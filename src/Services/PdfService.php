<?php
namespace WFOT\Services;

use Dompdf\Dompdf;

class PdfService
{
    public static function generateReceipt(array $booking, array $items, string $filePath): void
    {
        $html = '<h1>Booking Receipt</h1>';
        $html .= '<p>Booking ID: '.$booking['id'].'</p>';
        $html .= '<table width="100%" border="1" cellspacing="0" cellpadding="5"><tr><th>Item</th><th>Type</th><th>Cost</th></tr>';
        foreach ($items as $it) {
            $html .= '<tr><td>'.$it['fields']['Item'].'</td><td>'.$it['fields']['Type'].'</td><td>£'.number_format($it['fields']['Item Total'],2).'</td></tr>';
        }
        $html .= '</table>';
        $html .= '<p>Total: £'.number_format($booking['fields']['Total'],2).'</p>';

        $dompdf = new Dompdf(['isRemoteEnabled'=>false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        file_put_contents($filePath, $dompdf->output());
    }
}
?>
