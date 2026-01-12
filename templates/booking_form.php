<?php
/** @var array $reg
 *  @var array $items
 *  @var string $bookingId
 */
// Ensure $selectedItems is defined even if empty
$selectedItems = $selectedItems ?? [];
?>
<article class="booking-form">
  <h2><?=htmlspecialchars($reg['fields']['Meeting'])?> Booking Form</h2>
  <p>Booking for: <?=htmlspecialchars(($reg['fields']['First Name'] ?? '') . ' ' . ($reg['fields']['Last Name'] ?? '') . ' (' . ($reg['fields']['Email'] ?? '') . ')')?></p>
  <form method="post" id="booking-form">
    <input type="hidden" name="booking_id" value="<?=htmlspecialchars($bookingId)?>">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(generateCsrfToken())?>">
    <table role="grid" class="bookable-items-table">
      <thead><tr><th>Item</th><th>Type</th><th class="text-right">Cost</th><th class="text-right">Add to Booking</th></tr></thead>
      <tbody>
      <?php foreach ($items as $row): $f = $row['fields']; ?>
        <tr>
          <td>
            <?= htmlspecialchars($f['Name']) ?>
            <?php if (!empty($f['More Information'])): ?>
              <a href="<?= htmlspecialchars($f['More Information']) ?>" data-tooltip="Click for details" target="_blank"><div class="info-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path d="M12 0C5.372 0 0 5.373 0 12s5.373 12 12 12 12-5.374 12-12S18.626 0 12 0zm2.498 18.598a39.58 39.58 0 0 1-1.479.556 3.9 3.9 0 0 1-1.282.192c-.747 0-1.33-.183-1.744-.547s-.62-.827-.62-1.389c0-.218.015-.442.045-.67a8.36 8.36 0 0 1 .15-.77l.773-2.731c.068-.262.127-.511.173-.743.047-.233.07-.448.07-.643 0-.347-.073-.591-.216-.728-.145-.137-.419-.204-.826-.204-.199 0-.404.03-.615.091-.208.064-.389.122-.537.179l.204-.841c.506-.207.99-.383 1.453-.53a4.292 4.292 0 0 1 1.31-.221c.743 0 1.316.18 1.72.538.4.359.603.825.603 1.398 0 .12-.014.328-.042.627-.027.3-.08.573-.154.824l-.77 2.722a7.703 7.703 0 0 0-.169.748c-.05.28-.074.493-.074.636 0 .362.08.609.243.74.16.13.442.197.84.197.188 0 .398-.034.636-.099.235-.065.406-.123.514-.173l-.206.84zM14.36 7.547c-.358.333-.79.5-1.295.5-.504 0-.939-.167-1.3-.5a1.596 1.596 0 0 1-.542-1.212c0-.472.183-.879.542-1.215a1.841 1.841 0 0 1 1.3-.505c.505 0 .938.168 1.295.505.359.336.539.743.539 1.215 0 .474-.18.879-.539 1.212z" fill="#45bea3"/></svg></div></a>
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
    <textarea name="diet" id="diet" rows="3" placeholder="Please advise us of any dietary requirements (optional)"><?= htmlspecialchars($booking['fields']['Dietary Requirements'] ?? '') ?></textarea>

    <div id="payment-section" style="display: none;">
        <label for="paymethod">Payment method</label>
        <select id="paymethod" name="paymethod">
          <option value="">Please choose</option>
          <option value="Stripe" <?= ($selectedPayMethod === 'Stripe') ? 'selected' : '' ?>>Credit/Debit Card</option>
          <option value="Cash" <?= ($selectedPayMethod === 'Cash') ? 'selected' : '' ?>>Cash (Pay on arrival)</option>
        </select>
    </div>

    <div class="cash-notice" style="display: none; margin-top: 10px; margin-bottom: 10px;">
      <strong>Note:</strong> We can only accept cash payments in USD at the event. Please ensure you bring the exact amount as we may not have change available.
    </div>

    <h3>Subtotal: $<span id="subtotal">0.00</span> USD</h3>
    <div id="error-message" style="color: red; margin-top: 10px; margin-bottom: 10px;"></div>
    <button type="submit" id="confirm"><?= (isset($_GET['edit']) && $_GET['edit'] === 'true') ? 'Update Booking' : 'Confirm booking' ?></button>
  </form>
</article>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
// Pass PHP variables to JavaScript
const bookingFormData = {
  isEditMode: <?= json_encode(isset($_GET['edit']) && $_GET['edit'] === 'true') ?>,
  selectedPayMethod: <?= json_encode($selectedPayMethod ?? '') ?>
};
</script>
<script src="/bookings/assets/js/booking.js"></script>
