<?php
namespace WFOT\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfService
{
    public static function generateConfirmation(string $html, string $filePath): void
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('chroot', dirname(__DIR__, 2) . '/public'); // Set the chroot to the public directory
        $options->set('defaultMediaType', 'print'); 

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($filePath, $dompdf->output());
    }
}
?>