<?php
namespace WFOT\Services;

use Dompdf\Dompdf;

class PdfService
{
    public static function generateReceipt(array $booking, array $items, array $reg, string $qrCodeDataUri, string $filePath): void
    {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Booking Receipt</title>
            <style>
                body { font-family: sans-serif; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #dddddd; text-align: left; padding: 8px; }
                th { background-color: #f2f2f2; }
                .total-row td { font-weight: bold; }
            </style>
        </head>
        <body>
            <h1>Booking Receipt</h1>
            
            <img src="<?= $qrCodeDataUri ?>" alt="Booking QR Code" style="width: 150px; height: 150px;">

            <h2>Booking Details</h2>
            <p><strong>Booking ID:</strong> <?= htmlspecialchars($booking['id']) ?></p>
            <p><strong>Payment Status:</strong> <?= htmlspecialchars($booking['fields']['Payment Status'] ?? 'N/A') ?></p>
            <p><strong>Payment Method:</strong> <?= htmlspecialchars($booking['fields']['Payment Method'] ?? 'N/A') ?></p>
            
            <h2>Registration Details</h2>
            <p><strong>Name:</strong> <?= htmlspecialchars($reg['fields']['Name'] ?? 'N/A') ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($booking['fields']['Email'][0] ?? 'N/A') ?></p>
            <p><strong>Role:</strong> <?= htmlspecialchars($reg['fields']['Role'] ?? 'N/A') ?></p>

            <h2>Items</h2>
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Price (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['fields']['Item'] ?? 'Unknown Item') ?></td>
                        <td>$<?= number_format($it['fields']['Item Total'] ?? 0, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td>Total</td>
                        <td>$<?= number_format($booking['fields']['Total'] ?? 0, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </body>
        </html>
        <?php
        $html = ob_get_clean();

        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($filePath, $dompdf->output());
    }
}
?>
