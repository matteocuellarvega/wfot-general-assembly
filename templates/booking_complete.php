<?php
/** @var array $booking @var array $items Array of booked item records */
?>
<h2>Booking confirmation</h2>
<p><strong>Booking ID:</strong> <?=htmlspecialchars($booking['id'])?></p>
<p><strong>Payment status:</strong> <?=htmlspecialchars($booking['fields']['Payment Status'] ?? 'N/A')?></p>

<h3>Items</h3><ul>
<?php foreach ($items as $bi): ?>
  <li><?=htmlspecialchars($bi['fields']['Item'] ?? 'Unknown Item')?> – $<?=number_format($bi['fields']['Item Total'] ?? 0, 2)?> USD</li>
<?php endforeach; ?>
</ul>
<p>Total: $<?=number_format($booking['fields']['Total'] ?? 0, 2)?> USD</p>
<p>Receipt has been emailed to <?=safe_email($booking['fields']['Email'][0] ?? '')?></p>
<p>Thank you for your booking!</p>