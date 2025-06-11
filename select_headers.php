<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Select Headers & Merge Key</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .file-section { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 6px; }
    .header-box { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; max-width: 300px; }
    .header-item { background: #f0f0f0; padding: 8px; border-radius: 4px; }
    .loader {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding: 20px;
      color: #555;
      font-style: italic;
    }
    .spinner {
      border: 4px solid #f3f3f3;
      border-top: 4px solid #555;
      border-radius: 50%;
      width: 18px;
      height: 18px;
      animation: spin 1s linear infinite;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    select { margin-left: 10px; }
    button:disabled {
      background-color: #ccc;
      cursor: not-allowed;
    }
  </style>
</head>
<body>
  <h2>Select Headers and Merge Keys</h2>
   
  <form method="post" action="merge_files.php">
    <?php
    session_start();
    if (!isset($_SESSION['uploaded_files']) || empty($_SESSION['uploaded_files'])) {
      echo "<div class='loader'><div class='spinner'></div>Loading headers, please wait...</div>";
      echo "<script>document.addEventListener('DOMContentLoaded', function() { document.querySelector('button[type=submit]').disabled = true; });</script>";
      exit;
    }
    
    // Remove duplicates based on file name and path
    $files = $_SESSION['uploaded_files'];    
    $uniqueFiles = [];
    $seen = [];
    
    foreach ($files as $file) {
      $key = $file['name'] . '|' . $file['path'];
      if (!isset($seen[$key])) {
        $uniqueFiles[] = $file;
        $seen[$key] = true;
      }
    }
    
    foreach ($uniqueFiles as $index => $file) {
      $fileName = !empty($file['name']) ? htmlspecialchars($file['name']) : 'Unknown File';
      $filePath = !empty($file['path']) ? htmlspecialchars($file['path']) : '';
      $headers = !empty($file['headers']) && is_array($file['headers']) ? $file['headers'] : [];
   
      echo "<div class='file-section'>";
      echo "<h4>File: $fileName</h4>";
   
      if (!empty($headers)) {
        echo "<div class='header-box'>";
        foreach ($headers as $header) {
          $safeHeader = htmlspecialchars($header);
          echo "<label class='header-item'><input type='checkbox' name='columns[$index][]' value='$safeHeader'> $safeHeader</label>";
        }
        echo "</div>";
     
        echo "<label style='display:block;margin-top:10px;'>Merge Key: ";
        echo "<select name='join_keys[$index]'>";
        foreach ($headers as $header) {
          $safeHeader = htmlspecialchars($header);
          echo "<option value='$safeHeader'>$safeHeader</option>";
        }
        echo "</select></label>";
      } else {
        echo "<div class='loader'><div class='spinner'></div>No headers found or still loading...</div>";
        echo "<label style='display:block;margin-top:10px;'>Merge Key: ";
        echo "<select name='join_keys[$index]'><option value=''>-- Loading --</option></select></label>";
        echo "<script>document.addEventListener('DOMContentLoaded', function() { document.querySelector('button[type=submit]').disabled = true; });</script>";
      }
   
      echo "<input type='hidden' name='files[$index][path]' value='$filePath'>";
      echo "<input type='hidden' name='files[$index][name]' value='$fileName'>";
      echo "</div>";
    }
    ?>
    <button type="submit">Merge Files</button>
  </form>
</body>
</html>