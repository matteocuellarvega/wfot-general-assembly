<?php
/** @var array $booking @var array $items Array of booked item records */
?>
<h2>Booking confirmation</h2>

<article>
  <h3>Booking details</h3>
  <p><strong>Booking ID:</strong> <?=htmlspecialchars($booking['id'])?></p>
  <p><strong>Payment method:</strong> <?=htmlspecialchars($booking['fields']['Payment Method'] ?? 'N/A')?></p>
  <p><strong>Payment status:</strong> <?=htmlspecialchars($booking['fields']['Payment Status'] ?? 'N/A')?></p>
</article>

<article>
  <h3>Items</h3>
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

<article>
  <h3>Your QR Code</h3>
  <p>Present this QR code for check-in.</p>
  <img src="<?= $qrCodeDataUri ?? '' ?>" alt="Booking QR Code" style="max-width: 200px;">
</article>

<p>A receipt has been emailed to 
<?php if ($validToken ?? false): ?>
  <?=htmlspecialchars($booking['fields']['Email'][0] ?? 'N/A')?>
<?php else: ?>
  <?=safe_email($booking['fields']['Email'][0] ?? '')?>
<?php endif; ?>
</p>
<p>Thank you for your booking!</p>