<?php
namespace WFOT\Services;

use PHPMailer\PHPMailer\PHPMailer;

class EmailService
{
    private static function logDebug(string $message): void {
        if (env('DEBUG') === true || strtolower((string) env('DEBUG')) === 'true') {
            error_log("EmailService DEBUG: " . $message);
        }
    }

    public static function sendReceipt(string $to, string $name, string $pdfPath): bool
    {
        self::logDebug("sendReceipt called for to: $to, name: $name, pdfPath: $pdfPath");
        $mail = new PHPMailer(true);
        $mail->CharSet = "UTF-8";
        $mail->isSMTP();
        $mail->Host = env('SMTP_HOST');
        $mail->Port = 587; // TLS only
        $mail->SMTPSecure = 'tls'; // ssl is depracated
        $mail->SMTPAuth = true;
        $mail->Username = env('SMTP_USERNAME');
        $mail->Password = env('SMTP_PASSWORD');
        $mail->addReplyTo('admin@wfot.org', 'World Federation of Occupational Therapists');
        $mail->setFrom(env('MAIL_FROM'), env('MAIL_FROM_NAME','WFOT'));
        $mail->addAddress($to, $name);
        if($bcc = env('MAIL_BCC_ADMIN')) $mail->addBCC($bcc);
        $mail->Subject = 'WFOT General Assembly - Booking Confirmation';
        
        // Create a more detailed email body
        $mail->isHTML(true);
        $mail->Body = self::generateReceiptEmailBody($name);
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", self::generateReceiptEmailBody($name)));
        
        if (file_exists($pdfPath)) {
            $mail->addAttachment($pdfPath, 'WFOT_booking_confirmation.pdf');
        } else {
            self::logDebug("Warning: PDF file not found at path: $pdfPath");
        }
        
        self::logDebug("PHPMailer configured, attempting to send receipt.");
        $result = $mail->send();
        self::logDebug("sendReceipt result: " . ($result ? "Success" : "Failure"));
        return $result;
    }
    
    /**
     * Send a booking receipt with booking details and a link to view the receipt online
     * 
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $pdfPath Path to the PDF file
     * @param array $booking Booking data
     * @param string $receiptUrl URL to view the receipt online
     * @return bool Success status
     */
    public static function sendBookingReceipt(string $to, string $name, string $pdfPath, array $booking, string $receiptUrl): bool
    {
        self::logDebug("sendBookingReceipt called for to: $to, name: $name");
        
        $mail = new PHPMailer(true);
        $mail->CharSet = "UTF-8";
        $mail->isSMTP();
        $mail->Host = env('SMTP_HOST');
        $mail->Port = 587;
        $mail->SMTPSecure = 'tls';
        $mail->SMTPAuth = true;
        $mail->Username = env('SMTP_USERNAME');
        $mail->Password = env('SMTP_PASSWORD');
        $mail->addReplyTo('admin@wfot.org', 'World Federation of Occupational Therapists');
        $mail->setFrom(env('MAIL_FROM'), env('MAIL_FROM_NAME','WFOT'));
        $mail->addAddress($to, $name);
        if($bcc = env('MAIL_BCC_ADMIN')) $mail->addBCC($bcc);
        
        // More descriptive subject
        $mail->Subject = 'WFOT General Assembly - Booking Confirmation #' . substr($booking['id'] ?? '', -8);
        
        // Create a more detailed email body with booking details
        $mail->isHTML(true);
        $mail->Body = self::generateBookingReceiptEmailBody($name, $booking, $receiptUrl);
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", self::generateBookingReceiptEmailBody($name, $booking, $receiptUrl, false)));
        
        if (file_exists($pdfPath)) {
            $mail->addAttachment($pdfPath, 'WFOT_booking_confirmation.pdf');
        } else {
            self::logDebug("Warning: PDF file not found at path: $pdfPath");
        }
        
        self::logDebug("PHPMailer configured, attempting to send booking receipt.");
        $result = $mail->send();
        self::logDebug("sendBookingReceipt result: " . ($result ? "Success" : "Failure"));
        return $result;
    }
    
