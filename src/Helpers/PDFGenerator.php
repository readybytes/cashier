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
    public static function generatePDF($html, $file_name)
    {
        $dompdf = new \DOMPDF();
        $dompdf->load_html($html);
        $dompdf->set_option("enable_html5_parser", true);
        $dompdf->set_option("enable_remote", true);
        $dompdf->set_paper("A4", "portrait");
        $dompdf->render();

        $dompdf->stream($file_name, array("Attachment" => false));

        exit(0);
    }
}