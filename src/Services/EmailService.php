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

    public static function sendConfirmation(string $to, string $name, string $pdfPath, string $meetingId): bool
    {
        self::logDebug("sendConfirmation called for to: $to, name: $name, pdfPath: $pdfPath, meetingId: $meetingId");
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
        $mail->Body = self::generateConfirmationEmailBody($name, $meetingId);
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", self::generateConfirmationEmailBody($name, $meetingId)));
        
        if (file_exists($pdfPath)) {
            $mail->addAttachment($pdfPath, 'WFOT_' . $meetingId . '_Booking_Confirmation.pdf');
        } else {
            self::logDebug("Warning: PDF file not found at path: $pdfPath");
        }
        
        self::logDebug("PHPMailer configured, attempting to send confirmation.");
        $result = $mail->send();
        self::logDebug("sendConfirmation result: " . ($result ? "Success" : "Failure"));
        return $result;
    }
    
    /**
     * Generate the HTML body for the basic confirmation email
     * 
     * @param string $name Recipient name
     * @return string Email HTML body
     */
    private static function generateConfirmationEmailBody(string $name, string $meetingId): string
    {
        return '
        <img src="' . rtrim(env('APP_URL'), '/') . '/assets/img/logo-'. strtolower($meetingId) .'.png" alt="WFOT Logo" style="max-width: 200px;">
        <h2>Booking Confirmation</h2>
        <p>Dear ' . htmlspecialchars($name) . ',</p>
        <p>Thank you for your booking for the WFOT General Assembly. Please find your booking confirmation attached to this email.</p>
        <p>If you have any questions or need assistance, please contact us at <a href="mailto:admin@wfot.org">admin@wfot.org</a>.</p>
        <p>Kind regards,<br>WFOT Organisational Management Team</p>';
    }
    
}
?>
