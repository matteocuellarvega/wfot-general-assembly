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
        $mail->Subject = 'Booking Receipt';
        $mail->Body = 'Thank you for your booking. Your receipt is attached.';
        $mail->addAttachment($pdfPath, 'receipt.pdf');
        self::logDebug("PHPMailer configured, attempting to send receipt.");
        $result = $mail->send();
        self::logDebug("sendReceipt result: " . ($result ? "Success" : "Failure"));
        return $result;
    }
}
?>
