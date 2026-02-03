<?php
/** @var array $booking @var array $items Array of booked item records */
?>
<div class="heading">
  <h2>
    <?php if (($booking['fields']['Payment Status'] ?? '') === 'Paid' && ($booking['fields']['Payment Method'] ?? '') === 'Cash'): ?>
      Booking receipt
    <?php else: ?>
      Booking confirmation
    <?php endif; ?>
  </h2>
  <button onclick="window.print()" class="print-button no-print">Print/Save</button>
</div>

<article class="booking-info-container">
  <div class="booking-info">
    <div class="booking-details">
      <h4>Booking details</h4>
      <p><strong>Booking ID:</strong> <?=htmlspecialchars($booking['id'])?></p>
      <p><strong>Registration:</strong> <?=htmlspecialchars($booking['fields']['First Name'][0] ?? 'N/A')?> <?=htmlspecialchars($booking['fields']['Last Name'][0] ?? 'N/A')?> (Reg #: <?=htmlspecialchars($booking['fields']['Registration'][0] ?? 'N/A')?>)</p>
      <p><strong>Payment method:</strong> <?=htmlspecialchars($booking['fields']['Payment Method'] ?? 'N/A')?></p>
      <p><strong>Payment status:</strong> <?=htmlspecialchars($booking['fields']['Payment Status'] ?? 'N/A')?></p>

      <?php if (isset($booking['fields']['Payment Status']) && $booking['fields']['Payment Status'] === 'Paid'): ?>
        <?php if (isset($booking['fields']['Payment Reference'])): ?>
          <p><strong>Payment Reference:</strong> <?= htmlspecialchars($booking['fields']['Payment Reference']) ?></p>
        <?php endif; ?>

        <?php if (isset($booking['fields']['Payment Date'])): ?>
          <p><strong>Payment Date:</strong>
            <?php 
              $date = new DateTime($booking['fields']['Payment Date']);
              echo $date->format('d M Y');
            ?>
          </p>
        <?php endif; ?>
      <?php endif; ?>

    </div>
    <div class="booking-qr-code">
      <img src="<?= $qrCodeDataUri ?? '' ?>" alt="Booking ID: <?=htmlspecialchars($booking['id'])?>" style="max-width: 160px;">
    </div>
  </div>
</article>

<article class="items-table-container">
  <h4>Items</h4>
  <table class="items-table">
    <thead>
      <tr>
        <th>Item</th>
        <th style="width: 15%">Price (USD)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $bi): ?>
        <tr>
          <td><?=htmlspecialchars($bi['fields']['Item'] ?? 'Unknown Item')?></td>
          <td class="text-right">$<?=number_format($bi['fields']['Item Total'] ?? 0, 2)?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="text-right">Sub Total</td>
        <td class="text-right">$<?=number_format($booking['fields']['Subtotal'] ?? 0, 2)?></td>
      </tr>
      <tr>
        <td class="text-right no-border">Total</td>
        <td class="text-right no-border">$<?=number_format($booking['fields']['Total'] ?? 0, 2)?></td>
      </tr>
    </tfoot>
  </table>
</article>

<div class="confirmation-footer no-print">
  <p>A copy of this confirmation has been emailed to <?=safe_email($booking['fields']['Email'][0] ?? '')?>. If you do not receive it, please check your spam folder or contact us for assistance.</p>
  <?php if (($booking['fields']['Payment Method'] ?? '') === 'Cash' && ($booking['fields']['Payment Status'] ?? '') !== 'Paid'): ?>
    <p>Please note that we can only accept cash payments in USD at the event. Please ensure you bring the exact amount as we may not have change available.</p>
  <?php endif; ?>
  <p>Thank you for your booking!</p>
</div>

<div class="confirmation-footer only-print">
  <?php if (($booking['fields']['Payment Method'] ?? '') === 'Cash' && ($booking['fields']['Payment Status'] ?? '') !== 'Paid'): ?>
    <p>Please note that we can only accept cash payments in USD at the event. Please ensure you bring the exact amount as we may not have change available.</p>
  <?php endif; ?>
  <p>Thank you for your booking!</p>
</div>