<?php
/** @var array $reg
 *  @var array $items
 *  @var string $bookingId
 */
// Ensure $selectedItems is defined even if empty
$selectedItems = $selectedItems ?? [];
?>
<article class="booking-form">
  <h2>Book items for <?=htmlspecialchars($reg['fields']['First Name'].' '.$reg['fields']['Last Name'])?></h2>

  <form method="post" id="booking-form">
    <input type="hidden" name="booking_id" value="<?=htmlspecialchars($bookingId)?>">
    <table role="grid" class="bookable-items-table">
      <thead><tr><th>Item</th><th>Type</th><th class="text-right">Cost</th><th class="text-right">Add to Booking</th></tr></thead>
      <tbody>
      <?php echo json_encode($items); ?>
      <?php foreach ($items as $row): $f = $row['fields']; ?>
        <tr>
          <td>
            <?= htmlspecialchars($f['Name']) ?>
            <?php if (!empty($f['More Information'])): ?>
              <a href="<?= htmlspecialchars($f['More Information']) ?>" data-tooltip="Click for details" target="_blank">Details</a>
            <?php endif; ?>
          </td>
          <td><?=htmlspecialchars($f['Type'])?></td>
          <td class="text-right">$<?=number_format($f['Cost'],2)?> USD</td>
          <td class="text-right">
            <input type="checkbox" data-cost="<?=$f['Cost']?>" name="item[]" value="<?=$row['id']?>"
            <?=in_array($row['id'], $selectedItems) ? 'checked' : '';?>>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <label for="diet">Dietary requirements</label>
    <textarea name="diet" id="diet" rows="3"><?= htmlspecialchars($booking['fields']['Dietary Requirements'] ?? '') ?></textarea>

    <div id="payment-section" style="display: none;">
        <label for="paymethod">Payment method</label>
        <select id="paymethod" name="paymethod">
          <option value="">Please choose</option>
          <option value="PayPal" <?= ($selectedPayMethod === 'PayPal') ? 'selected' : '' ?>>PayPal</option>
          <option value="Cash" <?= ($selectedPayMethod === 'Cash') ? 'selected' : '' ?>>Cash (pay on arrival)</option>
        </select>
    </div>

    <h3>Subtotal: $<span id="subtotal">0.00</span> USD</h3>
    <div id="paypal-button-container" style="margin-top: 10px;"></div>
    <div id="error-message" style="color: red; margin-top: 10px; margin-bottom: 10px;"></div>
    <button type="submit" id="confirm">Confirm booking</button>
  </form>
</article>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://www.paypal.com/sdk/js?client-id=<?=env('PAYPAL_CLIENT_ID')?>&currency=USD"></script>
<script>
  // Pass PHP variables to JavaScript
  const bookingFormData = {
    isEditMode: <?= json_encode(isset($_GET['edit']) && $_GET['edit'] === 'true') ?>,
    selectedPayMethod: <?= json_encode($selectedPayMethod ?? '') ?>
  };
</script>
<script src="/bookings/assets/js/booking.js"></script>
