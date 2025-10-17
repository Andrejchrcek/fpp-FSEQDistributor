<?php
// FPP Plugin - FSEQ Distributor
// PHP časť zostáva rovnaká, zmeny sú len v JavaScripte
if (isset($_GET['nopage']) && $_GET['nopage'] == '1' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $pluginName = "fpp-FSEQDistributor";
    $pythonScript = "/home/fpp/media/plugins/{$pluginName}/parse_fseq.py";
    $uploadDir = "/home/fpp/media/upload/";
    $sequencesDir = "/home/fpp/media/sequences/";
    $outputDir = "/home/fpp/media/plugins/{$pluginName}/temp/";

    if ($_POST['action'] == 'start_process') {
        if (!is_dir($outputDir)) { mkdir($outputDir, 0777, true); }
        $debugLogFile = "/tmp/fseq_debug.log";
        $fseqPath = $sequencesDir . basename($_POST['fseq']);
        $xlsxPath = $uploadDir . basename($_POST['xlsx']);
        if (!file_exists($fseqPath) || !file_exists($xlsxPath)) { echo json_encode(['success' => false, 'message' => 'Missing FSEQ or XLSX file.']); exit; }
        
        $jobs = [];
        $controllers = $_POST['controllers'] ?? [];

        if (!empty($controllers)) {
            foreach ($controllers as $controller) {
                $jobId = uniqid();
                $args = escapeshellarg($fseqPath) . " " . escapeshellarg($xlsxPath) . " " . escapeshellarg($outputDir) . " " . escapeshellarg($jobId) . " " . escapeshellarg($controller);
                $command = "python3 " . $pythonScript . " " . $args . " > {$debugLogFile} 2>&1 &";
                exec($command);
                $jobs[] = ['hostname' => $controller, 'jobId' => $jobId];
            }
        }
        echo json_encode(['success' => true, 'jobs' => $jobs]);
        exit;
    }

    if ($_POST['action'] == 'check_status') {
        $jobId = basename($_POST['jobId']);
        $statusFile = "/home/fpp/media/plugins/fpp-FSEQDistributor/temp/fseq_job_" . $jobId . ".json";
        if (file_exists($statusFile)) {
            echo file_get_contents($statusFile);
        } else {
            echo json_encode(['status' => 'waiting', 'progress' => 0, 'message' => 'Job is starting...']);
        }
        exit;
    }
    
    if ($_POST['action'] == 'get_systems_with_status') {
        $multiSyncUrl = 'http://localhost/api/fppd/multiSyncSystems'; $json_data = @file_get_contents($multiSyncUrl); if ($json_data === FALSE) { echo json_encode(['success' => false, 'message' => "Could not fetch systems list."]); exit; } $data = json_decode($json_data, true); if ($data === NULL || !isset($data['systems'])) { echo json_encode(['success' => false, 'message' => "Error decoding systems list."]); exit; } $systems = $data['systems']; $ipsToProbe = []; foreach ($systems as $system) { if (!empty($system['address'])) { $ipsToProbe[] = $system['address']; } } $liveStatuses = []; if (!empty($ipsToProbe)) { $statusUrl = 'http://localhost/api/system/status?type=FPP'; foreach ($ipsToProbe as $ip) { $statusUrl .= '&ip[]=' . urlencode($ip); } $status_json = @file_get_contents($statusUrl); if ($status_json !== FALSE) { $liveStatuses = json_decode($status_json, true); } } foreach ($systems as $i => $system) { $ip = $system['address']; $systems[$i]['live_status'] = (isset($liveStatuses[$ip]) && is_array($liveStatuses[$ip])) ? ($liveStatuses[$ip]['status_name'] ?? 'unknown') : 'offline'; } echo json_encode(['success' => true, 'systems' => $systems]); exit;
    }
}

// --- HTML A JAVASCRIPT ---
$xlsxFiles = glob("/home/fpp/media/upload/*.xlsx");
$fseqFiles = glob("/home/fpp/media/sequences/*.fseq");
?>

