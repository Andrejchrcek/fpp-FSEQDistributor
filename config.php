<?php
$pluginName = "fpp-FSEQDistributor";

$uploadDir = "/home/fpp/media/upload/";
$sequencesDir = "/home/fpp/media/sequences/";
$outputDir = "/home/fpp/media/plugins/$pluginName/temp/";

// Create output directory if not exists
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// AJAX endpoint for prop status
if (isset($_GET['action']) && $_GET['action'] == 'get_status') {
    header('Content-Type: application/json');
    
    $statusFile = $outputDir . "status.json";
    if (file_exists($statusFile)) {
        echo file_get_contents($statusFile);
    } else {
        echo json_encode([]);
    }
    exit; // CRITICAL: Stop here, don't render HTML
}

// AJAX endpoint for processing
if (isset($_POST['action']) && $_POST['action'] == 'process') {
    header('Content-Type: application/json');
    
    $fseqPath = $sequencesDir . basename($_POST['fseq']);
    $xlsxPath = $uploadDir . basename($_POST['xlsx']);
    
    if (!file_exists($fseqPath)) {
        echo json_encode(['success' => false, 'message' => "FSEQ file not found: " . basename($fseqPath)]);
        exit;
    }
    
    if (!file_exists($xlsxPath)) {
        echo json_encode(['success' => false, 'message' => "XLSX file not found: " . basename($xlsxPath)]);
        exit;
    }
    
    // Run Python script
    $command = "python3 /home/fpp/media/plugins/$pluginName/parse_fseq.py " . 
               escapeshellarg($fseqPath) . " " . 
               escapeshellarg($xlsxPath) . " " . 
               escapeshellarg($outputDir) . " 2>&1";
    
    $output = shell_exec($command);
    $success = !(strpos($output, 'Error') !== false || strpos($output, 'Traceback') !== false);
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Processing completed successfully!' : 'Error occurred during processing',
        'output' => $output,
        'fseq' => basename($fseqPath),
        'xlsx' => basename($xlsxPath)
    ]);
    exit;
}

