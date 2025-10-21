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

    public static function generateConfirmationFromData(array $booking, array $items, string $filePath): void
    {
        $html = self::generateConfirmationHtml($booking, $items);
        self::generateConfirmation($html, $filePath);
    }

    private static function generateConfirmationHtml(array $booking, array $items): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>WFOT General Assembly - Booking Confirmation</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #333; }
        .booking-info { margin: 20px 0; }
        .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .items-table th { background-color: #f2f2f2; }
        .text-right { text-align: right; }
        .no-border { border: none; }
    </style>
</head>
<body>
    <h2>Booking Confirmation</h2>
    
    <div class="booking-info">
        <p><strong>Booking ID:</strong> ' . htmlspecialchars($booking['id']) . '</p>
        <p><strong>Registration:</strong> ' . htmlspecialchars($booking['fields']['First Name'][0] ?? 'N/A') . ' ' . htmlspecialchars($booking['fields']['Last Name'][0] ?? 'N/A') . ' (Reg #: ' . htmlspecialchars($booking['fields']['Registration'][0] ?? 'N/A') . ')</p>
        <p><strong>Payment method:</strong> ' . htmlspecialchars($booking['fields']['Payment Method'] ?? 'N/A') . '</p>
        <p><strong>Payment status:</strong> ' . htmlspecialchars($booking['fields']['Payment Status'] ?? 'N/A') . '</p>';

        if (isset($booking['fields']['Payment Status']) && $booking['fields']['Payment Status'] === 'Paid') {
            if (isset($booking['fields']['Payment Reference'])) {
                $html .= '<p><strong>Payment Reference:</strong> ' . htmlspecialchars($booking['fields']['Payment Reference']) . '</p>';
            }
            if (isset($booking['fields']['Payment Date'])) {
                $date = new DateTime($booking['fields']['Payment Date']);
                $html .= '<p><strong>Payment Date:</strong> ' . $date->format('d M Y') . '</p>';
            }
        }

        $html .= '</div>
    
    <h4>Items</h4>
    <table class="items-table">
        <thead>
            <tr>
                <th>Item</th>
                <th style="width: 15%">Price (USD)</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($items as $bi) {
            $html .= '<tr>
                <td>' . htmlspecialchars($bi['fields']['Item'] ?? 'Unknown Item') . '</td>
                <td class="text-right">$' . number_format($bi['fields']['Item Total'] ?? 0, 2) . '</td>
            </tr>';
        }

        $html .= '</tbody>
        <tfoot>
            <tr>
                <td class="text-right">Sub Total</td>
                <td class="text-right">$' . number_format($booking['fields']['Subtotal'] ?? 0, 2) . '</td>
            </tr>
            <tr>
                <td class="text-right no-border">Total</td>
                <td class="text-right no-border">$' . number_format($booking['fields']['Total'] ?? 0, 2) . '</td>
            </tr>
        </tfoot>
    </table>
    
    <div class="confirmation-footer">
        <p>Please keep a copy of your confirmation. This confirmation is not proof of payment; if you paid via Stripe, refer to your Stripe receipt, and if you are paying in cash, you will receive a receipt upon payment.</p>
        <p>Thank you for your booking!</p>
    </div>
</body>
</html>';

        return $html;
    }
}
?>