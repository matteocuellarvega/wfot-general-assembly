<?php
namespace WFOT\Services;

use Dompdf\Dompdf;

class PdfService
{
    public static function generateReceipt(string $html, string $filePath): void
    {
        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($filePath, $dompdf->output());
    }
}
?>