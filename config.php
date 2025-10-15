<?php
$pluginName = "fpp-FSEQDistributor";

$uploadDir = "/home/fpp/media/upload/";
$sequencesDir = "/home/fpp/media/sequences/";
$outputDir = "/home/fpp/media/plugins/$pluginName/temp/";

// Create output directory if not exists
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
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
            max-width: 800px;
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
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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

        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
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
    .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        return response.json();
    })
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
        
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = '🚀 Upload Show';
        resultDiv.innerHTML = `<div class="alert error">❌ Error: ${error.message}</div>`;
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
    // Zabezpečí, že ak je vstup undefined/null, vráti prázdny reťazec
    if (text === undefined || text === null) return '';
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}
</script>

</body>
</html>
