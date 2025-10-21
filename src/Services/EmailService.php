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

    public static function sendConfirmationWithoutPdf(string $to, string $name, string $confirmationUrl, string $meetingId): bool
    {
        self::logDebug("sendConfirmationWithoutPdf called for to: $to, name: $name, confirmationUrl: $confirmationUrl, meetingId: $meetingId");
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
        // if($bcc = env('MAIL_BCC_ADMIN')) $mail->addBCC($bcc);
        $mail->Subject = 'WFOT General Assembly - Booking Confirmation';
        
        // Create email body with confirmation link
        $mail->isHTML(true);
        $mail->Body = self::generateConfirmationEmailBodyWithoutPdf($name, $meetingId, $confirmationUrl);
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", self::generateConfirmationEmailBodyWithoutPdf($name, $meetingId, $confirmationUrl)));
        
        self::logDebug("PHPMailer configured, attempting to send confirmation.");
        $result = $mail->send();
        self::logDebug("sendConfirmationWithoutPdf result: " . ($result ? "Success" : "Failure"));
        return $result;
    }
    
    /**
     * Generate the HTML body for the confirmation email without PDF attachment
     * 
     * @param string $name Recipient name
     * @param string $meetingId Meeting identifier
     * @param string $confirmationUrl URL to download the PDF confirmation
     * @return string Email HTML body
     */
    private static function generateConfirmationEmailBodyWithoutPdf(string $name, string $meetingId, string $confirmationUrl): string
    {
        return '
        <img src="' . rtrim(env('APP_URL'), '/') . '/assets/img/logo-'. strtolower($meetingId) .'.png" alt="WFOT Logo" style="max-width: 200px;">
        <h2>Booking Confirmation</h2>
        <p>Dear ' . htmlspecialchars($name) . ',</p>
        <p>Thank you for your booking for the WFOT General Assembly. Your payment has been successfully processed.</p>
        <p>You can download your booking confirmation here: <a href="' . htmlspecialchars($confirmationUrl) . '">Download Confirmation PDF</a></p>
        <p>If you have any questions or need assistance, please contact us at <a href="mailto:admin@wfot.org">admin@wfot.org</a>.</p>
        <p>Kind regards,<br>WFOT Organisational Management Team</p>';
    }
    
}
?>
