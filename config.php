<?php
$pluginName = "fpp-FSEQDistributor";

$uploadDir = "/home/fpp/media/upload/";
$sequencesDir = "/home/fpp/media/sequences/";
$outputDir = "/home/fpp/media/plugins/$pluginName/temp/";

// Create output directory if not exists
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// --- AJAX Endpoints ---
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Action: Process FSEQ and XLSX files
    if ($_POST['action'] == 'process') {
        $fseqPath = $sequencesDir . basename($_POST['fseq']);
        $xlsxPath = $uploadDir . basename($_POST['xlsx']);
        if (!file_exists($fseqPath)) { echo json_encode(['success' => false, 'message' => "FSEQ file not found: " . basename($fseqPath)]); exit; }
        if (!file_exists($xlsxPath)) { echo json_encode(['success' => false, 'message' => "XLSX file not found: " . basename($xlsxPath)]); exit; }
        $command = "python3 /home/fpp/media/plugins/$pluginName/parse_fseq.py " . escapeshellarg($fseqPath) . " " . escapeshellarg($xlsxPath) . " " . escapeshellarg($outputDir) . " 2>&1";
        $output = shell_exec($command);
        $success = !(strpos($output, 'Error') !== false || strpos($output, 'Traceback') !== false);
        echo json_encode(['success' => $success, 'message' => $success ? 'Processing completed successfully!' : 'Error occurred during processing', 'output' => $output, 'fseq' => basename($fseqPath), 'xlsx' => basename($xlsxPath)]);
        exit;
    }

    // Action: Get connected systems WITH LIVE STATUS
    if ($_POST['action'] == 'get_systems_with_status') {
        $multiSyncUrl = 'http://localhost/api/fppd/multiSyncSystems';
        $json_data = @file_get_contents($multiSyncUrl);
        if ($json_data === FALSE) { echo json_encode(['success' => false, 'message' => "Could not fetch systems list from FPP API."]); exit; }
        $data = json_decode($json_data, true);
        if ($data === NULL || !isset($data['systems'])) { echo json_encode(['success' => false, 'message' => "Error decoding systems list."]); exit; }

        $systems = $data['systems'];
        $ipsToProbe = [];
        foreach ($systems as $system) {
            $typeId = intval($system['typeId'] ?? 0);
            if (($typeId >= 1 && $typeId < 128) || $typeId == 194 || $typeId == 195) {
                if (!empty($system['address'])) { $ipsToProbe[] = $system['address']; }
            }
        }
        
        $liveStatuses = [];
        if (!empty($ipsToProbe)) {
            $statusUrl = 'http://localhost/api/system/status?type=FPP';
            foreach ($ipsToProbe as $ip) { $statusUrl .= '&ip[]=' . urlencode($ip); }
            $status_json = @file_get_contents($statusUrl);
            if ($status_json !== FALSE) { $liveStatuses = json_decode($status_json, true); }
        }

        foreach ($systems as $i => $system) {
            $ip = $system['address'];
            if (isset($liveStatuses[$ip]) && is_array($liveStatuses[$ip])) {
                $systems[$i]['live_status'] = $liveStatuses[$ip]['status_name'] ?? 'unknown';
            } else {
                 $systems[$i]['live_status'] = 'offline';
            }
        }
        echo json_encode(['success' => true, 'systems' => $systems]);
        exit;
    }
}

