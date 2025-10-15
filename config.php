<?php
$pluginName = "fpp-FSEQDistributor";
include_once 'common/easyui_functions.php';

$uploadDir = "/home/fpp/media/upload/";
$sequencesDir = "/home/fpp/media/sequences/";
$outputDir = "/home/fpp/media/sequences/";

// Získaj zoznam súborov
$xlsxFiles = glob($uploadDir . "*.xlsx");
$fseqFiles = glob($sequencesDir . "*.fseq");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['fseq']) && isset($_POST['xlsx'])) {
    $fseqPath = $sequencesDir . basename($_POST['fseq']);
    $xlsxPath = $uploadDir . basename($_POST['xlsx']);
    
    // Kontrola, či súbory existujú
    if (!file_exists($fseqPath)) {
        echo "<div style='color: red;'>❌ FSEQ súbor neexistuje: $fseqPath</div>";
        exit;
    }
    if (!file_exists($xlsxPath)) {
        echo "<div style='color: red;'>❌ XLSX súbor neexistuje: $xlsxPath</div>";
        exit;
    }
    
    // Spusti Python skript
    $command = "python3 /home/fpp/media/plugins/$pluginName/parse_fseq.py " . 
               escapeshellarg($fseqPath) . " " . 
               escapeshellarg($xlsxPath) . " " . 
               escapeshellarg($outputDir) . " 2>&1";
    
    echo "<div style='background: #f0f0f0; padding: 10px; margin: 10px 0;'>";
    echo "<strong>🚀 Spracovávam...</strong><br>";
    echo "FSEQ: " . basename($fseqPath) . "<br>";
    echo "XLSX: " . basename($xlsxPath) . "<br><br>";
    
    $output = shell_exec($command);
    
    if (strpos($output, 'Error') !== false || strpos($output, 'Traceback') !== false) {
        echo "<strong style='color: red;'>❌ Chyba:</strong><br>";
    } else {
        echo "<strong style='color: green;'>✅ Hotovo!</strong><br>";
    }
    echo "<pre style='background: white; padding: 10px;'>$output</pre>";
    echo "</div>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <style>
        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            font-family: Arial, sans-serif;
        }
        .form-group {
            margin: 15px 0;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        select {
            width: 100%;
            padding: 8px;
            font-size: 14px;
        }
        input[type="submit"] {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        input[type="submit"]:hover {
            background: #45a049;
        }
        .info {
            background: #e7f3ff;
            padding: 10px;
            border-left: 4px solid #2196F3;
            margin: 10px 0;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>🎄 FSEQ Distributor</h2>
    
    <div class="info">
        <strong>ℹ️ Návod:</strong><br>
        1. Nahraj XLSX súbor do <code>/home/fpp/media/upload/</code><br>
        2. Vyber FSEQ súbor z <code>/home/fpp/media/sequences/</code><br>
        3. Stlač "Spracovať"
    </div>
    
    <form method="post">
        <div class="form-group">
            <label>📊 Vyber XLSX súbor (z ~/media/upload):</label>
            <select name="xlsx" required>
                <option value="">-- Vyber súbor --</option>
                <?php foreach ($xlsxFiles as $file): ?>
                    <option value="<?php echo basename($file); ?>">
                        <?php echo basename($file); ?>
                    </option>
                <?php endforeach; ?>
                <?php if (empty($xlsxFiles)): ?>
                    <option value="" disabled>Žiadne XLSX súbory v ~/media/upload/</option>
                <?php endif; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>🎵 Vyber FSEQ súbor (z ~/media/sequences):</label>
            <select name="fseq" required>
                <option value="">-- Vyber súbor --</option>
                <?php foreach ($fseqFiles as $file): ?>
                    <option value="<?php echo basename($file); ?>">
                        <?php echo basename($file); ?>
                    </option>
                <?php endforeach; ?>
                <?php if (empty($fseqFiles)): ?>
                    <option value="" disabled>Žiadne FSEQ súbory v ~/media/sequences/</option>
                <?php endif; ?>
            </select>
        </div>
        
        <input type="submit" value="🚀 Spracovať a distribuovať">
    </form>
</div>

</body>
</html>
