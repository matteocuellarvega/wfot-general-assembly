$(function(){
  const $subtotal = $('#subtotal');
  const $paymentSection = $('#payment-section');
  const $payMethodSelect = $('#paymethod');
  const $confirmButton = $('#confirm');
  const $errorMessage = $('#error-message');
  const $paypalContainer = $('#paypal-button-container');
  let currentTotal = 0;
  let paypalButtonsInstance = null; // To keep track of rendered buttons

  $('input[data-cost]').on('change click', calc);

  function calc(){
    let sum = 0;
    $('input[data-cost]:checked').each(function(){ 
      sum += parseFloat(this.dataset.cost); 
    });
    currentTotal = sum;
    $subtotal.text(sum.toFixed(2));

    if (sum > 0) {
      $paymentSection.show();
      $confirmButton.show();
    } else {
      $paymentSection.hide();
      $paypalContainer.hide();
      $confirmButton.show();
    }
    if (paypalButtonsInstance) {
        paypalButtonsInstance.close();
        paypalButtonsInstance = null;
        $paypalContainer.empty();
    }
  }

  // Initial calculation on page load
  calc();

  $('#booking-form').on('submit', function(e){
    e.preventDefault();
    $confirmButton.prop('disabled', true).text('Processing...');
    $errorMessage.text('');
    $paypalContainer.hide();

    // If items are selected (total > 0), ensure a payment method is chosen.
    if (currentTotal > 0 && !$payMethodSelect.val()){
         $errorMessage.text('Please select a payment method.');
         $confirmButton.prop('disabled', false).text('Confirm booking');
         return;
    }
    
    let data = $(this).serialize();
    if (currentTotal === 0) {
        data = data.split('&').filter(param => !param.startsWith('paymethod=')).join('&');
    }

    fetch('/bookings/save-booking.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: data
    })
    .then(r=>r.json())
    .then(json=>{
      if(json.error){
         $errorMessage.text(json.error);
         $confirmButton.prop('disabled', false).text('Confirm booking');
         return;
      }

      if(json.payment==='Cash' || json.payment==='None'){
         window.location.reload();
      } else if(json.payment==='Paypal'){
         $confirmButton.hide();
         $paypalContainer.show();
         paypalButtonsInstance = paypal.Buttons({
             createOrder: () => json.orderID,
             onApprove: (data, actions) => {
                actions.disable();
                $paypalContainer.append('<p>Processing payment...</p>');
                return fetch('/bookings/paypal/capture-order.php',{
                   method:'POST',
                   headers:{'Content-Type':'application/json'},
                   body: JSON.stringify({orderID:data.orderID, booking_id:json.booking_id})
                })
                .then(r=>r.json())
                .then(res=>{
                   if(res.success){
                      window.location.reload();
                   } else {
                      $errorMessage.text(res.error || 'Payment capture failed. Please try again.');
                      actions.enable();
                      $paypalContainer.find('p').remove();
                   }
                })
                .catch(()=>{
                   $errorMessage.text('An error occurred while capturing the payment. Please try again.');
                   actions.enable();
                   $paypalContainer.find('p').remove();
                });
             },
             onError: (err) => {
                console.error('PayPal Button Error:', err);
                $errorMessage.text('An error occurred with PayPal. Please try selecting items again or choose another payment method.');
                $confirmButton.prop('disabled', false).text('Confirm booking').show();
                $paypalContainer.hide().empty();
                paypalButtonsInstance = null;
             },
             onCancel: () => {
                $errorMessage.text('PayPal payment cancelled.');
                $confirmButton.prop('disabled', false).text('Confirm booking').show();
                $paypalContainer.hide().empty();
                paypalButtonsInstance = null;
             }
         });
         paypalButtonsInstance.render('#paypal-button-container');
      } else {
         $errorMessage.text('Unexpected response from server.');
         $confirmButton.prop('disabled', false).text('Confirm booking');
      }
    })
    .catch(error=>{
      console.error('Fetch Error:', error);
      $errorMessage.text('An error occurred while saving the booking. Please try again.');
      $confirmButton.prop('disabled', false).text('Confirm booking');
    });
  });
});
