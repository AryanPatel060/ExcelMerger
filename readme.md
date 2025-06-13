# 🔗 Excel File Joiner Web Tool

A lightweight web-based mini project that allows users to **merge two Excel files** using a common key, similar to **SQL JOIN operations** (like `INNER JOIN`, `LEFT JOIN`, etc.). This tool provides an intuitive interface for uploading files, selecting header keys, and downloading the processed output.

---

## 🚀 Features

- ✅ Upload Excel files (XLS/XLSX/CSV)
- ✅ Select matching headers (join keys) for merging
- ✅ Choose which columns to include in the output
- ✅ Process and download the final merged Excel file
- ✅ Works similarly to SQL-style joins (e.g., VLOOKUP-style matching)

---

## 🖥️ How It Works

1. **Upload Files:**  
   Upload two Excel/CSV files from your system via the upload page.

2. **Select Headers:**  
   Choose the headers (columns) you want to match on (e.g., `Product ID`, `SKU`) and the output headers to include.

3. **Process Join:**  
   The backend processes the selected headers and merges the files using the common keys.

4. **Download Output:**  
   The final merged file is available for download in Excel format.

---

## 🛠️ Tech Stack

- **Frontend:** HTML, CSS, JavaScript (Basic UI for interaction)
- **Backend:** PHP (or your preferred language), File handling, Excel parsing
- **Libraries:** PHPSpreadsheet, file upload & validation utilities

---

## 📂 Folder Structure 

project-root/
├── uploads/ # Temp storage for uploaded files
├── output/ # Stores processed output files
├── index.php # Upload page
├── select_headers.php # Page for selecting headers
├── process.php # Join logic and file generation
└── download.php # Serves the final output


---

## 📸 Screenshots 

### 🔼 Upload Page
![Upload Page](uploads/media/uploadpage.png)

### 📝 Header Selection
![Header Selection](uploads/media/headerselectionpage.png)

### 📥 Download Output
![Download Page](uploads/media/downloadpage.png)


---


## 🙌 Acknowledgments

- Inspired by MySQL joins and Excel VLOOKUP functionality.
- Special thanks to [PHPExcel](https://github.com/PHPOffice/PHPExcel) or [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) for Excel file parsing.

---

## 📬 Contact

For feedback or queries:  
**Aryan Patel**  
📧 aryanpatel19aug3@gmail.com

