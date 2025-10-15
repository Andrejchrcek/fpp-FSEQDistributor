<?php
// FPP Plugin - FSEQ Distributor
// This block must be at the very top. It handles AJAX requests before any HTML is sent.
if (isset($_GET['nopage']) && $_GET['nopage'] == '1' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $pluginName = "fpp-FSEQDistributor";
    $uploadDir = "/home/fpp/media/upload/";
    $sequencesDir = "/home/fpp/media/sequences/";
    $outputDir = "/home/fpp/media/plugins/$pluginName/temp/";

    if ($_POST['action'] == 'process') {
        if (!is_dir($outputDir)) { mkdir($outputDir, 0777, true); }
        $fseqPath = $sequencesDir . basename($_POST['fseq']);
        $xlsxPath = $uploadDir . basename($_POST['xlsx']);
        if (!file_exists($fseqPath)) { echo json_encode(['success' => false, 'message' => "FSEQ file not found: " . basename($fseqPath)]); exit; }
        if (!file_exists($xlsxPath)) { echo json_encode(['success' => false, 'message' => "XLSX file not found: " . basename($xlsxPath)]); exit; }
        
        // --- ZMENA: Skladanie príkazu na základe prítomnosti kontroléra ---
        $command = "python3 /home/fpp/media/plugins/$pluginName/parse_fseq.py " . escapeshellarg($fseqPath) . " " . escapeshellarg($xlsxPath) . " " . escapeshellarg($outputDir);
        
        $controllerName = null;
        if (isset($_POST['controller']) && !empty($_POST['controller'])) {
            $controllerName = basename($_POST['controller']);
            $command .= " " . escapeshellarg($controllerName);
        }
        // --- Koniec zmeny ---

        $output = shell_exec($command . " 2>&1");
        $success = !(strpos($output, 'Error') !== false || strpos($output, 'Traceback') !== false);

        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Processing completed successfully!' : 'Error occurred during processing',
            'output' => $output,
            'fseq' => basename($fseqPath),
            'xlsx' => basename($xlsxPath),
            'controller' => $controllerName ?? 'All' // Posielame späť, čo sa spracovalo
        ]);
        exit;
    }

    if ($_POST['action'] == 'get_systems_with_status') {
        $multiSyncUrl = 'http://localhost/api/fppd/multiSyncSystems';
        $json_data = @file_get_contents($multiSyncUrl);
        if ($json_data === FALSE) { echo json_encode(['success' => false, 'message' => "Could not fetch systems list."]); exit; }
        $data = json_decode($json_data, true);
        if ($data === NULL || !isset($data['systems'])) { echo json_encode(['success' => false, 'message' => "Error decoding systems list."]); exit; }
        
        $systems = $data['systems'];
        $ipsToProbe = [];
        foreach ($systems as $system) { if (!empty($system['address'])) { $ipsToProbe[] = $system['address']; } }
        
        $liveStatuses = [];
        if (!empty($ipsToProbe)) {
            $statusUrl = 'http://localhost/api/system/status?type=FPP';
            foreach ($ipsToProbe as $ip) { $statusUrl .= '&ip[]=' . urlencode($ip); }
            $status_json = @file_get_contents($statusUrl);
            if ($status_json !== FALSE) { $liveStatuses = json_decode($status_json, true); }
        }

        foreach ($systems as $i => $system) {
            $ip = $system['address'];
            $systems[$i]['live_status'] = (isset($liveStatuses[$ip]) && is_array($liveStatuses[$ip])) ? ($liveStatuses[$ip]['status_name'] ?? 'unknown') : 'offline';
        }
        echo json_encode(['success' => true, 'systems' => $systems]);
        exit;
    }
}

// --- FPP Plugin Page Content Starts Here ---
$xlsxFiles = glob("/home/fpp/media/upload/*.xlsx");
$fseqFiles = glob("/home/fpp/media/sequences/*.fseq");
?>

