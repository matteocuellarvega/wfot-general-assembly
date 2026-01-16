document.addEventListener("DOMContentLoaded", function () {
   const currentUser = WFOTCurrentUser;
   const targetElement = document.getElementById("gam-registration");
   const meetingName = "gam2026"; // Replace with the actual meeting name
   const allowBooking = true; // Set to true to test booking logic

   if (!currentUser || !targetElement) {
      console.error('Missing required data or elements.');
      if(targetElement) targetElement.innerHTML = '<p>Error: User data not found.</p>';
      return;
   }

   let checkUrl = `https://general-assembly.wfot.org/registration/?meeting=${meetingName}&response=json`;

   if (currentUser.role === "Council Meeting Observer" && currentUser.user_email) {
      checkUrl += `&observer=${encodeURIComponent(currentUser.user_email)}`;
   } else if (currentUser.airtable_id) {
      // Use the Airtable Record ID from the user object if available
      checkUrl += `&person=${currentUser.airtable_id}`;
   } else {
      targetElement.innerHTML = '<p>Your role or profile does not permit registration for this meeting. If you believe this to be an error, please email <a href="mailto:admin@wfot.org?subject=Council%20Meeting%20Registration">admin@wfot.org</a></p>';
      return;
   }

   // Show loading state
   const loadingMessage = "<i>Checking registration...</i>";
   targetElement.innerHTML = loadingMessage;

   console.log("Checking registration status:", checkUrl);

   // Main fetch logic
   fetch(checkUrl)
      .then(response => {
         if (!response.ok) {
            // Try to get error message from JSON response body
            return response.json().then(
               errData => {
                  throw new Error((errData && errData.error) || `Network response was not ok (${response.status})`);
               },
               () => {
                  // Fallback if response body is not JSON or empty
                  throw new Error(`Network response was not ok (${response.status})`);
               }
            );
         }
         return response.json();
      })
      .then(data => {
         console.log("Registration data received:", data);
         if (data.error) {
            targetElement.innerHTML = `<p>Error: ${data.error}</p>`;
            return;
         }

         // Process registration and get initial HTML (might include booking placeholder)
         const statusResult = handleRegistrationStatus(data, allowBooking, currentUser);
         targetElement.innerHTML = statusResult.html;

         // If a booking link is needed, fetch it asynchronously
         if (statusResult.bookingInfo?.needsLink && statusResult.bookingInfo.registrationId) {
            const bookingPlaceholder = targetElement.querySelector("#booking-link-placeholder"); // Find the placeholder div
            if (bookingPlaceholder) {
               getBookingLink(statusResult.bookingInfo.registrationId)
                  .then(bookingUrl => {
                     if (bookingUrl) {
                        // Append &edit=true if this is an existing booking
                        if (statusResult.bookingInfo.isEdit) {
                           bookingUrl += (bookingUrl.includes('?') ? '&' : '?') + 'edit=true';
                           bookingPlaceholder.innerHTML = `<a class="elementor-button elementor-size-lg" href="${bookingUrl}" target="_blank">Edit Booking</a>`;
                        } else {
                           bookingPlaceholder.innerHTML = `<a class="elementor-button elementor-size-lg" href="${bookingUrl}" target="_blank">Booking Form</a>`;
                        }
                     } else {
                        // Handle error fetching booking link
                        bookingPlaceholder.innerHTML = `<p><i>Could not retrieve booking/payment link. Please contact <a href="mailto:admin@wfot.org">admin@wfot.org</a>.</i></p>`;
                     }
                  });
            } else {
               console.error("Booking placeholder element not found in the generated HTML.");
            }
         } else if (statusResult.bookingInfo?.needsLink && !statusResult.bookingInfo.registrationId) {
             // Handle case where link is needed but registration ID is missing (shouldn't normally happen if registered)
             const bookingPlaceholder = targetElement.querySelector("#booking-link-placeholder");
             if (bookingPlaceholder) {
                 bookingPlaceholder.innerHTML = `<p><i>Error: Cannot generate booking link (missing registration ID). Please contact <a href="mailto:admin@wfot.org">admin@wfot.org</a>.</i></p>`;
             }
             console.error("Booking link needed but registration ID is missing in statusResult.");
         }

      })
      .catch(error => {
         // Handle network errors and other exceptions
         console.error('Fetch error:', error); // Log the error for debugging
         targetElement.innerHTML = `<p>Error retrieving registration status. Please try again later or contact <a href="mailto:admin@wfot.org">admin@wfot.org</a> if the problem persists. Details: ${error.message}</p>`;
      });

   // --- NEW: Function to get booking link from API ---
   async function getBookingLink(registrationId) {
      // Updated endpoint: use WordPress AJAX handler
      const apiUrl = '/wp-admin/admin-ajax.php?action=wfot_get_booking_link';

      try {
         console.log("Requesting booking link for:", registrationId);
         const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
               'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'registrationId=' + encodeURIComponent(registrationId)
         });

         if (!response.ok) {
            const errorData = await response.json();
            console.error("API Error Response:", errorData);
            throw new Error(`Failed to get booking link: ${response.status} ${errorData.error || response.statusText}`);
         }
         const data = await response.json();
         console.log("Received booking link:", data.bookingUrl);
         return data.bookingUrl;
      } catch (error) {
         console.error("Error fetching booking link:", error);
         return null;
      }
    }

   // Helper function for registration status
   // Returns an object: { html: "...", bookingInfo: { needsLink: boolean, registrationId: "...", isEdit: boolean } | null }
   function handleRegistrationStatus(data, allowBooking, currentUser) {
      let bookingInfo = null; // Initialize booking info

      if (!data.registered) {
         // Not registered at all
         return { html: `<p>Please complete the registration form to indicate whether you will or will not attend the 37th WFOT Meeting - General Assembly.</p><a class="elementor-button elementor-size-lg" href="${data.url}">Register Now</a>`, bookingInfo: null };
      }

      if (!data.registration?.completed) {
         // Started but not completed registration
         return { html: `<p>You have started your registration, but not completed it. Please submit your registration.</p> <a class="elementor-button elementor-size-lg" href="${data.formUrl}">Finish Registration</a>`, bookingInfo: null };
      }

      // User is registered and has completed the form
      let responseHtml = `<p>You have already registered. ${currentUser.primary_role === "Council Meeting Observer" ? 'If you need to make any changes to your registration, please contact <a href="mailto:admin@wfot.org">admin@wfot.org</a>' : `You can <a href="${data.formUrl}">edit your registration</a>.`}</p>`;
      

      if (allowBooking) {
         // Bookings are open, get booking status/options
         const bookingStatusResult = handleBookingStatus(data);
         responseHtml += bookingStatusResult.html; // Add the booking HTML (might contain placeholder)
         if (bookingStatusResult.needsLink) {
            bookingInfo = { 
               needsLink: true, 
               registrationId: bookingStatusResult.registrationId,
               isEdit: bookingStatusResult.isEdit
            };
         }
      } else {
         // Bookings are not open yet
         responseHtml += `<h3>Booking</h3><p>Bookings for lunches and social events will be available soon. Please check back later.</p>`;
      }
      return { html: responseHtml, bookingInfo: bookingInfo };
   }

   function handleBookingStatus(data) {
      const bookingPlaceholderId = "booking-link-placeholder"; // ID for the placeholder element

      // Check if booking data exists and has details
      if (!data.booking || !data.booking.id) {
         // No booking exists yet, need link to create one
         return {
            html: `<h3 class="booking-heading">Booking</h3><p>You may now book lunches and social events.</p><div id="${bookingPlaceholderId}"><i>Loading booking link...</i></div>`,
            needsLink: true,
            registrationId: data.registration?.id, // Pass registration ID needed for link generation
            isEdit: false
         };
      }

      // Booking exists, check its status
      const { status: bookingStatus, paymentStatus = 'Unknown', paymentMethod, confirmation: confirmationUrl = '#' } = data.booking;
      const registrationId = data.registration?.id;
      
      console.log(`Booking status: ${bookingStatus}, Payment status: ${paymentStatus}, Payment method: ${paymentMethod}`);

      // Handle Pending Bookings
      if (bookingStatus === 'Pending') {
         if (!paymentMethod) {
            return {
               html: `<h3 class="booking-heading">Booking</h3>
                      <p>You have a pending booking. Please use the link below to complete your booking.</p>
                      <div id="${bookingPlaceholderId}"><i>Loading booking link...</i></div>`,
               needsLink: true,
               registrationId: registrationId,
               isEdit: true
            };
         }

         if (paymentMethod === 'Stripe') {
            if (paymentStatus === 'Pending' || paymentStatus === 'Unpaid') {
               return {
                  html: `<h3 class="booking-heading">Booking</h3>
                         <p>You have started a booking but have not completed payment via Stripe. Please use the link below to complete your payment.</p>
                         <div id="${bookingPlaceholderId}"><i>Loading payment link...</i></div>`,
                  needsLink: true,
                  registrationId: registrationId,
                  isEdit: true
               };
            }
            // Unusual state for Stripe
            return {
               html: `<h3 class="booking-heading">Booking</h3>
                      <p>Your booking status is unusual (Booking: Pending, Payment: ${paymentStatus}, Method: Stripe). 
                      Please contact <a href="mailto:admin@wfot.org">admin@wfot.org</a> for assistance.</p>`,
               needsLink: false,
            };
         }
         if (paymentMethod === 'Cash') {
            return {
               html: `<h3 class="booking-heading">Booking</h3>
                      <p>You have a pending booking to be paid by cash. You can edit your booking if needed:</p>
                      <div id="${bookingPlaceholderId}"><i>Loading booking link...</i></div>
                      <p>Please remember to bring cash for payment during the event.</p>`,
               needsLink: true,
               registrationId: registrationId,
               isEdit: true
            };
         }
         // Pending with other/unknown payment method
         return {
            html: `<h3 class="booking-heading">Booking</h3>
                   <p>Your booking is pending with an unrecognized payment method. Please contact 
                   <a href="mailto:admin@wfot.org">admin@wfot.org</a> for assistance.</p>`,
            needsLink: false,
         };
      }
      
      // Handle Completed Bookings
      if (bookingStatus === 'Complete') {
         let message = '';
         let instructions = `If you need to make any changes to your booking, please contact <a href="mailto:admin@wfot.org">admin@wfot.org</a>.`;

         if (paymentStatus === 'Not Required') {
            message = 'Your booking is complete. No payment is required.';
         } else if (paymentMethod === 'Stripe' && paymentStatus === 'Paid') {
            message = 'Your booking is complete and has been paid via Stripe. Thank you!';
         } else if (paymentMethod === 'Cash' && paymentStatus === 'Paid') {
            message = 'Your booking is complete and has been paid by cash. Thank you!';
         } else if (paymentMethod === 'Cash' && paymentStatus === 'Pending') {
            message = 'Your booking is complete and will be paid by cash at the venue.';
            instructions = 'Please remember to bring cash for payment.';
         } else {
            // Fallback for unexpected completed states
            message = `Your booking is complete, the payment status is ${paymentStatus}.`;
            instructions = `If you have any questions, please contact <a href="mailto:admin@wfot.org">admin@wfot.org</a>.`;
         }

         return {
            html: `<h3 class="booking-heading">Booking</h3>
                   <p>${message}</p>
                   <p>You can <a href="${confirmationUrl}" target="_blank">view your booking confirmation</a>.</p>
                   <p>${instructions}</p>`,
            needsLink: false,
         };
      }

      if (bookingStatus === 'Cancelled') {
         return {
            html: `<h3 class="booking-heading">Booking</h3>
                   <p>Your booking has been cancelled. If you believe this is an error, please contact <a href="mailto:admin@wfot.org">admin@wfot.org</a>.</p>`,
            needsLink: false,
         };
      }

      // Handle Unknown booking status
      return {
         html: `<h3 class="booking-heading">Booking</h3>
                <p>Your booking has an unusual status (${bookingStatus}). Please contact 
                <a href="mailto:admin@wfot.org">admin@wfot.org</a> for assistance.</p>`,
         needsLink: false,
      };
   }
});