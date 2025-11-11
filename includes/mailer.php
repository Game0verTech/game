<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/Exception.php';

function mailer_from_config(): PHPMailer
{
    $config = load_config();
    $mail = new PHPMailer(true);
    $smtp = $config['smtp'] ?? [];

    $mail->isSMTP();
    $mail->Host = $smtp['host'] ?? 'localhost';
    $mail->Port = (int)($smtp['port'] ?? 25);
    $mail->SMTPAuth = !empty($smtp['username']);
    $mail->Username = $smtp['username'] ?? '';
    $mail->Password = $smtp['password'] ?? '';
    if (!empty($smtp['encryption'])) {
        $mail->SMTPSecure = $smtp['encryption'];
    }

    $fromEmail = $smtp['from_email'] ?? 'noreply@example.com';
    $fromName = $smtp['from_name'] ?? 'Play for Purpose Ohio';
    $mail->setFrom($fromEmail, $fromName);

    return $mail;
}

function send_mail(string $toEmail, string $toName, string $subject, string $body): bool
{
    try {
        $mail = mailer_from_config();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error: ' . $e->getMessage());
        return false;
    }
}

function send_test_email(array $smtpConfig, string $recipient): array
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtpConfig['host'];
        $mail->Port = (int)$smtpConfig['port'];
        if (!empty($smtpConfig['encryption'])) {
            $mail->SMTPSecure = $smtpConfig['encryption'];
        }
        $mail->SMTPAuth = !empty($smtpConfig['username']);
        $mail->Username = $smtpConfig['username'] ?? '';
        $mail->Password = $smtpConfig['password'] ?? '';
        $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
        $mail->addAddress($recipient);
        $mail->isHTML(true);
        $mail->Subject = 'SMTP Test - Play for Purpose Ohio';
        $mail->Body = '<p>This is a test email confirming SMTP settings.</p>';
        $mail->send();
        return ['success' => true, 'message' => 'Test email sent successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
