<?php
ini_set('max_execution_time', 600);
ini_set('memory_limit', '3076M');
require __DIR__ . '/vendor/autoload.php';

require_once 'PHPExcel/Classes/PHPExcel/IOFactory.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class FileMerger
{
    private $tempDir;
    private $chunkSize;
    private $outputDir;

    public function __construct($tempDir = 'temp/', $outputDir = 'uploads/results/', $chunkSize = 5000)
    {
        $this->tempDir = $tempDir;
        $this->outputDir = $outputDir;
        $this->chunkSize = $chunkSize;

        // Create directories if they don't exist
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Main processing function
     */
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

                echo "Processing index for: {$fileMeta['name']}\n";

                $indexFile = $this->buildIndex(
                    $fileMeta['path'],
                    $columns[$index],
                    $joinKeys[$index]
                );

                $indexFiles[] = $indexFile;
                $allColumns[] = $columns[$index];
            }
            
            // Main file (first file)
            $mainFile = $filesMeta[0];
            unset($filesMeta[0]);
            // Generate output file
            $outputFile = $this->outputDir . 'merged_' . date('Y-m-d_H-i-s') . '.csv';

            echo "Starting merge process...\n";

            // Process main file and merge with indices
            $totalRows = $this->mergeFiles(
                $mainFile['path'],
                $indexFiles,
                $joinKeys[0],
                $outputFile,
                $allColumns
            );

            echo "\nMerge completed successfully!\n";
            echo "Output file: $outputFile\n";
            echo "Total rows processed: $totalRows\n";

            // Generate download button
            $this->generateDownloadButton($outputFile);

            return $outputFile;
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Build index from file (CSV or Excel)
     */
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

    /**
     * Build index from CSV file
     */
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
        $rowCount = 0;

        while (($row = fgetcsv($file)) !== false) {
            $joinValue = $row[$keyIndex];
            $joinKeys = str_split((string)$joinValue, 2);

            $requiredCols = [];
            foreach ($columnIndices as $col => $colIndex) {
                $requiredCols[$col] = $row[$colIndex] ?? '';
            }

            $this->setNestedValue($indexData, $joinKeys, $requiredCols);

            $rowCount++;

            // Write in chunks to manage memory
            if ($rowCount % $this->chunkSize === 0) {
                $this->writeIndexChunk($indexFile, $indexData, $rowCount === $this->chunkSize);
                $indexData = [];
                gc_collect_cycles();

                echo "Processed $rowCount CSV rows...\n";
                flush();
            }
        }

        // Write remaining data
        if (!empty($indexData)) {
            $this->writeIndexChunk($indexFile, $indexData, $rowCount <= $this->chunkSize);
        }

        fclose($file);
        echo "CSV index built: $rowCount rows processed\n";
        return $indexFile;
    }

    /**
     * Build index from Excel file using PHPExcel
     */


    private function buildIndexFromExcel($filePath, $columns, $key)
    {
        $indexFile = $this->tempDir . 'index_' . md5($filePath) . '.json';

        if (file_exists($indexFile)) {
            return $indexFile;
        }

        echo "Processing Excel file: $filePath\n";

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

            // Find key index (case-insensitive)
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
                $joinValue = $worksheet->getCellByColumnAndRow($keyIndex + 1, $row)->getValue();

                
                $joinKeys = str_split((string) $joinValue, 2); // Custom nesting logic

                $requiredCols = [];
                foreach ($columnIndices as $col => $colIndex) {
                    $cellValue = $worksheet->getCellByColumnAndRow($colIndex + 1, $row)->getValue();
                    $requiredCols[$col] = $cellValue ?? '';
                }

                $this->setNestedValue($indexData, $joinKeys, $requiredCols);

                $processedRows++;

                if ($processedRows % $this->chunkSize === 0) {
                    $this->writeIndexChunk($indexFile, $indexData, $processedRows === $this->chunkSize);
                    $indexData = [];
                    gc_collect_cycles();
                    echo "Processed $processedRows Excel rows...\n";
                    flush();
                }
            }

            // Write any remaining data
            if (!empty($indexData)) {
                $this->writeIndexChunk($indexFile, $indexData, $processedRows <= $this->chunkSize);
            }

            // Cleanup
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            echo "Excel index built: $processedRows rows processed\n";
            return $indexFile;
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            throw new Exception("Error processing Excel file '$filePath': " . $e->getMessage());
        }
    }


    /**
     * Merge files with streaming approach
     */
    private function mergeFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns)
    {
        $ext = pathinfo($mainFilePath, PATHINFO_EXTENSION);

        if (strtolower($ext) === 'xlsx' || strtolower($ext) === 'xls') {
            return $this->mergeExcelFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns);
        }

        return $this->mergeCsvFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns);
    }

    /**
     * Merge CSV files with streaming
     */
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

        // Create final header
        // $finalHeader = $this->buildFinalHeader($mainHeader, $allColumns);



        $processedRows = 0;
        $columnsToOut = [];

        foreach ($allColumns as $col) {
            foreach ($col as $_col) {
                $columnsToOut[] = $_col;
            }
        }
        fputcsv($outputHandle, $columnsToOut); // Write main file header to output
        $columnsToOut = $allColumns[0]; // Ensure unique columns


        // Process main file row by row
        while (($row = fgetcsv($mainFile)) !== false) {


            $joinValue = $row[$keyIndex];
            $joinKeys = str_split((string)$joinValue, 2); // YOUR EXACT INDEXING LOGIC


            // Start with main file data
            $_mergedRow = array_combine($mainHeader, $row);

            $mergedRow = [];
            foreach ($columnsToOut as $col) {

                $mergedRow[$col] = $_mergedRow[$col];
            }
           
            
            // Merge data from each index file
            foreach ($indexFiles as $indexFile) {
                $matchedData = $this->getDataFromIndex($indexFile, $joinKeys);
                
                if ($matchedData) {
                    $mergedRow = array_merge($mergedRow, $matchedData);
                }
            }       
           
            
            @fputcsv($outputHandle, $mergedRow);

            $processedRows++;

            // Progress indicator
            if ($processedRows % 10000 === 0) {
                echo "Processed $processedRows rows...\n";
                flush();
            }

            // Memory cleanup
            if ($processedRows % $this->chunkSize === 0) {
                gc_collect_cycles();
            }
        }

        fclose($mainFile);
        fclose($outputHandle);

        return $processedRows;
    }

    /**
     * Merge Excel files with streaming
     */

    private function mergeExcelFiles($mainFilePath, $indexFiles, $joinKey, $outputFile, $allColumns)
    {
        try {
            // Load main Excel file using PhpSpreadsheet
            $spreadsheet = IOFactory::load($mainFilePath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            // Read header
            $mainHeader = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
                $mainHeader[] = trim((string)$cellValue);
            }
          
            
            // Find key index (case-insensitive)
            $normalizedHeader = array_map('strtolower', $mainHeader);
            
            $keyIndex = array_search(strtolower($joinKey), $normalizedHeader);
       
            if ($keyIndex === false) {
                throw new Exception("Join key '$joinKey' not found in main Excel file");
            }

            // Open output CSV
            $outputHandle = fopen($outputFile, 'w');
            if (!$outputHandle) {
                throw new Exception("Failed to create output file: $outputFile");
            }

            // Build final CSV header
            // $finalHeader = $this->buildFinalHeader($mainHeader, $allColumns);
            
           
            
            $columnsToOut = [];

            foreach ($allColumns as $col) {
                foreach ($col as $_col) {
                    $columnsToOut[] = $_col;
                }
            }
            $finalHeader = $columnsToOut; // Ensure unique columns
          
            fputcsv($outputHandle, $columnsToOut); // Write main file header to output
            $columnsToOut = $allColumns[0]; 
            
            $processedRows = 0;

            // Process rows (start at 2 to skip header)
            for ($row = 2; $row <= $highestRow; $row++) {
                $joinValue = $worksheet->getCellByColumnAndRow($keyIndex + 1, $row)->getValue();
                $joinKeys = str_split((string)$joinValue, 2); // Your nesting logic

                // Read all row values
                $rowData = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cellValue = $worksheet->getCellByColumnAndRow($col, $row)->getValue();
                    $rowData[] = $cellValue;
                }
                
                // Combine main header with row
                $mergedRow = array_combine($mainHeader, $rowData);
               

                // Merge index data
                foreach ($indexFiles as $indexFile) {
                    $matchedData = $this->getDataFromIndex($indexFile, $joinKeys);
                    if ($matchedData) {
                        $mergedRow = array_merge($mergedRow, $matchedData);
                    }
                }
                
                // Ensure unique columns
                
                
                // Build final row by header
                $finalRow = [];
                foreach ($finalHeader as $column) {
                    
                    $finalRow[] = $mergedRow[$column] ?? '';
                }
              
                fputcsv($outputHandle, $finalRow);
                $processedRows++;

                if ($processedRows % 1000 === 0) {
                    echo "Processed $processedRows Excel rows...\n";
                    flush();
                }

                if ($processedRows % 5000 === 0) {
                    gc_collect_cycles(); // Free memory
                }
            }

            fclose($outputHandle);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $processedRows;
        } catch (Exception $e) {
            throw new Exception("Error merging Excel files: " . $e->getMessage());
        }
    }


    private function setNestedValue(&$targetArray, $keys, $value)
    {
        $current = &$targetArray;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[(string)$key];
        }

        $current = $value;
    }

    /**
     * Get data from index using YOUR EXACT INDEXING LOGIC
     */
    private function getDataFromIndex($indexFile, $joinKeys)
    {
        static $cache = [];

        if (!isset($cache[$indexFile])) {
            if (!file_exists($indexFile)) {
                return null;
            }

            $indexContent = file_get_contents($indexFile);
            $indexContent = rtrim($indexContent, ",\n") . "\n}";
            $cache[$indexFile] = json_decode($indexContent, true);

        }

 
        $current = $cache[$indexFile];       
    
        // YOUR EXACT INDEXING LOGIC PRESERVED
        foreach ($joinKeys as $key) {
            if (isset($current[$key])) {
                $current = $current[$key];
              
            } else {
                return null;
            }
        }
        
        return is_array($current) ? $current : null;
    }

    /**
     * Write index chunk to file
     */
    private function writeIndexChunk($indexFile, $data, $isFirst = false)
    {
        $mode = $isFirst ? 'w' : 'a';
        $file = fopen($indexFile, $mode);

        if ($isFirst) {
            fwrite($file, "{\n");
        }

        foreach ($data as $key => $value) {
            $line = '"' . $key . '":' . json_encode($value) . ",\n";
            fwrite($file, $line);
        }

        fclose($file);
    }

    /**
     * Build final header combining all columns
     */
    private function buildFinalHeader($mainHeader, $allColumns)
    {
        $finalHeader = $mainHeader;

        foreach ($allColumns as $fileColumns) {
            foreach ($fileColumns as $column) {
                if (!in_array($column, $finalHeader)) {
                    $finalHeader[] = $column;
                }
            }
        }

        return $finalHeader;
    }

    /**
     * Generate download button
     */
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

    /**
     * Clean up temporary files
     */
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
    // Get POST data
    $filesMeta = $_POST['files'] ?? [];
    $joinKeys = $_POST['join_keys'] ?? [];
    $columns = $_POST['columns'] ?? [];


    if (empty($filesMeta)) {
        throw new Exception("No files provided");
    }

    // Initialize file merger
    $fileMerger = new FileMerger('temp/', 'uploads/results/', 5000);

    echo "<h2>🔄 Processing File Merge...</h2>";
    echo "<div style='font-family: monospace; background: #f8f9fa; padding: 15px; border-radius: 5px;'>";

    // Process files
    $outputFile = $fileMerger->processFiles($filesMeta, $joinKeys, $columns);

    echo "</div>";
    echo "<p><strong>Memory Peak Usage:</strong> " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB</p>";

    // Optionally clean up temporary files after success
    // $fileMerger->cleanup();

} catch (Exception $e) {
    echo "<div style='color: red; padding: 15px; background: #ffe6e6; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ Error occurred:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";

    // Cleanup on error
    if (isset($fileMerger)) {
        $fileMerger->cleanup();
    }
}
?>

<?php
// ===== DOWNLOAD SCRIPT =====
// Save this as download.php

if (isset($_GET['file'])) {
    $file = $_GET['file'] ?? '';

    if (empty($file) || !file_exists($file)) {
        die('File not found');
    }

    // Security check - only allow files from results directory
    if (strpos($file, 'uploads/results/') !== 0) {
        die('Invalid file path');
    }

    $fileName = basename($file);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    // Read and output file in chunks to handle large files
    $handle = fopen($file, 'rb');
    while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
    }
    fclose($handle);
    exit;
}
?>