<?php
$pluginName = "fpp-FSEQDistributor";  // Prispôsob
include_once 'common/easyui_functions.php';  // Pre FPP UI helpery

$tempDir = "/home/fpp/media/plugins/$pluginName/temp/";
$outputDir = "/home/fpp/media/sequences/";  // Kam uložiť finálne FSEQ

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['fseq']) && isset($_FILES['xlsx'])) {
    $fseqPath = $tempDir . basename($_FILES['fseq']['name']);
    $xlsxPath = $tempDir . basename($_FILES['xlsx']['name']);
    move_uploaded_file($_FILES['fseq']['tmp_name'], $fseqPath);
    move_uploaded_file($_FILES['xlsx']['tmp_name'], $xlsxPath);

    // Spusti Python skript
    $command = "python3 /home/fpp/media/plugins/$pluginName/parse_fseq.py $fseqPath $xlsxPath $outputDir 2>&1";
    $output = shell_exec($command);
    echo "<pre>Output: $output</pre>";  // Zobraz log
}
?>

<div class="container">
    <h2>FSEQ Distributor Config</h2>
    <form method="post" enctype="multipart/form-data">
        <label>Upload FSEQ:</label> <input type="file" name="fseq" required><br>
        <label>Upload XLSX:</label> <input type="file" name="xlsx" required><br>
        <input type="submit" value="Process & Upload">
    </form>
    <!-- Možno pridaj tabuľku pre status: použi PHP na parsovanie logu -->
</div>
