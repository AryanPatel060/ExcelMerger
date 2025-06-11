<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require_once './PHPExcel/Classes/PHPExcel/IOFactory.php'; // Load if using PHPExcel

class HeaderOnlyFilter implements PHPExcel_Reader_IReadFilter {
    public function readCell($column, $row, $worksheetName = '') {
        return $row == 1;
    }
}

function readExcelHeader($tmpFile) {
    $zip = new ZipArchive;
    if ($zip->open($tmpFile) === TRUE) {
        // Load shared strings first
        $sharedStrings = [];
        if (($index = $zip->locateName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($zip->getFromIndex($index));
            foreach ($xml->si as $val) {
                $sharedStrings[] = (string) $val->t;
            }
        }

        // Open sheet1.xml using stream
        if (($sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml')) !== false) {
            $sheetXml = $zip->getFromIndex($sheetIndex);
            $reader = new XMLReader();
            $reader->XML($sheetXml);
            $insideRow = false;
            $rowCount = 0;

       
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'row') {
                    $rowCount++;
                    if ($rowCount > 1) break;

                    $rowDom = new DOMDocument();
                    $rowNode = $reader->expand();
                    $rowNode = $rowDom->importNode($rowNode, true);
                    $rowDom->appendChild($rowNode);

                    $cells = $rowDom->getElementsByTagName('c');
            
                    foreach ($cells as $cell) {
                        $value = '';
                        $type = $cell->getAttribute('t');
                        $vNode = $cell->getElementsByTagName('v')->item(0);
                        if ($vNode) {
                            $v = $vNode->nodeValue;
                            if ($type === 's') {
                                $value = $sharedStrings[(int)$v];
                            } else {
                                $value = $v;
                            }
                        }
                        $header[] = htmlspecialchars($value);
                    }
                }
            }

            $reader->close();
        } else {
            echo "Could not locate sheet1.xml.";
        }
        
        $zip->close();
    } else {
        echo "Error opening XLSX file.";
    }
    return $header;
}

$response = ['success' => false, 'message' => 'Unknown error'];
$baseUploadPath = __DIR__ . '/uploads';

// Create dated folder structure: uploads/YYYY-MM-DD/HHMMSS/
$today = date('Y-m-d');
$hourPath = date('H');
$targetDir = "$baseUploadPath/$today/$hourPath";

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (!isset($_FILES['files'])) {
    $response['message'] = 'No files received.';
    echo json_encode($response);
    exit;
}

// Initialize session array
if (!isset($_SESSION['uploaded_files'])) {
    $_SESSION['uploaded_files'] = [];
}


$uploadedFiles = $_FILES['files'];
$total = count($uploadedFiles['name']);
$_SESSION['uploaded_files'] = [];

for ($i = 0; $i < $total; $i++) {
    $tmpName = $uploadedFiles['tmp_name'][$i];
    $name = basename($uploadedFiles['name'][$i]);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!is_uploaded_file($tmpName)) continue;
    $uniqId = uniqid();
    $targetPath = "$targetDir/$uniqId"."_"."$name";
    if (!move_uploaded_file($tmpName, $targetPath)) continue;

    $headers = [];

    if ($ext === 'csv') {
        if (($handle = fopen($targetPath, 'r')) !== false) {
            $headers = fgetcsv($handle);
            fclose($handle);
        }
    } elseif (in_array($ext, ['xls', 'xlsx'])) {
        try {

            $headers = readExcelHeader($targetPath);
            // Create reader with minimal settings for speed
            // $reader = PHPExcel_IOFactory::createReaderForFile($targetPath);
            // $reader->setReadDataOnly(true);
            // $reader->setReadEmptyCells(false);
            
            // // Only read first row for headers
            // $reader->setReadFilter(new HeaderOnlyFilter());
            
            // $spreadsheet = $reader->load($targetPath);
            // $sheet = $spreadsheet->getActiveSheet();
            
            // // Get headers directly from first row
            // $headers = [];
            // $columnIterator = $sheet->getRowIterator(1, 1)->current()->getCellIterator();
            // $columnIterator->setIterateOnlyExistingCells(false);
            
            // foreach ($columnIterator as $cell) {
            //     $value = $cell->getValue();
            //     if ($value === null || $value === '') break;
            //     $headers[] = $value;
            // }
            
            // $response[$originalName] = $headers;
            
            // Free memory immediately
            // $spreadsheet->disconnectWorksheets();
            // unset($spreadsheet, $sheet, $reader);
            
        } catch (Exception $e) {
            $response[$originalName] = ['Error: ' . $e->getMessage()];
        }
    }

    $_SESSION['uploaded_files'][] = [
        'name' => $name,
        'path' => "uploads".explode( "/uploads",$targetPath)[1],
        'headers' => $headers
    ];
}

$response['success'] = true;
$response['message'] = 'Files uploaded';
echo json_encode($response);
