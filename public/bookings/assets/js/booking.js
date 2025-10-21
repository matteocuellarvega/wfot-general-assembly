$(function(){
  // DOM element cache
  const $subtotal = $('#subtotal');
  const $paymentSection = $('#payment-section');
  const $payMethodSelect = $('#paymethod');
  const $confirmButton = $('#confirm');
  const $errorMessage = $('#error-message');
  const $dietInput = $('#diet');
  const $form = $('#booking-form');
  
  // State variables
  let currentTotal = 0;
  let formChanged = false;
  
  // Configuration
  const config = {
    maxDietaryLength: 500,
    notificationDuration: 2000,
    debounceWait: 100
  };
  
  // Get edit mode status from data passed from PHP
  const isEditMode = window.bookingFormData?.isEditMode || 
                     new URLSearchParams(window.location.search).get('edit') === 'true';

  // Helper functions
  function getUrlWithoutEditParam() {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.delete('edit');
    return currentUrl.toString();
  }
  
  function debounce(func, wait) {
    let timeout;
    return function() {
      const context = this, args = arguments;
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(context, args), wait);
    };
  }
  
  function showNotification(message, type) {
    $('<div>')
      .addClass(`notification ${type}`)
      .text(message)
      .appendTo('body')
      .delay(config.notificationDuration)
      .fadeOut(500, function() { $(this).remove(); });
  }

  function clearFormChangedFlag() {
    formChanged = false;
  }
  
  // Calculation function to update subtotal and UI state
  function calc() {
    let sum = 0;
    $('input[data-cost]:checked').each(function() { 
      sum += parseFloat(this.dataset.cost); 
    });
    
    currentTotal = sum;
    $subtotal.text(sum.toFixed(2));
    
    if (sum > 0) {
      $paymentSection.show();
    } else {
      $paymentSection.hide();
    }
  }
  
  // Initialize the page
  function init() {
    // Set payment method if provided
    if (window.bookingFormData?.selectedPayMethod) {
      $payMethodSelect.val(window.bookingFormData.selectedPayMethod);
    }
    
    // Initial calculation
    calc();
    
    // Setup event listeners
    setupEventListeners();
  }
  
  // Event listeners setup
  function setupEventListeners() {
    // Item selection events
    const debouncedCalc = debounce(calc, config.debounceWait);
    $('input[data-cost]')
      .off('change click')
      .on('change click', debouncedCalc);
    
    // Payment method change
    $payMethodSelect.on('change', handlePaymentMethodChange);
    
    // Form submission
    $form.on('submit', handleFormSubmit);
    
    // Track form changes
    $form.find('input, select, textarea').on('change', function() {
      formChanged = true;
    });
    
    // Warn before leaving with unsaved changes
    $(window).on('beforeunload', function() {
      if (formChanged) {
        return 'You have unsaved changes. Are you sure you want to leave?';
      }
    });
    
    // Improve tab navigation
    $form.find('input, select, textarea').attr('tabindex', function(index) {
      return index + 1;
    });
  }
  
  // Event handlers
  
  function handlePaymentMethodChange() {
    const method = $(this).val();
    if (method === 'Stripe') {
      $confirmButton.text('Pay with Stripe')
    } else {
      $confirmButton.text('Confirm booking');
    }
  }
  
    function handleFormSubmit(e) {
    e.preventDefault();
    
    // Validation
    if (!validateForm()) return;
    
    // Prepare UI for submission
    $confirmButton.prop('disabled', true)
                  .attr('aria-busy', 'true')
                  .text('Processing...');
    $errorMessage.text('');
    
    // Prepare data
    let data = $form.serialize();
    if (currentTotal === 0) {
      data = data.split('&').filter(param => !param.startsWith('paymethod=')).join('&');
    }
    
    // Submit form data
    submitBooking(data);
  }
  
  function validateForm() {
    // Check payment method if items are selected
    if (currentTotal > 0 && !$payMethodSelect.val()) {
      $errorMessage.text('Please select a payment method.');
      $confirmButton.prop('disabled', false).text('Confirm booking');
      return false;
    }
    
    // Check dietary requirements length
    const dietaryInput = $dietInput.val().trim();
    if (dietaryInput.length > config.maxDietaryLength) {
      $errorMessage.text(`Dietary requirements text is too long (maximum ${config.maxDietaryLength} characters).`);
      $confirmButton.prop('disabled', false).text('Confirm booking');
      return false;
    }
    
    return true;
  }
  
  // API interactions
  function submitBooking(data) {
    // Add CSRF token to data - read from form field instead of window object
    const csrfToken = $('input[name="csrf_token"]').val();
    if (csrfToken) {
      data += '&csrf_token=' + encodeURIComponent(csrfToken);
    }
    
    fetch('/bookings/save-booking.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: data
    })
    .then(r => r.json())
    .then(json => {
      if (json.error) {
        handleBookingError(json.error);
        return;
      }
      
      handleBookingSuccess(json);
    })
    .catch(error => {
      console.error('Fetch Error:', error);
      $errorMessage.text('A network error occurred. Please check your connection and try again.');
      resetConfirmButton();
    });
  }
  
  function resetConfirmButton() {
    $confirmButton.prop('disabled', false)
                 .removeAttr('aria-busy')
                 .text('Confirm booking')
                 .show();
  }
  
  function handleBookingError(error) {
    $errorMessage.text(error);
    resetConfirmButton();
  }
  
  function handleBookingSuccess(json) {
    if (json.payment === 'Cash' || json.payment === 'None') {
      handleCashPayment();
    } else if (json.payment === 'Stripe') {
      handleStripePayment(json);
    } else {
      $errorMessage.text('Unexpected response from server.');
      $confirmButton.prop('disabled', false).text('Confirm booking');
    }
  }
  
  function handleCashPayment() {
    clearFormChangedFlag();
    
    if (isEditMode) {
      const cleanUrl = getUrlWithoutEditParam();
      console.log("Redirecting to:", cleanUrl);
      window.location.href = cleanUrl;
    } else {
      window.location.reload();
    }
  }
  
  function handleStripePayment(json) {
    // Redirect to Stripe Checkout
    window.location.href = json.checkout_url;
  }
  
  function handleStripeError(err) {
    console.error('Stripe Error:', err);
    $errorMessage.text('An error occurred with Stripe. Please try selecting items again or choose another payment method.');
    resetConfirmButton();
  }
  
  // Initialize the page
  init();
});