<style>
    .plugin-wrapper { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
    .plugin-main-content { flex: 3; min-width: 350px; }
    .plugin-sidebar { flex: 2; min-width: 300px; }
    #systemsList ul { list-style-type: none; padding-left: 0; margin: 0; }
    #systemsList li { display: flex; justify-content: space-between; align-items: center; padding: 10px 5px; border-bottom: 1px solid #eee; min-height: 54px; }
    #systemsList li:last-child { border-bottom: none; }
    #systemsList .status { font-weight: bold; padding: 3px 10px; border-radius: 12px; color: white; font-size: 0.8em; min-width: 70px; text-align: center; }
    #systemsList .status-online { background-color: #28a745; }
    #systemsList .status-offline { background-color: #dc3545; }
    #systemsList .hostname { font-weight: 600; }
    #systemsList .ip { color: #6c757d; font-size: 0.9em; }
    #systemsList .system-actions { display: flex; align-items: center; gap: 8px; }
    #systemsList .buttons { padding: 4px 10px; font-size: 0.85rem; }
    .device-progress-container { width: 130px; display: none; }
    .device-progress-bar { background-color: #e9ecef; border-radius: .25rem; height: 1.2rem; }
    .device-progress-bar div { background-color: #0d6efd; height: 100%; width: 0%; border-radius: .25rem; text-align: center; color: white; font-size: 0.8rem; line-height: 1.2rem; transition: width 0.4s ease; }
    .device-progress-message { font-size: 0.8rem; font-weight: bold; color: #6c757d; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .progress-icon { margin-right: 4px; }
</style>

<div id="resultMessage" class="fpp-alert-container"></div>
<div class="plugin-wrapper">
    <div class="plugin-main-content">
        <div id="fseq-distributor" class="settingsGroup">
            <legend>📤 Process and Upload</legend>
            <div class="alert alert-info"><strong>Instructions:</strong><ol style="margin: 5px 0 0 20px; padding: 0;"><li>Upload Prop XLSX file to File Manager (Uploads tab).</li><li>Ensure FSEQ sequence files are in your sequences folder.</li><li>Select files and use the buttons to upload.</li></ol></div>
            <div class="settingsSetting"><label>📊 Prop Connections (XLSX):</label><div class="setting"><select name="xlsx" id="xlsxSelect" class="form-select" required><option value="">-- Select XLSX file --</option><?php foreach ($xlsxFiles as $file):?><option value="<?php echo htmlspecialchars(basename($file)); ?>"><?php echo htmlspecialchars(basename($file)); ?></option><?php endforeach; ?><?php if (empty($xlsxFiles)): ?><option value="" disabled>⚠️ No XLSX files found in Uploads</option><?php endif; ?></select></div></div>
            <div class="settingsSetting"><label>🎵 Sequence (FSEQ):</label><div class="setting"><select name="fseq" id="fseqSelect" class="form-select" required><option value="">-- Select FSEQ file --</option><?php foreach ($fseqFiles as $file):?><option value="<?php echo htmlspecialchars(basename($file)); ?>"><?php echo htmlspecialchars(basename($file)); ?></option><?php endforeach; ?><?php if (empty($fseqFiles)): ?><option value="" disabled>⚠️ No FSEQ files found</option><?php endif; ?></select></div></div>
            <div class="settingsSetting"><div class="setting"><button type="button" class="buttons" id="uploadAllBtn" onclick="startProcessing(this)">🚀 Upload All</button></div></div>
        </div>
    </div>
    <div class="plugin-sidebar">
        <div id="connected-systems" class="settingsGroup">
            <legend>📡 Connected Systems</legend>
            <div id="systemsList"><p>Loading systems...</p></div>
        </div>
    </div>
</div>

<script>
    const currentUrl = new URL(window.location.href);
    const apiUrl = `${currentUrl.pathname}${currentUrl.search}&nopage=1`;
    let activeJobs = {}; 

    function setAllButtonsDisabled(disabled) {
        document.querySelectorAll('#uploadAllBtn, #systemsList button').forEach(b => b.disabled = disabled);
    }

    function clearAllProgressBars() {
        document.querySelectorAll('#systemsList li[data-hostname]').forEach(li => {
            li.querySelector('.device-progress-container').style.display = 'none';
            li.querySelector('.system-actions').style.display = 'flex';
        });
    }

    function startProcessing(button, controllerName = null) {
        if (Object.keys(activeJobs).length > 0) return;

        const xlsx = document.getElementById('xlsxSelect').value;
        const fseq = document.getElementById('fseqSelect').value;
        const resultDiv = document.getElementById('resultMessage');
        resultDiv.innerHTML = '';
        if (!xlsx || !fseq) {
            resultDiv.innerHTML = '<div class="alert alert-danger">❌ Please select both XLSX and FSEQ files</div>';
            return;
        }

        clearAllProgressBars();
        setAllButtonsDisabled(true);

        const formData = new FormData();
        formData.append('action', 'start_process');
        formData.append('xlsx', xlsx);
        formData.append('fseq', fseq);
        
        let targets = [];
        if (controllerName) {
            targets.push(controllerName);
            formData.append('controllers[]', controllerName);
        } else {
            document.querySelectorAll('#systemsList li[data-hostname]').forEach(li => {
                const hostname = li.dataset.hostname;
                targets.push(hostname);
                formData.append('controllers[]', hostname);
            });
        }
        
        activeJobs = {};
        targets.forEach(hostname => prepareDeviceUIForUpload(hostname, 'Waiting...'));

        fetch(apiUrl, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.jobs && data.jobs.length > 0) {
                    data.jobs.forEach(job => {
                        activeJobs[job.jobId] = { 
                            hostname: job.hostname, 
                            interval: null, 
                            lastUpdate: Date.now() // Zaznamenaj čas štartu
                        };
                        pollStatus(job.jobId, job.hostname);
                    });
                } else {
                    targets.forEach(hostname => finishDeviceUI(hostname, 'Job failed to start', true));
                    setAllButtonsDisabled(false);
                }
            }).catch(error => {
                targets.forEach(hostname => finishDeviceUI(hostname, `Start error: ${error.message}`, true));
                setAllButtonsDisabled(false);
            });
    }

    function pollStatus(jobId, hostname) {
        const interval = setInterval(() => {
            // --- NOVÁ LOGIKA: Timeout ---
            const job = activeJobs[jobId];
            if (!job) { // Ak bol job zrušený, zastav interval
                clearInterval(interval);
                return;
            }
            if (Date.now() - job.lastUpdate > 90000) { // 90 sekúnd bez aktualizácie
                clearInterval(interval);
                delete activeJobs[jobId];
                if(Object.keys(activeJobs).length === 0) setAllButtonsDisabled(false);
                finishDeviceUI(hostname, 'Process timed out!', true);
                return;
            }
            // --- Koniec Timeout logiky ---

            const formData = new FormData();
            formData.append('action', 'check_status');
            formData.append('jobId', jobId);

            fetch(apiUrl, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                activeJobs[jobId].lastUpdate = Date.now(); // Aktualizuj čas poslednej odpovede
                const isError = data.status === 'error';
                updateDeviceProgress(hostname, data.progress || 0, data.message, isError);
                
                if (data.status === 'complete' || isError) {
                    clearInterval(interval);
                    delete activeJobs[jobId];
                    if(Object.keys(activeJobs).length === 0) {
                        setAllButtonsDisabled(false);
                    }
                    finishDeviceUI(hostname, data.message, isError);
                }
            });
        }, 2000); // Zvýšil som interval na 2s
        activeJobs[jobId].interval = interval;
    }
    
    function prepareDeviceUIForUpload(hostname, message) {
        const li = document.querySelector(`li[data-hostname="${hostname}"]`);
        if (!li) return;
        li.querySelector('.system-actions').style.display = 'none';
        li.querySelector('.device-progress-container').style.display = 'block';
        updateDeviceProgress(hostname, 0, message, false);
    }

    function updateDeviceProgress(hostname, progress, message, isError) {
        const li = document.querySelector(`li[data-hostname="${hostname}"]`);
        if (!li) return;
        const progressBar = li.querySelector('.device-progress-bar div');
        const progressMessage = li.querySelector('.device-progress-message');
        progress = Math.min(100, Math.max(0, progress));
        progressBar.style.width = `${progress}%`;
        progressBar.textContent = `${Math.round(progress)}%`;
        let cleanMessage = message.replace(`Uploading to: ${hostname}`, 'Uploading...').replace(`Processing file for: ${hostname}`, 'Processing...');
        progressMessage.innerHTML = cleanMessage;
        progressBar.style.backgroundColor = isError ? '#dc3545' : (progress >= 100 ? '#28a745' : '#0d6efd');
    }
    
    function finishDeviceUI(hostname, finalMessage, isError = false) {
        const li = document.querySelector(`li[data-hostname="${hostname}"]`);
        if (!li) return;
        const icon = isError ? '❌' : '✅';
        const finalStatusText = isError ? 'Failed!' : 'Done!';
        const finalProgress = isError ? (parseInt(li.querySelector('.device-progress-bar div').style.width) || 99) : 100;
        updateDeviceProgress(hostname, finalProgress, `<span class="progress-icon">${icon}</span> ${finalStatusText}`, isError);
    }

    function fetchSystems() {
        if (Object.keys(activeJobs).length > 0) return;
        const systemsDiv = document.getElementById('systemsList');
        const formData = new FormData();
        formData.append('action', 'get_systems_with_status');
        fetch(apiUrl, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.systems) { systemsDiv.innerHTML = '<p>No systems found.</p>'; return; }
            let html = '<ul>'; let foundSystems = 0;
            data.systems.forEach(system => {
                if (system.local) return;
                foundSystems++;
                const status = system.live_status || 'offline';
                let statusClass = (status !== 'offline' && status !== 'unreachable') ? 'status-online' : 'status-offline';
                let statusText = (status === 'idle') ? 'ONLINE' : status.toUpperCase();
                if (status === 'unreachable') statusText = 'OFFLINE';
                const escapedHostname = escapeHtml(system.hostname);
                html += `<li data-hostname="${escapedHostname}"><div><span class="hostname">${escapedHostname}</span><br><span class="ip">${escapeHtml(system.address)}</span></div><div class="system-actions"><button class="buttons" onclick="startProcessing(this, '${escapedHostname}')">Upload</button><span class="status ${statusClass}">${escapeHtml(statusText)}</span></div><div class="device-progress-container"><div class="device-progress-bar"><div></div></div><div class="device-progress-message"></div></div></li>`;
            });
            html += '</ul>';
            systemsDiv.innerHTML = (foundSystems === 0) ? '<p>No remote systems found.</p>' : html;
        });
    }

    function escapeHtml(text) { return text ? String(text).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]) : ''; }
    
    fetchSystems(); 
    setInterval(fetchSystems, 5000);
</script>