    /**
     * Generate the HTML body for the basic receipt email
     * 
     * @param string $name Recipient name
     * @return string Email HTML body
     */
    private static function generateReceiptEmailBody(string $name): string
    {
        return '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="text-align: center; padding: 20px 0;">
                <img src="' . rtrim(env('APP_URL'), '/') . '/assets/img/logo.png" alt="WFOT Logo" style="max-width: 200px;">
            </div>
            <div style="padding: 20px; border-top: 3px solid #005691; background-color: #f9f9f9;">
                <h2 style="color: #005691;">Booking Confirmation</h2>
                <p>Dear ' . htmlspecialchars($name) . ',</p>
                <p>Thank you for your booking for the WFOT General Assembly. Please find your booking confirmation attached to this email.</p>
                <p>If you have any questions or need assistance, please contact us at <a href="mailto:admin@wfot.org">admin@wfot.org</a>.</p>
                <p>Best regards,<br>WFOT Team</p>
            </div>
            <div style="padding: 10px; background-color: #005691; color: white; text-align: center; font-size: 12px;">
                &copy; ' . date('Y') . ' World Federation of Occupational Therapists
            </div>
        </div>';
    }
    
    /**
     * Generate the HTML body for the booking receipt email with details
     * 
     * @param string $name Recipient name
     * @param array $booking Booking data
     * @param string $receiptUrl URL to view the receipt online
     * @param bool $isHtml Whether to generate HTML (true) or plain text (false)
     * @return string Email body
     */
    private static function generateBookingReceiptEmailBody(string $name, array $booking, string $receiptUrl, bool $isHtml = true): string
    {
        $paymentMethod = $booking['fields']['Payment Method'] ?? 'N/A';
        $paymentStatus = $booking['fields']['Payment Status'] ?? 'N/A';
        $total = number_format($booking['fields']['Total'] ?? 0, 2);
        $bookingId = $booking['id'] ?? 'Unknown';
        
        if ($isHtml) {
            return '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <div style="text-align: center; padding: 20px 0;">
                    <img src="' . rtrim(env('APP_URL'), '/') . '/assets/img/logo.png" alt="WFOT Logo" style="max-width: 200px;">
                </div>
                <div style="padding: 20px; border-top: 3px solid #005691; background-color: #f9f9f9;">
                    <h2 style="color: #005691;">Booking Confirmation</h2>
                    <p>Dear ' . htmlspecialchars($name) . ',</p>
                    <p>Thank you for your booking for the WFOT General Assembly.</p>
                    
                    <div style="background-color: #ffffff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px;">
                        <h3 style="color: #005691; margin-top: 0;">Booking Details</h3>
                        <p><strong>Booking ID:</strong> ' . htmlspecialchars($bookingId) . '</p>
                        <p><strong>Payment Method:</strong> ' . htmlspecialchars($paymentMethod) . '</p>
                        <p><strong>Payment Status:</strong> ' . htmlspecialchars($paymentStatus) . '</p>
                        <p><strong>Total:</strong> $' . $total . ' USD</p>
                    </div>
                    
                    <p>Please find your booking confirmation attached to this email. You can also view your receipt online by clicking the button below:</p>
                    
                    <div style="text-align: center; margin: 25px 0;">
                        <a href="' . $receiptUrl . '" style="background-color: #005691; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;">View Receipt</a>
                    </div>
                    
                    <p>' . ($paymentMethod === 'Cash' ? '<strong>Note:</strong> As you selected Cash as your payment method, please bring the exact amount in USD to complete your payment upon arrival.' : '') . '</p>
                    
                    <p>If you have any questions or need assistance, please contact us at <a href="mailto:admin@wfot.org">admin@wfot.org</a>.</p>
                    <p>Best regards,<br>WFOT Team</p>
                </div>
                <div style="padding: 10px; background-color: #005691; color: white; text-align: center; font-size: 12px;">
                    &copy; ' . date('Y') . ' World Federation of Occupational Therapists
                </div>
            </div>';
        } else {
            // Plain text version
            return "WFOT General Assembly - Booking Confirmation\n\n" .
                "Dear " . $name . ",\n\n" .
                "Thank you for your booking for the WFOT General Assembly.\n\n" .
                "Booking Details:\n" .
                "Booking ID: " . $bookingId . "\n" .
                "Payment Method: " . $paymentMethod . "\n" .
                "Payment Status: " . $paymentStatus . "\n" .
                "Total: $" . $total . " USD\n\n" .
                "Please find your booking confirmation attached to this email. You can also view your receipt online at:\n" . $receiptUrl . "\n\n" .
                ($paymentMethod === 'Cash' ? "Note: As you selected Cash as your payment method, please bring the exact amount in USD to complete your payment upon arrival.\n\n" : "") .
                "If you have any questions or need assistance, please contact us at admin@wfot.org.\n\n" .
                "Best regards,\nWFOT Team\n\n" .
                "© " . date('Y') . " World Federation of Occupational Therapists";
        }
    }
}
?>
