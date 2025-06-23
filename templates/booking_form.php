<?php
/** @var array $reg
 *  @var array $items
 *  @var string $bookingId
 */
// Ensure $selectedItems is defined even if empty
$selectedItems = $selectedItems ?? [];
?>
<h2>Book items for <?=htmlspecialchars($reg['fields']['First Name'].' '.$reg['fields']['Last Name'])?></h2>

<form method="post" id="booking-form">
  <input type="hidden" name="booking_id" value="<?=htmlspecialchars($bookingId)?>">
  <table role="grid">
    <thead><tr><th>Item</th><th>Type</th><th>Cost</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $row): $f = $row['fields']; ?>
      <tr>
        <td><?=htmlspecialchars($f['Name'])?></td>
        <td><?=htmlspecialchars($f['Type'])?></td>
        <td>$<?=number_format($f['Cost'],2)?> USD</td>
        <td>
          <input type="checkbox" data-cost="<?=$f['Cost']?>" name="item[]" value="<?=$row['id']?>"
          <?=in_array($row['id'], $selectedItems) ? 'checked' : '';?>>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <label for="diet">Dietary requirements</label>
  <textarea name="diet" id="diet" rows="3"></textarea>

  <div id="payment-section" style="display: none;">
      <label for="paymethod">Payment method</label>
      <select id="paymethod" name="paymethod">
        <option value="">Please choose</option>
        <option value="PayPal">PayPal</option>
        <option value="Cash">Cash (pay on arrival)</option>
      </select>
  </div>

  <h3>Subtotal: $<span id="subtotal">0.00</span> USD</h3>
  <div id="paypal-button-container" style="margin-top: 10px;"></div>
  <div id="error-message" style="color: red; margin-top: 10px; margin-bottom: 10px;"></div>
  <button type="submit" id="confirm">Confirm booking</button>
</form>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://www.paypal.com/sdk/js?client-id=<?=env('PAYPAL_CLIENT_ID')?>&currency=USD"></script>
<script src="/bookings/assets/js/booking.js"></script>
