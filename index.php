<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Multi File Upload & Header Extract</title>
  <style>
    body { font-family: sans-serif; padding: 20px; }
    #status { margin-top: 15px; color: green; }
  </style>
</head>
<body>
  <h2>Upload Excel/CSV Files</h2>
  <form id="uploadForm" enctype="multipart/form-data">
    <input type="file" name="files[]" id="fileInput" multiple required>
    <button type="submit">Upload Files</button>
  </form>
  <div id="status"></div>

  <script>
    document.getElementById('uploadForm').addEventListener('submit', function (e) {
      e.preventDefault();

      const formData = new FormData();
      const files = document.getElementById('fileInput').files;
      for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
      }

      fetch('upload_files.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          document.getElementById('status').textContent = 'Files uploaded and headers extracted successfully.';
          // Redirect to header selection page
          window.location.href = 'select_headers.php';
        } else {
          document.getElementById('status').textContent = 'Upload failed: ' + data.message;
        }
      })
      .catch(err => {
        document.getElementById('status').textContent = 'Error: ' + err;
      });
    });
  </script>
</body>
</html>
