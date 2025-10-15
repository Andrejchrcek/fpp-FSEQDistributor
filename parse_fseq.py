import struct
import sys
import os
import openpyxl
import re
import socket
import requests
import json
from datetime import datetime
# POTREBNÉ IMPORTY PRE PROGRESS TRACKING
from requests_toolbelt.multipart.encoder import MultipartEncoder
from requests_toolbelt.multipart.encoder import MultipartEncoderMonitor 

status_file = "/home/fpp/media/plugins/fpp-FSEQDistributor/temp/status.json"
job_status_file_path = None # Globálna premenná pre cestu k Job Status súboru

def update_status(props):
    """Update status.json with current prop states"""
    with open(status_file, 'w') as f:
        json.dump(props, f)

def update_job_status(status_data):
    """Aktualizuje JSON súbor so stavom prebiehajúcej úlohy."""
    global job_status_file_path
    if job_status_file_path:
        try:
            status_data['timestamp'] = datetime.now().isoformat()
            # Zápis statusu do TEMP adresára
            with open(job_status_file_path, 'w') as f:
                json.dump(status_data, f, indent=4)
        except Exception as e:
            print(f"Error updating job status file: {e}")

def parse_xlsx(file_path):
    wb = openpyxl.load_workbook(file_path, read_only=True)
    sheet = wb.active  # Predpokladáme, že dáta sú v prvom hárku
    controllers = {}
    current_controller = None
    current_ip = None

    for row in sheet.iter_rows(values_only=True):
        line = ','.join(str(cell) for cell in row if cell is not None).strip()
        if not line:
            continue

        controller_match = re.match(r'^(.*?) (DDP|ESPixelStick.*) (.*)$', line)
        if controller_match:
            current_controller = controller_match.group(1).strip()
            current_ip = controller_match.group(3).strip()
            controllers[current_controller] = {'ip': current_ip, 'ranges': []}
            continue

        if 'Output' in line or 'Model' in line:
            continue

        port_match = re.match(r'^Pixel Port \d+\(SC:(\d+)\)\(.*?\)\(CHANS:(\d+)\)\(.*?\),', line)
        if port_match and current_controller:
            start_chan = int(port_match.group(1))
            num_chans = int(port_match.group(2))
            controllers[current_controller]['ranges'].append((start_chan, num_chans))

    controllers = {k: v for k, v in controllers.items() if v['ranges']}
    wb.close()
    return controllers

def read_fseq_header(f):
    f.seek(0)
    header = f.read(32)
    if len(header) < 32:
        raise ValueError("Invalid FSEQ file: too short")
    
    magic, = struct.unpack('<4s', header[0:4])
    if magic not in (b'PSEQ', b'FSEQ'):
        raise ValueError("Invalid magic: not a FSEQ file")
    
    data_offset, = struct.unpack('<H', header[4:6])
    minor_ver, = struct.unpack('<B', header[6:7])
    major_ver, = struct.unpack('<B', header[7:8])
    if major_ver != 2:
        raise ValueError("Only FSEQ v2 supported")
    
    var_offset, = struct.unpack('<H', header[8:10])
    channel_count, = struct.unpack('<I', header[10:14])
    frame_count, = struct.unpack('<I', header[14:18])
    step_time, = struct.unpack('<B', header[18:19])
    flags, = struct.unpack('<B', header[19:20])
    
    comp_byte, = struct.unpack('<B', header[20:21])
    comp_type = comp_byte & 0x0F
    ext_comp_block_count = (comp_byte >> 4) & 0x0F
    comp_block_count, = struct.unpack('<B', header[21:22])
    sparse_range_count, = struct.unpack('<B', header[22:23])
    
    full_comp_block_count = (ext_comp_block_count << 8) | comp_block_count
    
    if comp_type != 0:
        raise ValueError("Compressed FSEQ not supported; decompress first")
    if sparse_range_count != 0:
        print("Warning: Input has sparse ranges; assuming full data")
    
    variables = {}
    f.seek(32)
    while f.tell() < data_offset:
        var_size, = struct.unpack('<H', f.read(2))
        if var_size < 4:
            break
        code = f.read(2).decode('ascii', errors='ignore')
        data = f.read(var_size - 4).decode('utf-8', errors='ignore').rstrip('\x00')
        variables[code] = data
    
    return {
        'data_offset': data_offset,
        'channel_count': channel_count,
        'frame_count': frame_count,
        'step_time': step_time,
        'unique_id': struct.unpack('<Q', header[24:32])[0],
        'variables': variables,
        'minor_ver': minor_ver,
    }

