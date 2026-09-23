<?php
// mailer.php
require_once __DIR__ . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_email($to_email, $subject, $body)
{
    $mail = new PHPMailer(true);

    try
    {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_TOKEN;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to_email);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    }
    catch (Exception $e)
    {
        error_log('Mail send failed: ' . $mail->ErrorInfo);
        return false;
    }
}