<style>
    /* Custom styles for this plugin's layout */
    .plugin-wrapper { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
    .plugin-main-content { flex: 3; min-width: 350px; }
    .plugin-sidebar { flex: 2; min-width: 300px; }
    #systemsList ul { list-style-type: none; padding-left: 0; margin: 0; }
    #systemsList li { display: flex; justify-content: space-between; align-items: center; padding: 10px 5px; border-bottom: 1px solid #eee; }
    #systemsList li:last-child { border-bottom: none; }
    #systemsList .status { font-weight: bold; padding: 3px 10px; border-radius: 12px; color: white; font-size: 0.8em; min-width: 70px; text-align: center; }
    #systemsList .status-online { background-color: #28a745; }
    #systemsList .status-offline { background-color: #dc3545; }
    #systemsList .hostname { font-weight: 600; }
    #systemsList .ip { color: #6c757d; font-size: 0.9em; }
    /* --- ZMENA: Štýly pre novú skupinu tlačidiel --- */
    #systemsList .system-actions { display: flex; align-items: center; gap: 8px; }
    #systemsList .buttons { padding: 4px 10px; font-size: 0.85rem; }
</style>

<div id="resultMessage" class="fpp-alert-container"></div>

<div class="plugin-wrapper">
    
    <div class="plugin-main-content">
        <div id="fseq-distributor" class="settingsGroup">
            <legend>📤 Process and Upload</legend>
            <div class="alert alert-info">
                <strong>Instructions:</strong>
                <ol style="margin: 5px 0 0 20px; padding: 0;">
                    <li>Upload Prop XLSX file to File Manager (Uploads tab).</li>
                    <li>Ensure FSEQ sequence files are in your sequences folder.</li>
                    <li>Select files and use the buttons to upload.</li>
                </ol>
            </div>
            <div class="settingsSetting"><label>📊 Prop Connections (XLSX):</label><div class="setting">
                <select name="xlsx" id="xlsxSelect" class="form-select" required>
                    <option value="">-- Select XLSX file --</option>
                    <?php foreach ($xlsxFiles as $file):?><option value="<?php echo htmlspecialchars(basename($file)); ?>"><?php echo htmlspecialchars(basename($file)); ?></option><?php endforeach; ?>
                    <?php if (empty($xlsxFiles)): ?><option value="" disabled>⚠️ No XLSX files found in Uploads</option><?php endif; ?>
                </select>
            </div></div>
            <div class="settingsSetting"><label>🎵 Sequence (FSEQ):</label><div class="setting">
                <select name="fseq" id="fseqSelect" class="form-select" required>
                    <option value="">-- Select FSEQ file --</option>
                    <?php foreach ($fseqFiles as $file):?><option value="<?php echo htmlspecialchars(basename($file)); ?>"><?php echo htmlspecialchars(basename($file)); ?></option><?php endforeach; ?>
                    <?php if (empty($fseqFiles)): ?><option value="" disabled>⚠️ No FSEQ files found</option><?php endif; ?>
                </select>
            </div></div>
             <div class="settingsSetting"><div class="setting">
                <button type="button" class="buttons" id="submitBtn" onclick="processFiles(this)">🚀 Upload All</button>
             </div></div>
        </div>
    </div>

    <div class="plugin-sidebar">
        <div id="connected-systems" class="settingsGroup">
            <legend>📡 Connected Systems</legend>
            <div id="systemsList">
                <p>Loading systems...</p>
            </div>
        </div>
    </div>
</div>

<script>
const currentUrl = new URL(window.location.href);
const apiUrl = `${currentUrl.pathname}${currentUrl.search}&nopage=1`;

// --- ZMENA: Funkcia teraz prijíma samotné tlačidlo a voliteľný názov kontroléra ---
function processFiles(button, controllerName = null) {
    const originalButtonText = button.innerHTML;
    const xlsx = document.getElementById('xlsxSelect').value;
    const fseq = document.getElementById('fseqSelect').value;
    const resultDiv = document.getElementById('resultMessage');

    if (!xlsx || !fseq) {
        resultDiv.innerHTML = '<div class="alert alert-danger">❌ Please select both XLSX and FSEQ files</div>';
        return;
    }
    
    button.disabled = true;
    button.innerHTML = '⏳...';
    resultDiv.innerHTML = '';
    
    const formData = new FormData();
    formData.append('action', 'process');
    formData.append('xlsx', xlsx);
    formData.append('fseq', fseq);
    
    if (controllerName) {
        formData.append('controller', controllerName);
    }

    fetch(apiUrl, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        const alertClass = data.success ? 'alert-success' : 'alert-danger';
        const icon = data.success ? '✅' : '❌';
        let target = data.controller || 'All';
        
        let html = `<div class="alert ${alertClass}">
            <strong>${icon} ${data.message}</strong><br>
            <small>Target: ${escapeHtml(target)}</small><br>
            <small>FSEQ: ${data.fseq||''}</small><br>
            <small>XLSX: ${data.xlsx||''}</small>`;
        if (data.output) {
            html += `<details><summary>Show output details</summary><pre class="log pre-scrollable">${escapeHtml(data.output)}</pre></details>`;
        }
        html += '</div>';
        resultDiv.innerHTML = html;
        window.scrollTo(0, 0);
    })
    .catch(error => {
        resultDiv.innerHTML = `<div class="alert alert-danger">❌ Error: ${error.message}</div>`;
        window.scrollTo(0, 0);
    })
    .finally(() => {
        button.disabled = false;
        button.innerHTML = originalButtonText;
    });
}

function fetchSystems() {
    const systemsDiv = document.getElementById('systemsList');
    const formData = new FormData();
    formData.append('action', 'get_systems_with_status');

    fetch(apiUrl, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (!data.success || !data.systems) { systemsDiv.innerHTML = '<p>No systems found or error fetching data.</p>'; return; }
        
        let html = '<ul>';
        let foundSystems = 0;
        data.systems.forEach(system => {
            if (system.local) return;
            
            foundSystems++;
            const status = system.live_status || 'offline';
            let statusClass = (status !== 'offline' && status !== 'unreachable') ? 'status-online' : 'status-offline';
            let statusText = (status === 'idle') ? 'ONLINE' : status.toUpperCase();
            if (status === 'unreachable') statusText = 'OFFLINE';

            // --- ZMENA: Pridanie tlačidla "Upload" ku každému zariadeniu ---
            const escapedHostname = escapeHtml(system.hostname);
            html += `<li>
                        <div>
                            <span class="hostname">${escapedHostname}</span><br>
                            <span class="ip">${escapeHtml(system.address)}</span>
                        </div>
                        <div class="system-actions">
                            <button class="buttons" onclick="processFiles(this, '${escapedHostname}')">Upload</button>
                            <span class="status ${statusClass}">${escapeHtml(statusText)}</span>
                        </div>
                    </li>`;
        });
        html += '</ul>';

        systemsDiv.innerHTML = (foundSystems === 0) ? '<p>No remote systems found.</p>' : html;
    })
    .catch(error => { systemsDiv.innerHTML = `<p class="text-danger">Error: ${error.message}</p>`; });
}

function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return text ? String(text).replace(/[&<>"']/g, m => map[m]) : '';
}

// Spustenie po načítaní stránky
fetchSystems(); 
setInterval(fetchSystems, 5000);
</script>