def extract_data_for_ranges(f, header, ranges):
    frame_size = header['channel_count']
    extracted = bytearray()
    
    f.seek(header['data_offset'])
    
    for _ in range(header['frame_count']):
        frame_data = f.read(frame_size)
        if len(frame_data) < frame_size:
            raise ValueError("Incomplete frame data")
        for start_1, num_chans in ranges:
            start_0 = start_1 - 1
            extracted.extend(frame_data[start_0 : start_0 + num_chans])
    
    return extracted

def write_sparse_fseq(output_path, header, extracted_data, ranges):
    sparse_range_count = len(ranges)
    new_channel_count = sum(num_chans for _, num_chans in ranges)
    comp_type = 0
    comp_block_count = 0
    ext_comp_block_count = 0
    
    var_data = bytearray()
    for code, data in header['variables'].items():
        var_size = 4 + len(data) + 1
        var_data.extend(struct.pack('<H', var_size))
        var_data.extend(code.encode('ascii'))
        var_data.extend(data.encode('utf-8'))
        var_data.extend(b'\x00')
    
    var_len = len(var_data)
    pad_var = (4 - (var_len % 4)) % 4
    var_data.extend(b'\x00' * pad_var)
    var_len += pad_var
    
    sparse_data = bytearray()
    for start_1, num_chans in ranges:
        start_0 = start_1 - 1
        end_offset = num_chans - 1
        sparse_data.extend(start_0.to_bytes(3, 'little'))
        sparse_data.extend(end_offset.to_bytes(3, 'little'))
    
    comp_data = bytearray()
    
    sparse_size = 6 * sparse_range_count
    var_start = 32 + len(comp_data) + sparse_size
    
    pre_data_size = var_start + len(var_data) - pad_var
    data_offset = ((pre_data_size + 3) // 4) * 4
    
    header_bytes = bytearray()
    header_bytes.extend(b'PSEQ')
    header_bytes.extend(struct.pack('<H', data_offset))
    header_bytes.extend(struct.pack('<B', header['minor_ver']))
    header_bytes.extend(struct.pack('<B', 2))
    header_bytes.extend(struct.pack('<H', var_start))
    header_bytes.extend(struct.pack('<I', new_channel_count))
    header_bytes.extend(struct.pack('<I', header['frame_count']))
    header_bytes.extend(struct.pack('<B', header['step_time']))
    header_bytes.extend(struct.pack('<B', 0))
    comp_byte = (ext_comp_block_count << 4) | comp_type
    header_bytes.extend(struct.pack('<B', comp_byte))
    header_bytes.extend(struct.pack('<B', comp_block_count))
    header_bytes.extend(struct.pack('<B', sparse_range_count))
    header_bytes.extend(struct.pack('<B', 0))
    header_bytes.extend(struct.pack('<Q', header['unique_id']))
    
    with open(output_path, 'wb') as f:
        f.write(header_bytes)
        f.write(comp_data)
        f.write(sparse_data)
        f.write(var_data[:-pad_var] if pad_var > 0 else var_data) 
        current_pos = f.tell()
        pad_needed = data_offset - current_pos
        f.write(b'\x00' * pad_needed)
        f.write(extracted_data)

def is_device_online(ip, port=80, timeout=3):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(timeout)
        sock.connect((ip, port))
        sock.close()
        return True
    except:
        return False

# OPRAVENÁ FUNKCIA NA NAHRÁVANIE S MONITOROM (kompatibilná so staršou verziou)
def upload_fseq_via_http(ip, fseq_path, upload_filename, ctrl_name, total_uploads, current_upload_index):
    try:
        url = f'http://{ip}/upload'
        headers = {
            'accept': 'application/json',
            'x-requested-with': 'XMLHttpRequest',
            'cookie': 'advancedMode=true'
        }
        
        file_size = os.path.getsize(fseq_path)
        
        # Funkcia, ktorá sa volá pri odosielaní každého bloku dát
        def progress_callback(monitor):
            if monitor.len:
                local_progress = monitor.bytes_read / monitor.len
                
                # Výpočet globálneho progressu v rozsahu 50% až 99%
                chunk_progress = 49 / total_uploads
                
                global_progress_base = 50 + (current_upload_index - 1) * chunk_progress
                global_progress = global_progress_base + (local_progress * chunk_progress)

                global_progress = min(global_progress, 99) 
                
                update_job_status({
                    "status": "uploading",
                    "progress": int(global_progress),
                    "message": f"Uploading to: {ctrl_name} ({ip}). Sent: {int(monitor.bytes_read / 1024)}KB / {int(file_size / 1024)}KB",
                    "total_controllers": total_uploads,
                    "current_controller": current_upload_index
                })
        
        # 1. Vytvorenie základného MultipartEncoder
        m = MultipartEncoder(
            fields={'file': (upload_filename, open(fseq_path, 'rb'), 'application/octet-stream')}
        )

        # 2. Zabaliť ho do monitoru (použitie staršej metódy bez 'callback' argumentu)
        monitor = MultipartEncoderMonitor(m, progress_callback) 

        print(f"Uploading {upload_filename} from {fseq_path} to {url}...")
        
        # 3. Odoslanie dát. Dáta sú monitor a content_type sa berie z monitora.
        response = requests.post(
            url, 
            data=monitor, 
            headers={'Content-Type': monitor.content_type, **headers}, 
            timeout=300
        )
        response.raise_for_status()
        print(f"Successfully uploaded {upload_filename} to {ip} (Status: {response.status_code})")
        
        return True
    except Exception as e:
        print(f"HTTP upload failed for {ip}: {e}")
        return False


def process_upload(input_fseq, input_xlsx, output_dir, job_id, target_controller=None):
    
    global job_status_file_path
    job_status_file_path = os.path.join(os.path.dirname(output_dir), f"fseq_job_{job_id}.json")
    
    update_job_status({
        "status": "initializing",
        "progress": 0,
        "message": "Starting process...",
    })

    try:
        os.makedirs(output_dir, exist_ok=True)
        controllers = parse_xlsx(input_xlsx)
        
        if target_controller:
            controllers_to_process = {target_controller: controllers.get(target_controller)}
            if controllers_to_process.get(target_controller) is None:
                raise ValueError(f"Controller '{target_controller}' not found in {input_xlsx}")
        else:
            controllers_to_process = controllers
            
        upload_list = {}
        total_fseq_controllers = len([c for c in controllers_to_process.values() if c is not None and c.get('ranges')])
        processed_count = 0
        
        # FÁZA 1: Vytvorenie odľahčených FSEQ súborov (0% - 50%)
        update_job_status({
            "status": "processing",
            "progress": 5,
            "message": f"Creating {total_fseq_controllers} sparse FSEQ files...",
            "total_controllers": total_fseq_controllers,
            "current_controller": 0
        })

        with open(input_fseq, 'rb') as f:
            header = read_fseq_header(f)
            
            for ctrl_name, info in controllers_to_process.items():
                if info is None or 'ranges' not in info or not info['ranges']:
                    continue
                
                processed_count += 1
                progress = int((processed_count / total_fseq_controllers) * 45) + 5 # 5% až 50%
                
                update_job_status({
                    "status": "processing",
                    "progress": progress,
                    "message": f"Processing file for: {ctrl_name} ({processed_count}/{total_fseq_controllers})",
                    "total_controllers": total_fseq_controllers,
                    "current_controller": processed_count
                })
                
                f.seek(0)
                ranges = info['ranges']
                extracted = extract_data_for_ranges(f, header, ranges)
                output_path = os.path.join(output_dir, f"{ctrl_name}.fseq")
                write_sparse_fseq(output_path, header, extracted, ranges)
                upload_list[ctrl_name] = {'ip': info['ip'], 'path': output_path}

        upload_controllers = {k: v for k, v in upload_list.items() 
                              if 'ESPixelStick' in v.get('ip', '')}
        total_upload_controllers = len(upload_controllers)
        
        # Status po vytvorení FSEQ súborov (Presne 50%)
        update_job_status({
            "status": "processing",
            "progress": 50,
            "message": f"Finished FSEQ generation. Starting upload to {total_upload_controllers} devices...",
            "total_controllers": total_upload_controllers,
            "current_controller": 0
        })
        
        # FÁZA 2: Nahrávanie (50% - 100%)
        processed_upload_count = 0
        original_fseq_name = os.path.basename(input_fseq)
        
        for ctrl_name, info in upload_controllers.items():
            ip_full = info['ip']
            output_path = info['path']
            ip = ip_full.split()[0]
                
            processed_upload_count += 1
            
            if is_device_online(ip):
                
                # Nastavenie progressu na začiatku bloku pred nahrávaním
                progress_start = 50 + int((processed_upload_count - 1) / total_upload_controllers * 49) 
                update_job_status({
                    "status": "uploading",
                    "progress": progress_start,
                    "message": f"Starting upload to: {ctrl_name} ({ip})...",
                    "total_controllers": total_upload_controllers,
                    "current_controller": processed_upload_count
                })

                if upload_fseq_via_http(
                    ip, 
                    output_path, 
                    original_fseq_name,
                    ctrl_name, 
                    total_upload_controllers, 
                    processed_upload_count
                ):
                    print(f"Successfully uploaded to {ctrl_name}")
                else:
                    print(f"Upload failed for {ctrl_name}")
                    # Ak upload zlyhal, zmena statusu na koniec bloku s chybou
                    progress_end = 50 + int(processed_upload_count / total_upload_controllers * 49)
                    update_job_status({
                        "status": "uploading",
                        "progress": progress_end,
                        "message": f"Upload FAILED for: {ctrl_name} ({ip}).",
                        "total_controllers": total_upload_controllers,
                        "current_controller": processed_upload_count
                    })
            else:
                print(f"{ctrl_name} at {ip} is not online or unreachable")
                # Ak je zariadenie offline, posuň progress na koniec bloku
                progress_end = 50 + int(processed_upload_count / total_upload_controllers * 49)
                update_job_status({
                    "status": "uploading",
                    "progress": progress_end,
                    "message": f"Device OFFLINE: {ctrl_name} ({ip}). Skipping.",
                    "total_controllers": total_upload_controllers,
                    "current_controller": processed_upload_count
                })

        # 3. Záverečný status
        update_job_status({
            "status": "complete",
            "progress": 100,
            "message": "All operations completed successfully.",
            "total_controllers": total_fseq_controllers,
            "current_controller": total_fseq_controllers
        })
        
    except ValueError as e:
        print(f"Error: {e}")
        update_job_status({
            "status": "error",
            "progress": 99,
            "message": str(e),
        })
        sys.exit(1)
        
    except Exception as e:
        print(f"An unexpected error occurred: {e}")
        update_job_status({
            "status": "error",
            "progress": 99,
            "message": f"Unexpected error: {e}",
        })
        sys.exit(1)


if __name__ == "__main__":
    if len(sys.argv) not in (5, 6):
        print("Usage: python create_sparse_fseq.py input.fseq input.xlsx output_dir JobID [target_controller_name]")
        sys.exit(1)
    
    input_fseq = sys.argv[1]
    input_xlsx = sys.argv[2]
    output_dir = sys.argv[3]
    job_id = sys.argv[4]
    
    target_controller = None
    if len(sys.argv) == 6:
        target_controller = sys.argv[5]

    process_upload(input_fseq, input_xlsx, output_dir, job_id, target_controller)
