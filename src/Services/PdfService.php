<?php
namespace WFOT\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfService
{
    public static function generateReceipt(string $html, string $filePath): void
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        // Set the base path to the webroot for resolving relative paths
        $options->set('chroot', '/var/www/general-assembly/public');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($filePath, $dompdf->output());
    }
}
?>