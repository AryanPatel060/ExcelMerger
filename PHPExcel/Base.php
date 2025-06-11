<?php
    if(file_exists( '/PHPExcel/Classes/PHPExcel.php')){
        require_once '/PHPExcel/Classes/PHPExcel.php';
    }else{
        throw new Exception("PHPExcel.php not found in:");
    }
    class PHPExcel_Base{
        public static function identify($excelFilePath){
            return PHPExcel_IOFactory::identify($excelFilePath);
        }
        public static function createReader($excelFileType){
            return PHPExcel_IOFactory::createReader($excelFileType);
        }
    }
?>