// --- HTML part starts here ---
$xlsxFiles = glob($uploadDir . "*.xlsx");
$fseqFiles = glob($sequencesDir . "*.fseq");
?>
<!DOCTYPE html>
<html>
<head>
    <title>FSEQ Distributor</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h3 { margin-bottom: 15px; color: #333; font-size: 18px; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .alert { padding: 15px; border-radius: 5px; margin: 15px 0; }
        .alert.info { background: #e7f3ff; border-left: 4px solid #2196F3; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; word-wrap: break-word;}
        details { margin-top: 10px; cursor: pointer; }
        summary { font-weight: 600; padding: 5px; user-select: none; }

        /* --- NOVÉ ŠTÝLY PRE LAYOUT --- */
        .cards-wrapper { display: flex; align-items: flex-start; gap: 20px; }
        #process-card { flex: 2; min-width: 350px; }
        #systems-card { flex: 1; min-width: 250px; }
        @media (max-width: 768px) {
            .cards-wrapper { flex-direction: column; }
        }

        /* Štýly pre zoznam zariadení */
        #systemsList ul { list-style-type: none; }
        #systemsList li { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #eee; }
        #systemsList li:last-child { border-bottom: none; }
        #systemsList .status { font-weight: bold; padding: 3px 8px; border-radius: 12px; color: white; font-size: 0.8em; min-width: 65px; text-align: center; }
        #systemsList .status-online { background-color: #4CAF50; }
        #systemsList .status-offline { background-color: #f44336; }
        #systemsList .hostname { font-weight: 600; }
        #systemsList .ip { color: #777; font-size: 0.9em; }

        /* Zvyšok CSS pre formulár (nezmenené) */
        .form-group { margin: 15px 0; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #555; }
        select { width: 100%; padding: 12px; font-size: 14px; border: 2px solid #ddd; border-radius: 5px; }
        .btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 30px; border: none; cursor: pointer; font-size: 16px; font-weight: 600; border-radius: 5px; width: 100%; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🎄 FSEQ Distributor</h1>
        <p>Distribute FSEQ files to ESPixelStick Props</p>
    </div>
    
    <div id="resultMessage"></div>

    <div class="cards-wrapper">
        <div class="card" id="process-card">
            <h3>📤 Process and Upload</h3>
            <div class="alert info"><strong>ℹ️ Instructions:</strong><br>1. Upload XLSX to <code>~/media/upload/</code><br>2. FSEQ files in <code>~/media/sequences/</code><br>3. Select files and process</div>
            <form id="uploadForm" onsubmit="return false;">
                <div class="form-group"><label>📊 XLSX File (Prop Connections):</label><select name="xlsx" id="xlsxSelect" required><option value="">-- Select file --</option><?php foreach ($xlsxFiles as $file):?><option value="<?php echo htmlspecialchars(basename($file)); ?>"><?php echo htmlspecialchars(basename($file)); ?></option><?php endforeach; if (empty($xlsxFiles)): ?><option value="" disabled>⚠️ No XLSX files found</option><?php endif; ?></select></div>
                <div class="form-group"><label>🎵 FSEQ File:</label><select name="fseq" id="fseqSelect" required><option value="">-- Select file --</option><?php foreach ($fseqFiles as $file):?><option value="<?php echo htmlspecialchars(basename($file)); ?>"><?php echo htmlspecialchars(basename($file)); ?></option><?php endforeach; if (empty($fseqFiles)): ?><option value="" disabled>⚠️ No FSEQ files found</option><?php endif; ?></select></div>
                <button type="button" class="btn" id="submitBtn" onclick="processFiles()">🚀 Upload Show</button>
            </form>
        </div>

        <div class="card" id="systems-card">
            <h3>📡 Connected Systems</h3>
            <div id="systemsList">
                <p>Loading systems...</p>
            </div>
        </div>
    </div>
</div>

<script>
const apiUrl = window.location.href + '&nopage=1';

function processFiles() {
    const btn = document.getElementById('submitBtn'); const xlsx = document.getElementById('xlsxSelect').value; const fseq = document.getElementById('fseqSelect').value; const resultDiv = document.getElementById('resultMessage'); if (!xlsx || !fseq) { resultDiv.innerHTML = '<div class="alert error">❌ Please select both XLSX and FSEQ files</div>'; return; } btn.disabled = true; btn.innerHTML = '⏳ Processing...'; resultDiv.innerHTML = ''; const formData = new FormData(); formData.append('action', 'process'); formData.append('xlsx', xlsx); formData.append('fseq', fseq); fetch(apiUrl, { method: 'POST', body: formData }).then(response => response.json()).then(data => { btn.disabled = false; btn.innerHTML = '🚀 Upload Show'; const alertClass = data.success ? 'success' : 'error'; const icon = data.success ? '✅' : '❌'; let html = `<div class="alert ${alertClass}"><strong>${icon} ${data.message}</strong><br><small>FSEQ: ${data.fseq||''}</small><br><small>XLSX: ${data.xlsx||''}</small>`; if (data.output) { html += `<details><summary>Show output details</summary><pre>${escapeHtml(data.output)}</pre></details>`; } html += '</div>'; resultDiv.innerHTML = html; }).catch(error => { btn.disabled = false; btn.innerHTML = '🚀 Upload Show'; resultDiv.innerHTML = `<div class="alert error">❌ Error: ${error.message}</div>`; });
}

function fetchSystems() {
    const systemsDiv = document.getElementById('systemsList');
    const formData = new FormData();
    formData.append('action', 'get_systems_with_status');

    fetch(apiUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success || !data.systems) {
            systemsDiv.innerHTML = '<p>No systems found or error fetching data.</p>';
            return;
        }

        let html = '<ul>';
        let foundSystems = 0;
        data.systems.forEach(system => {
            if (system.local) return; // <-- IGNOROVANIE LOKÁLNEHO ZARIADENIA
            
            foundSystems++;
            const status = system.live_status || 'offline';
            let statusClass = 'status-offline';
            let statusText = status.toUpperCase();

            if (statusText === 'IDLE') { // <-- ZMENA IDLE NA ONLINE
                statusText = 'ONLINE';
            }
            if (status !== 'offline' && status !== 'unreachable') {
                statusClass = 'status-online';
            }
            if (status === 'unreachable') {
                statusText = 'OFFLINE';
            }

            html += `
                <li>
                    <div>
                        <span class="hostname">${escapeHtml(system.hostname)}</span><br>
                        <span class="ip">${escapeHtml(system.address)}</span>
                    </div>
                    <span class="status ${statusClass}">${escapeHtml(statusText)}</span>
                </li>`;
        });
        html += '</ul>';

        if (foundSystems === 0) {
            systemsDiv.innerHTML = '<p>No remote systems found.</p>';
        } else {
            systemsDiv.innerHTML = html;
        }
    })
    .catch(error => {
        systemsDiv.innerHTML = `<p style="color: red;">Error: ${error.message}</p>`;
    });
}

function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    if (text === undefined || text === null) return '';
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}

document.addEventListener('DOMContentLoaded', function() {
    fetchSystems(); 
    setInterval(fetchSystems, 5000);
});
</script>

</body>
</html>
