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
        $dompdf->set_option('isHtml5ParserEnabled', true);
        $dompdf->load_html($html);
        $dompdf->set_paper("A4", "portrait");
        $dompdf->render();

        $dompdf->stream($file_name, array("Attachment" => false));

        exit(0);
    }
}