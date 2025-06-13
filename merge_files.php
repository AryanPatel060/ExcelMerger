<?php
ini_set('max_execution_time', 600);
ini_set('memory_limit', '3G');
require __DIR__ . '/vendor/autoload.php';
require_once 'PHPExcel/Classes/PHPExcel/IOFactory.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class FileMerger
{
    private $tempDir;
    private $chunkSize;
    private $outputDir;

    public function __construct($outputDir = 'uploads/results/', $chunkSize = 5000)
    {
        $this->outputDir = $outputDir;
        $this->chunkSize = $chunkSize;
       
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    public function processFiles($filesMeta, $joinKeys, $columns)
    {
        try {
            if (empty($filesMeta)) {
                throw new Exception("No files provided");
            }

            $indexFiles = [];
            $allColumns = [];

            echo "Building indices for lookup files...\n";

            // Build indices for all lookup files
            foreach ($filesMeta as $index => $fileMeta) {
                if (!isset($joinKeys[$index]) || !isset($columns[$index])) {
                    echo "Skipping file {$fileMeta['name']}: Missing join key or columns\n";
                    continue;
                }                
                if($index != 0)
                {
                    echo "Processing index for: {$fileMeta['name']}\n";
                    $indexData = $this->buildIndex(
                        $fileMeta['path'],
                        $columns[$index],
                        $joinKeys[$index]
                    );   
                    $indexFiles[] = $indexData;
                }
                $allColumns[] = $columns[$index];
            }            
            
            $mainFile = $filesMeta[0];
            unset($filesMeta[0]);

            $outputFile = $this->outputDir . 'merged_' . date('Y-m-d_H-i-s') . '.csv';

            echo "Starting merge process...\n";

            $this->mergeFiles(
                $mainFile['path'],
                $indexFiles,
                $joinKeys[0],
                $outputFile,
                $allColumns
            );

            echo "<br>Merge completed successfully!<br>";
            echo "Output file: $outputFile\n<br>";

            // Generate download button
            $this->generateDownloadButton($outputFile);

            return $outputFile;
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n<br>";
            throw $e;
        }
    }

    public function buildIndex($filePath, $columns, $key)
    {
        if (!file_exists($filePath)) {
            throw new Exception("File does not exist: $filePath");
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        if (strtolower($ext) === 'xlsx' || strtolower($ext) === 'xls') {
            return $this->buildIndexFromExcel($filePath, $columns, $key);
        }

        return $this->buildIndexFromCsv($filePath, $columns, $key);
    }

    private function buildIndexFromCsv($filePath, $columns, $key)
    {
        $indexFile = $this->tempDir . 'index_' . md5($filePath) . '.json';

        if (file_exists($indexFile)) {
            return $indexFile;
        }

        $file = fopen($filePath, 'r');
        if ($file === false) {
            throw new Exception("Could not open CSV file: $filePath");
        }

        $header = fgetcsv($file);
        if ($header === false) {
            fclose($file);
            throw new Exception("Could not read header from CSV file: $filePath");
        }

        $keyIndex = array_search($key, $header);

        
        if ($keyIndex === false) {
            fclose($file);
            throw new Exception("Join key '$key' not found in CSV file: $filePath");
        }

        // Get column indices
        $columnIndices = [];
        foreach ($columns as $col) {
            $colIndex = array_search($col, $header);
            if ($colIndex !== false) {
                $columnIndices[$col] = $colIndex;
            }
        }

        $indexData = [];

        while (($row = fgetcsv($file)) !== false) {
            $requiredCols = [];
            foreach ($columnIndices as $col => $colIndex) {
                $requiredCols[$col] = $row[$colIndex] ?? '';
            }

            $this->setNestedValue($indexData, $row[$keyIndex], $requiredCols);
            
        }
        fclose($file);
        return $indexData;
    }

    private function buildIndexFromExcel($filePath, $columns, $key)
    {
        echo "Processing Excel file: $filePath\n<br>";

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            // Read header row
            $header = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
                $header[] = trim((string) $cellValue);
            }

            $normalizedHeader = array_map('strtolower', $header);
            $keyIndex = array_search(strtolower($key), $normalizedHeader);
            if ($keyIndex === false) {
                throw new Exception("Join key '$key' not found in Excel file: $filePath");
            }

            // Get column indices
            $columnIndices = [];
            foreach ($columns as $col) {
                $colIndex = array_search(strtolower($col), $normalizedHeader);
                if ($colIndex !== false) {
                    $columnIndices[$col] = $colIndex;
                }
            }

            $indexData = [];
            $processedRows = 0;

            // Process rows starting from row 2 (skip header)
            for ($row = 2; $row <= $highestRow; $row++) {
                $value = $worksheet->getCellByColumnAndRow($keyIndex + 1, $row)->getValue();
                $requiredCols = [];
                foreach ($columnIndices as $col => $colIndex) {
                    $cellValue = $worksheet->getCellByColumnAndRow($colIndex + 1, $row)->getValue();
                    $requiredCols[$col] = $cellValue ?? '';
                }
                $this->setNestedValue($indexData, $value, $requiredCols);
            }       
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            echo "Excel index built: $processedRows rows processed\n";
            return $indexData;
        } catch (Exception $e) {
            throw new Exception("Error processing Excel file '$filePath': " . $e->getMessage());
        }
    }

    private function mergeFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns)
    {
        $ext = pathinfo($mainFilePath, PATHINFO_EXTENSION);

        if (strtolower($ext) === 'xlsx' || strtolower($ext) === 'xls') {
            return $this->mergeExcelFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns);
        }
        $this->mergeCsvFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns);
        return ;
    }

    private function mergeCsvFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns)
    {
        $mainFile = fopen($mainFilePath, 'r');
        $outputHandle = fopen($outputFile, 'w');

        if ($mainFile === false || $outputHandle === false) {
            throw new Exception("Could not open CSV files for processing");
        }

        // Read main file header
        $mainHeader = fgetcsv($mainFile);
        $keyIndex = array_search($joinKey, $mainHeader);

        if ($keyIndex === false) {
            throw new Exception("Join key '$joinKey' not found in main CSV file");
        }

        $columnsToOut = [];

        foreach ($allColumns as $col) {
            foreach ($col as $_col) {
                $columnsToOut[] = $_col;
            }
        }
        
        fputcsv($outputHandle, $columnsToOut);  
        $columnsToOut = $allColumns[0];  

        while (($row = fgetcsv($mainFile)) !== false) {

            $_mergedRow = array_combine($mainHeader, $row);
            
            $mergedRow = [];
            foreach ($columnsToOut as $col) {
                
                $mergedRow[$col] = $_mergedRow[$col];
            }
            foreach ($indexFiles as $indexFile) {
                $matchedData = $this->getDataFromIndex($indexFile, $row[$keyIndex]);
                
                if ($matchedData) {
                    $mergedRow = array_merge($mergedRow, $matchedData);
                }
            }       
            
            @fputcsv($outputHandle, $mergedRow);

        }

        fclose($mainFile);
        fclose($outputHandle);

        return ;
    }

    private function mergeExcelFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns)
    {
        try {
            $spreadsheet = IOFactory::load($mainFilePath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            $mainHeader = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
                $mainHeader[] = trim((string)$cellValue);
            }
          
            $normalizedHeader = array_map('strtolower', $mainHeader);
            
            $keyIndex = array_search(strtolower($joinKey), $normalizedHeader);
       
            if ($keyIndex === false) {
                throw new Exception("Join key '$joinKey' not found in main Excel file");
            }

            $outputHandle = fopen($outputFile, 'w');
            if (!$outputHandle) {
                throw new Exception("Failed to create output file: $outputFile");
            }
            
            $columnsToOut = [];

            foreach ($allColumns as $col) {
                foreach ($col as $_col) {
                    $columnsToOut[] = $_col;
                }
            }
            $finalHeader = $columnsToOut; 
          
            fputcsv($outputHandle, $columnsToOut); 
            $columnsToOut = $allColumns[0]; 
            
            for ($row = 2; $row <= $highestRow; $row++) {
                $value = $worksheet->getCellByColumnAndRow($keyIndex + 1, $row)->getValue();

                $rowData = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cellValue = $worksheet->getCellByColumnAndRow($col, $row)->getValue();
                    $rowData[] = $cellValue;
                }
                
                $mergedRow = array_combine($mainHeader, $rowData);
               
                foreach ($indexFiles as $indexFile) {
                    $matchedData = $this->getDataFromIndex($indexFile, $value);
                    if ($matchedData) {
                        $mergedRow = array_merge($mergedRow, $matchedData);
                    }
                }
                
                $finalRow = [];
                foreach ($finalHeader as $column) {
                    $finalRow[] = $mergedRow[$column] ?? '';
                }
              
                fputcsv($outputHandle, $finalRow);
               
            }

            fclose($outputHandle);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return ;
        } catch (Exception $e) {
            throw new Exception("Error merging Excel files: " . $e->getMessage());
        }
    }

    private function setNestedValue(&$targetArray, $key, $value)
    {
        if(strlen($key) %2 == 0) {
            $keys = str_split((string)$key, 2); 
        } else {
            $keys = str_split("_".$key, 2); 
        }
      
        $current = &$targetArray;

        foreach ($keys as $key) {
            $key = trim($key);
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[(string)$key];
        }

        $current['value'] = $value;
    
    }

    private function getDataFromIndex($indexFile, $key)
    {      

        if(strlen($key) %2 == 0) {
            $keys = str_split((string)$key, 2); 
        } else {
            $keys = str_split("_".$key, 2); 
        }
        $current = $indexFile;       
    
        foreach ($keys as $key) {
            if (isset($current[$key])) {
                $current = $current[$key];
            } else {
                return null;
            }
        }
        
        if(isset($current['value']))
        {
            return $current['value'];
        }
        else {
            return null;
        }
    }

    private function generateDownloadButton($outputFile)
    {
        if (file_exists($outputFile)) {
            $fileName = basename($outputFile);
            $fileSize = filesize($outputFile);
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);

            echo "<div style='margin: 20px; padding: 15px; border: 1px solid #ccc; background: #f9f9f9; border-radius: 5px;'>";
            echo "<h3 style='color: #28a745; margin: 0 0 10px 0;'>✅ File Merge Completed Successfully!</h3>";
            echo "<p><strong>File:</strong> $fileName</p>";
            echo "<p><strong>Size:</strong> {$fileSizeMB} MB</p>";
            echo "<a download href='$outputFile'";
            echo "style='display: inline-block; padding: 12px 24px; background: #007cba; color: white; text-decoration: none; border-radius: 5px; margin: 10px 0; font-weight: bold;'>";
            echo "📥 Download Merged File</a>";
            echo "</div>";
        }
    }

    public function cleanup()
    {
        $files = glob($this->tempDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

// ===== MAIN PROCESSING =====

try {
    $filesMeta = $_POST['files'] ?? [];
    $joinKeys = $_POST['join_keys'] ?? [];
    $columns = $_POST['columns'] ?? [];


    if (empty($filesMeta)) {
        throw new Exception("No files provided");
    }

    $fileMerger = new FileMerger('uploads/results/', 5000);

    echo "<h2>🔄 Processing File Merge...</h2>";
    echo "<div style='font-family: monospace; background: #f8f9fa; padding: 15px; border-radius: 5px;'>";

    $outputFile = $fileMerger->processFiles($filesMeta, $joinKeys, $columns);

    echo "</div>";
    echo "<p><strong>Memory Peak Usage:</strong> " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB</p>";

    $fileMerger->cleanup();

} catch (Exception $e) {
    echo "<div style='color: red; padding: 15px; background: #ffe6e6; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ Error occurred:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";

    if (isset($fileMerger)) {
        $fileMerger->cleanup();
    }
}
?>

<?php
// ===== DOWNLOAD SCRIPT =====

if (isset($_GET['file'])) {
    $file = $_GET['file'] ?? '';

    if (empty($file) || !file_exists($file)) {
        die('File not found');
    }

    if (strpos($file, 'uploads/results/') !== 0) {
        die('Invalid file path');
    }

    $fileName = basename($file);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    $handle = fopen($file, 'rb');
    while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
    }
    fclose($handle);
    exit;
}
?>