<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

class PDFGenerator
{
    public static function generatePDF($html, $file_name, $save_file = false, $site_alias = null, $dir_name = null)
    {
        $dompdf = new \DOMPDF();
        $dompdf->load_html($html);
        $dompdf->set_option("enable_html5_parser", true);
        $dompdf->set_option("enable_remote", true);
        $dompdf->set_paper("A4", "portrait");
        $dompdf->render();

        if($save_file){
            $file_path  = public_path()."/shared/".$site_alias."/$dir_name/";
            // check if filepath exists or not
            if(!file_exists($file_path)){
                mkdir($file_path, 0777, true);
            }

            $output = $dompdf->output();
            file_put_contents($file_path.$file_name, $output);
            chmod($file_path.$file_name, 0777);

            return $file_path.$file_name;
        } else{
            $dompdf->stream($file_name, array("Attachment" => false));

            exit(0);
        }
    }
}