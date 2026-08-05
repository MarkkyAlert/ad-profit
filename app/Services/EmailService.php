<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private bool $enabled;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromAddress;
    private string $fromName;

    /**
     * ค่าปริยายมาจาก constant ของแอป — พารามิเตอร์มีไว้ให้เทสต์สลับค่าได้เท่านั้น
     *
     * ⚠️ เดิม constructor อ่าน constant ตรง ๆ กฎ "พร้อมส่งจริงหรือยัง" จึงทดสอบ
     * พฤติกรรมไม่ได้เลย เทสต์ต้องไปอ่าน *ซอร์สโค้ด* แล้วหาสตริง ซึ่งบรรทัดที่ถูก
     * คอมเมนต์ทิ้งไว้ก็ทำให้ผ่านได้ และการเขียนใหม่ที่ถูกต้องกลับทำให้แดง
     */
    public function __construct(
        ?bool $enabled = null,
        ?string $host = null,
        ?int $port = null,
        ?string $username = null,
        ?string $password = null,
        ?string $fromAddress = null,
        ?string $fromName = null
    ) {
        $this->enabled = $enabled ?? MAIL_ENABLED;
        $this->host = $host ?? MAIL_HOST;
        $this->port = $port ?? MAIL_PORT;
        $this->username = $username ?? MAIL_USERNAME;
        $this->password = $password ?? MAIL_PASSWORD;
        $this->fromAddress = $fromAddress ?? MAIL_FROM_ADDRESS;
        $this->fromName = $fromName ?? MAIL_FROM_NAME;
    }

    /**
     * "ตั้งค่าครบพอจะส่งได้จริง" ไม่ใช่แค่ MAIL_ENABLED=true
     *
     * fromAddress ต้องรวมด้วย — default เป็นค่าว่าง ถ้าลืมตั้งจะผ่านด่านนี้แล้วไปล้มที่
     * setFrom('') ทีหลัง กลายเป็น "ขอลิงก์รีเซ็ตแล้วเงียบ" แบบเดียวกับตอนไม่ได้ตั้ง SMTP
     */
    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->username !== ''
            && $this->password !== ''
            && $this->fromAddress !== '';
    }

    public function sendPasswordResetEmail(string $toEmail, string $resetLink): bool
    {
        if (!$this->isEnabled()) {
            error_log('[email] Email sending is disabled or not configured');
            return false;
        }

        $subject = 'รีเซ็ตรหัสผ่าน - ' . APP_NAME;
        
        $htmlBody = $this->buildPasswordResetHtml($resetLink);
        $textBody = $this->buildPasswordResetText($resetLink);

        return $this->send($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * ลิงก์ยืนยันอีเมลใหม่ — ส่งไปที่ **อีเมลใหม่** เท่านั้น
     *
     * ⚠️ ถ้าผู้ใช้พิมพ์อีเมลผิด จดหมายฉบับนี้จะไม่ถึงใคร → อีเมลไม่เปลี่ยน
     * ซึ่งคือสิ่งที่ต้องการ (บัญชีไม่หาย)
     */
    public function sendEmailChangeVerification(string $toEmail, string $verifyLink): bool
    {
        if (!$this->isEnabled()) {
            error_log('[email] Email sending is disabled or not configured');

            return false;
        }

        $hours = max(1, (int)PASSWORD_RESET_TOKEN_TTL_HOURS);
        $safeLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');

        $htmlBody = '<p>มีการขอเปลี่ยนอีเมลของบัญชี ' . APP_NAME . ' มาเป็น <strong>' . $safeEmail . '</strong></p>'
            . '<p>กดลิงก์ด้านล่างเพื่อยืนยัน — <strong>อีเมลจะยังไม่เปลี่ยนจนกว่าคุณจะกดลิงก์นี้</strong></p>'
            . '<p><a href="' . $safeLink . '">' . $safeLink . '</a></p>'
            . '<p>ลิงก์นี้ใช้ได้ ' . $hours . ' ชั่วโมง</p>'
            . '<p>ถ้าคุณไม่ได้เป็นคนขอ ไม่ต้องทำอะไร อีเมลของบัญชีจะยังเป็นอันเดิม</p>';

        $textBody = "มีการขอเปลี่ยนอีเมลของบัญชี " . APP_NAME . " มาเป็น {$toEmail}\n\n"
            . "กดลิงก์เพื่อยืนยัน (อีเมลจะยังไม่เปลี่ยนจนกว่าจะกดลิงก์นี้):\n{$verifyLink}\n\n"
            . "ลิงก์นี้ใช้ได้ {$hours} ชั่วโมง\n"
            . "ถ้าคุณไม่ได้เป็นคนขอ ไม่ต้องทำอะไร อีเมลของบัญชีจะยังเป็นอันเดิม\n";

        return $this->send($toEmail, 'ยืนยันอีเมลใหม่ - ' . APP_NAME, $htmlBody, $textBody);
    }

    private function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        if (!class_exists(PHPMailer::class)) {
            error_log('[email] PHPMailer is not installed. Please run `composer require phpmailer/phpmailer`');
            return false;
        }

        $attempts = max(1, MAIL_RETRY_ATTEMPTS + 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $mail = new PHPMailer(true);

            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = $this->host;
                $mail->SMTPAuth = true;
                $mail->Username = $this->username;
                $mail->Password = $this->password;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $this->port;
                $mail->Timeout = max(5, MAIL_TIMEOUT_SECONDS);
                $mail->CharSet = 'UTF-8';

                // Recipients
                $mail->setFrom($this->fromAddress, $this->fromName);
                $mail->addAddress($to);

                // Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                $mail->AltBody = $textBody ?: strip_tags($htmlBody);

                $mail->send();
                error_log('[email] Email sent successfully to: ' . $to . ' (attempt ' . $attempt . '/' . $attempts . ')');
                return true;
            } catch (Exception $exception) {
                error_log('[email] Failed to send email to: ' . $to . ' (attempt ' . $attempt . '/' . $attempts . '). Mailer Error: ' . $mail->ErrorInfo);

                if ($attempt >= $attempts) {
                    return false;
                }

                usleep(200000);
            }
        }

        return false;
    }

    private function buildPasswordResetHtml(string $resetLink): string
    {
        $appName = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
        $expiryHours = max(1, PASSWORD_RESET_TOKEN_TTL_HOURS);

        return <<<HTML
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รีเซ็ตรหัสผ่าน</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0a1628;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; margin: 0 auto; padding: 40px 20px;">
        <tr>
            <td style="background: linear-gradient(135deg, #0d1526 0%, #1a2744 100%); border-radius: 16px; padding: 40px; border: 1px solid rgba(255,255,255,0.1);">
                <h1 style="color: #fff; font-size: 24px; margin: 0 0 20px 0; text-align: center;">
                    📊 {$appName}
                </h1>
                
                <h2 style="color: #e2e8f0; font-size: 20px; margin: 0 0 16px 0;">
                    รีเซ็ตรหัสผ่านของคุณ
                </h2>
                
                <p style="color: #94a3b8; font-size: 14px; line-height: 1.6; margin: 0 0 24px 0;">
                    เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณ คลิกปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่
                </p>
                
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="text-align: center; padding: 20px 0;">
                            <a href="{$safeLink}" style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #f97316, #ea580c); color: #fff; text-decoration: none; border-radius: 12px; font-weight: 600; font-size: 16px;">
                                รีเซ็ตรหัสผ่าน
                            </a>
                        </td>
                    </tr>
                </table>
                
                <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin: 24px 0 0 0;">
                    ลิงก์นี้จะหมดอายุใน {$expiryHours} ชั่วโมง หากคุณไม่ได้ขอรีเซ็ตรหัสผ่าน กรุณาละเว้นอีเมลนี้
                </p>
                
                <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.1); margin: 24px 0;">
                
                <p style="color: #475569; font-size: 11px; margin: 0;">
                    หากปุ่มไม่ทำงาน ให้คัดลอกลิงก์นี้ไปวางในเบราว์เซอร์:<br>
                    <a href="{$safeLink}" style="color: #f97316; word-break: break-all;">{$safeLink}</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private function buildPasswordResetText(string $resetLink): string
    {
        $appName = APP_NAME;
        $expiryHours = max(1, PASSWORD_RESET_TOKEN_TTL_HOURS);

        return <<<TEXT
{$appName} - รีเซ็ตรหัสผ่าน

เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณ

คลิกลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่:
{$resetLink}

ลิงก์นี้จะหมดอายุใน {$expiryHours} ชั่วโมง

หากคุณไม่ได้ขอรีเซ็ตรหัสผ่าน กรุณาละเว้นอีเมลนี้
TEXT;
    }
}
