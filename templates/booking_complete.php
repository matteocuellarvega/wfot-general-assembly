<?php
/** @var array $booking @var array $items Array of booked item records */
?>
<h2>Booking confirmation</h2>

<article>
  <div class="booking-info" style="display: flex;">
    <div class="booking-details">
      <h4>Booking details</h4>
      <p><strong>Booking ID:</strong> <?=htmlspecialchars($booking['id'])?></p>
      <p><strong>Payment method:</strong> <?=htmlspecialchars($booking['fields']['Payment Method'] ?? 'N/A')?></p>
      <p><strong>Payment status:</strong> <?=htmlspecialchars($booking['fields']['Payment Status'] ?? 'N/A')?></p>
    </div>
    <div class="booking-qr-code">
      <img src="<?= $qrCodeDataUri ?? '' ?>" alt="Booking QR Code" style="max-width: 180px;">
    </div>
  </div>
</article>

<article>
  <h4>Items</h4>
  <table class="items-table">
    <thead>
      <tr>
        <th>Item</th>
        <th>Price (USD)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $bi): ?>
        <tr>
          <td><?=htmlspecialchars($bi['fields']['Item'] ?? 'Unknown Item')?></td>
          <td>$<?=number_format($bi['fields']['Item Total'] ?? 0, 2)?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td><strong>Total</strong></td>
        <td><strong>$<?=number_format($booking['fields']['Total'] ?? 0, 2)?></strong></td>
      </tr>
    </tfoot>
  </table>
</article>

<div class="receipt-footer no-print">
  <p>A receipt has been emailed to <?=safe_email($booking['fields']['Email'][0] ?? '')?>. If you do not receive it, please check your spam folder or contact us for assistance.</p>
  <p>Thank you for your booking!</p>
</div>

<div class="receipt-footer only-print">
  <p>Please keep a copy of your receipt.</p>
  <p>Thank you for your booking!</p>
</div>