// Get file lists for UI
$xlsxFiles = glob($uploadDir . "*.xlsx");
$fseqFiles = glob($sequencesDir . "*.fseq");
?>
<!DOCTYPE html>
<html>
<head>
    <title>FSEQ Distributor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .card h3 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        
        .form-group {
            margin: 15px 0;
        }
        
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
        }
        
        select {
            width: 100%;
            padding: 12px;
            font-size: 14px;
            border: 2px solid #ddd;
            border-radius: 5px;
            background: white;
            transition: border-color 0.3s;
        }
        
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            border-radius: 5px;
            width: 100%;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            animation: slideIn 0.3s;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert.info {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
        }
        
        .alert.error {
            background: #ffe7e7;
            border-left: 4px solid #f44336;
        }
        
        .alert.success {
            background: #e7ffe7;
            border-left: 4px solid #4CAF50;
        }
        
        .device-list {
            width: 100%;
        }
        
        .device-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.3s;
        }
        
        .device-item:hover {
            background: #f9f9f9;
        }
        
        .device-item:last-child {
            border-bottom: none;
        }
        
        .device-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-right: 15px;
            color: white;
        }
        
        .device-icon.online {
            background: #4CAF50;
        }
        
        .device-icon.offline {
            background: #9e9e9e;
        }
        
        .device-icon.uploading {
            background: #ff9800;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .device-info {
            flex: 1;
        }
        
        .device-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .device-ip {
            font-size: 12px;
            color: #999;
        }
        
        .device-status {
            min-width: 120px;
            text-align: right;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.online {
            background: #e7ffe7;
            color: #4CAF50;
        }
        
        .status-badge.offline {
            background: #f0f0f0;
            color: #9e9e9e;
        }
        
        .status-badge.uploading {
            background: #fff3e0;
            color: #ff9800;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #eee;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.5s;
        }
        
        .refresh-btn {
            background: #f0f0f0;
            color: #333;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .refresh-btn:hover {
            background: #e0e0e0;
        }
        
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        details {
            margin-top: 10px;
            cursor: pointer;
        }
        
        summary {
            font-weight: 600;
            padding: 5px;
            user-select: none;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🎄 FSEQ Distributor</h1>
        <p>Distribute FSEQ files to ESPixelStick Props</p>
    </div>
    
    <div id="resultMessage"></div>
    
    <div class="grid">
        <!-- Form -->
        <div class="card">
            <h3>📤 Process and Upload</h3>
            
            <div class="alert info">
                <strong>ℹ️ Instructions:</strong><br>
                1. Upload XLSX to <code>~/media/upload/</code><br>
                2. FSEQ files in <code>~/media/sequences/</code><br>
                3. Select files and process
            </div>
            
            <form id="uploadForm" onsubmit="return false;">
                <div class="form-group">
                    <label>📊 XLSX File (Prop Connections):</label>
                    <select name="xlsx" id="xlsxSelect" required>
                        <option value="">-- Select file --</option>
                        <?php foreach ($xlsxFiles as $file): ?>
                            <option value="<?php echo htmlspecialchars(basename($file)); ?>">
                                <?php echo htmlspecialchars(basename($file)); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($xlsxFiles)): ?>
                            <option value="" disabled>⚠️ No XLSX files found</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>🎵 FSEQ File:</label>
                    <select name="fseq" id="fseqSelect" required>
                        <option value="">-- Select file --</option>
                        <?php foreach ($fseqFiles as $file): ?>
                            <option value="<?php echo htmlspecialchars(basename($file)); ?>">
                                <?php echo htmlspecialchars(basename($file)); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($fseqFiles)): ?>
                            <option value="" disabled>⚠️ No FSEQ files found</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <button type="button" class="btn" id="submitBtn" onclick="processFiles()">🚀 Upload Show</button>
            </form>
        </div>
        
        <!-- Prop Status -->
        <div class="card">
            <h3>🎛️ Prop Status</h3>
            <button class="refresh-btn" onclick="refreshDevices()">🔄 Refresh</button>
            
            <div id="device-list" class="device-list">
                <div class="loading">
                    <div class="spinner"></div>
                    Loading...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function processFiles() {
    const btn = document.getElementById('submitBtn');
    const xlsx = document.getElementById('xlsxSelect').value;
    const fseq = document.getElementById('fseqSelect').value;
    const resultDiv = document.getElementById('resultMessage');
    
    if (!xlsx || !fseq) {
        resultDiv.innerHTML = '<div class="alert error">❌ Please select both XLSX and FSEQ files</div>';
        return;
    }
    
    // Disable button
    btn.disabled = true;
    btn.innerHTML = '⏳ Processing...';
    resultDiv.innerHTML = '';
    
    // Send AJAX request
    const formData = new FormData();
    formData.append('action', 'process');
    formData.append('xlsx', xlsx);
    formData.append('fseq', fseq);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '🚀 Upload Show';
        
        const alertClass = data.success ? 'success' : 'error';
        const icon = data.success ? '✅' : '❌';
        
        let html = `<div class="alert ${alertClass}">
            <strong>${icon} ${data.message}</strong><br>
            <small>FSEQ: ${data.fseq}</small><br>
            <small>XLSX: ${data.xlsx}</small>`;
        
        if (data.output) {
            html += `<details>
                <summary>Show output details</summary>
                <pre>${escapeHtml(data.output)}</pre>
            </details>`;
        }
        
        html += '</div>';
        resultDiv.innerHTML = html;
        
        // Refresh device list
        refreshDevices();
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = '🚀 Upload Show';
        resultDiv.innerHTML = `<div class="alert error">❌ Error: ${error.message}</div>`;
    });
}

function refreshDevices() {
    fetch('?action=get_status')
        .then(response => {
            if (!response.ok) throw new Error('Network error');
            return response.json();
        })
        .then(devices => {
            const container = document.getElementById('device-list');
            
            if (!devices || devices.length === 0) {
                container.innerHTML = '<div class="empty-state">No props found<br><small>Upload a show to see connected props</small></div>';
                return;
            }
            
            container.innerHTML = devices.map(device => `
                <div class="device-item">
                    <div class="device-icon ${device.status}">
                        ${device.status === 'online' ? '✓' : device.status === 'uploading' ? '↑' : '✗'}
                    </div>
                    <div class="device-info">
                        <div class="device-name">${escapeHtml(device.name)}</div>
                        <div class="device-ip">${escapeHtml(device.ip)}</div>
                        ${device.status === 'uploading' && device.progress !== undefined ? `
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${device.progress}%"></div>
                            </div>
                        ` : ''}
                    </div>
                    <div class="device-status">
                        <span class="status-badge ${device.status}">
                            ${device.status === 'online' ? 'Ready' : 
                              device.status === 'uploading' ? 'Uploading ' + (device.progress || 0) + '%' : 
                              'Offline'}
                        </span>
                    </div>
                </div>
            `).join('');
        })
        .catch(err => {
            console.error('Error loading props:', err);
            document.getElementById('device-list').innerHTML = 
                '<div class="empty-state">Error loading props<br><small>' + err.message + '</small></div>';
        });
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// Initial load and auto-refresh every 3 seconds
refreshDevices();
setInterval(refreshDevices, 3000);
</script>

</body>
</html>
