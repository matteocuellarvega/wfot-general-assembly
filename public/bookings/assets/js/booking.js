$(function(){
  const $subtotal = $('#subtotal');
  const $paymentSection = $('#payment-section');
  const $payMethodSelect = $('#paymethod');
  const $confirmButton = $('#confirm');
  const $errorMessage = $('#error-message');
  const $paypalContainer = $('#paypal-button-container');
  let currentTotal = 0;
  let paypalButtonsInstance = null; // To keep track of rendered buttons

  // Enhanced function to get URL without edit parameter but preserve all other parameters
  function getUrlWithoutEditParam() {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.delete('edit');
    return currentUrl.toString();
  }

  // Check if we're in edit mode - using the variable passed from PHP
  const isEditMode = window.bookingFormData ? window.bookingFormData.isEditMode : 
                    (new URLSearchParams(window.location.search).get('edit') === 'true');

  // Set previously selected payment method if it exists - using the variable passed from PHP
  if (window.bookingFormData && window.bookingFormData.selectedPayMethod) {
    $payMethodSelect.val(window.bookingFormData.selectedPayMethod);
  }

  // The dietary requirements are now directly set in the textarea in the HTML

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

  // Add feedback for selection changes
  $('input[data-cost]').on('change', function() {
    const itemName = $(this).closest('tr').find('td:first').text();
    if (this.checked) {
      $('<div class="notification success">')
        .text(`Added ${itemName}`)
        .appendTo('body')
        .delay(2000)
        .fadeOut(500, function() { $(this).remove(); });
    } else {
      $('<div class="notification warning">')
        .text(`Removed ${itemName}`)
        .appendTo('body')
        .delay(2000)
        .fadeOut(500, function() { $(this).remove(); });
    }
  });

  // Debounce function to prevent rapid calculations when clicking multiple items quickly
  function debounce(func, wait) {
    let timeout;
    return function() {
      const context = this, args = arguments;
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(context, args), wait);
    };
  }

  // Replace direct calc binding with debounced version
  const debouncedCalc = debounce(calc, 100);
  $('input[data-cost]').off('change click').on('change click', debouncedCalc);

  // Add payment method change handler
  $payMethodSelect.on('change', function() {
    const method = $(this).val();
    if (method === 'PayPal') {
      $('<div class="notification info">')
        .text('You will see PayPal payment options after confirming your selections.')
        .insertAfter($payMethodSelect)
        .delay(5000)
        .fadeOut(500, function() { $(this).remove(); });
    }
  });

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
    
    // Add form validation before submission
    const dietaryInput = $('#diet').val().trim();
    if (dietaryInput.length > 500) { // Add character limit check
      $errorMessage.text('Dietary requirements text is too long (maximum 500 characters).');
      $confirmButton.prop('disabled', false).text('Confirm booking');
      return;
    }

    let data = $(this).serialize();
    if (currentTotal === 0) {
        data = data.split('&').filter(param => !param.startsWith('paymethod=')).join('&');
    }

    // Display a loading indicator
    const $loadingIndicator = $('<div class="loading-spinner">Processing...</div>');
    $loadingIndicator.insertAfter($confirmButton);

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
         // Clear the form changed flag before redirecting
         clearFormChangedFlag();
         
         // Always use the URL without edit parameter for redirections
         if (isEditMode) {
           const cleanUrl = getUrlWithoutEditParam();
           console.log("Redirecting to:", cleanUrl); // Debug log
           window.location.href = cleanUrl;
         } else {
           window.location.reload();
         }
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
                      // Clear the form changed flag
                      clearFormChangedFlag();
                      
                      // Always use the URL without edit parameter for redirections
                      if (isEditMode) {
                        const cleanUrl = getUrlWithoutEditParam();
                        console.log("Redirecting to:", cleanUrl); // Debug log
                        window.location.href = cleanUrl;
                      } else {
                        window.location.reload();
                      }
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
      $errorMessage.text('A network error occurred. Please check your connection and try again.');
      $confirmButton.prop('disabled', false).text('Confirm booking');
      $loadingIndicator.remove();
    });
  });
  
  // Add warning before leaving page with unsaved changes
  let formChanged = false;
  
  $('#booking-form input, #booking-form select, #booking-form textarea').on('change', function() {
    formChanged = true;
  });
  
  $(window).on('beforeunload', function() {
    if (formChanged) {
      return 'You have unsaved changes. Are you sure you want to leave?';
    }
  });
  
  // Clear the beforeunload warning when form is successfully submitted
  function clearFormChangedFlag() {
    formChanged = false;
  }
  
  // Call this after successful submission
  // Modify the success handlers to clear the flag before redirecting
  
  // Add tab key navigation improvement for form elements
  $('#booking-form input, #booking-form select, #booking-form textarea')
    .attr('tabindex', function(index) {
      return index + 1;
    });
    
  // Add simple CSS for notifications - will be added via JavaScript
  const style = `
    <style>
      .notification {
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 10px 15px;
        border-radius: 4px;
        color: white;
        z-index: 1000;
        animation: fadeIn 0.5s;
      }
      .notification.success { background-color: #4CAF50; }
      .notification.warning { background-color: #FF9800; }
      .notification.info { background-color: #2196F3; }
      .notification.error { background-color: #F44336; }
      .loading-spinner {
        display: inline-block;
        margin-left: 10px;
        animation: spin 1s infinite linear;
      }
      @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }
      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
    </style>
  `;
  $('head').append(style);
});
