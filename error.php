<?php

declare(strict_types=1);

/**
 * ⭐ หน้าแจ้งข้อผิดพลาดหน้าเดียวใช้ทุกรหัส (404 / 403 / 500)
 *
 * เดิม `.htaccess` ไม่มี `ErrorDocument` เลย ผู้ใช้ที่เปิดลิงก์ผิดหรือลิงก์เก่าจึงเจอ
 * หน้าเปล่าภาษาอังกฤษของ Apache ไม่มีเมนู ไม่มีทางกลับ — คนที่ไม่ถนัดคอมพิวเตอร์
 * จะเข้าใจว่าเว็บพังทั้งเว็บ
 *
 * ⚠️ ห้าม `require bootstrap.php` — หน้านี้ต้องขึ้นได้แม้ตอนที่ระบบพังจนตอบ 500
 * (ฐานข้อมูลล่ม · schema ไม่ตรง) ถ้าไปพึ่ง bootstrap ก็จะพังซ้ำแล้ววนกลับมาที่ตัวเอง
 * จึงเขียนเป็นหน้า HTML ล้วนที่ไม่แตะฐานข้อมูลและไม่เปิด session
 *
 * ⚠️ ไม่แสดงรายละเอียดทางเทคนิคใด ๆ — เส้นทางไฟล์/ข้อความ error ของ PHP ไม่ควร
 * หลุดออกไปหาผู้ใช้ (หลักเดียวกับที่ `bootstrap.php` ปิด display_errors)
 */

$status = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);

$messages = [
    403 => [
        'หน้านี้เข้าดูไม่ได้',
        'ลิงก์นี้ไม่ได้เปิดให้เข้าถึง หากคุณคิดว่าน่าจะเข้าได้ ลองเข้าสู่ระบบใหม่อีกครั้ง',
    ],
    404 => [
        'ไม่พบหน้าที่ต้องการ',
        'ลิงก์อาจพิมพ์ผิด หรือเป็นลิงก์เก่าที่ย้ายไปแล้ว',
    ],
    500 => [
        'ระบบขัดข้องชั่วคราว',
        'ไม่ได้เกิดจากสิ่งที่คุณทำ กรุณารอสักครู่แล้วลองใหม่อีกครั้ง',
    ],
];

if (!isset($messages[$status])) {
    $status = 404;
}

[$heading, $detail] = $messages[$status];

if (!headers_sent()) {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
}

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/* ⚠️⚠️ ปุ่มกลับต้องคำนวณที่อยู่เอง — ห้ามเขียน `/dashboard.php` ตายตัว
 *
 * บน Hostinger รากโปรเจกต์คือ `public_html/` ลิงก์แบบ `/dashboard.php` จึงถูก
 * **แต่คู่มือบอกให้ติดตั้งใน `http://localhost/ad-profit/` ตอนพัฒนา** และคนที่ซื้อ
 * เทมเพลตไปวางในโฟลเดอร์ย่อยก็มี · ลิงก์ที่ขึ้นต้นด้วย `/` จะพาไปที่รากโดเมน
 * ซึ่งไม่มีแอปอยู่ → กดปุ่ม "กลับหน้าแดชบอร์ด" แล้วเจอ 404 ของ Apache ซ้ำอีกชั้น
 * (หน้าที่มีไว้ช่วยคนหลงทาง กลายเป็นทางตัน)
 *
 * ⚠️ ห้ามใช้ `app_url()` — หน้านี้ต้องขึ้นได้แม้ตอนที่ระบบพังจนโหลด bootstrap ไม่ได้
 * จึงคำนวณจาก `SCRIPT_NAME` ซึ่งเว็บเซิร์ฟเวอร์ให้มาเสมอ ไม่ต้องพึ่งไฟล์อื่นเลย
 */
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/error.php');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
// ⚠️ กันค่าที่ไม่คาดคิด — ยอมเฉพาะเส้นทางที่ขึ้นต้นด้วย / และไม่มีตัวพาออกนอกเว็บ
if ($basePath !== '' && (!str_starts_with($basePath, '/') || str_contains($basePath, '..'))) {
    $basePath = '';
}
$dashboardUrl = $basePath . '/dashboard.php';
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($heading) ?></title>
    <style>
        /* ⚠️ CSS ฝังในหน้า ไม่โหลดจากที่อื่น — หน้านี้ต้องขึ้นได้แม้ตอนระบบมีปัญหา */
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background-color: #081028;
            color: #e2e8f0;
            font-family: 'Sarabun', 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.7
        }

        .box {
            width: 100%;
            max-width: 440px;
            text-align: center;
            background: #0b1739;
            border: 1px solid rgba(99, 102, 241, .18);
            border-radius: 16px;
            padding: 32px 24px;
            box-shadow: 0 8px 32px rgba(1, 5, 17, .4)
        }

        .brand {
            margin: 0 0 20px
        }

        .brand-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            margin-bottom: 10px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, .10);
            background: rgba(255, 255, 255, .05);
            font-size: 26px
        }

        /* ไล่สีเดียวกับหัวเว็บและหน้าเข้าสู่ระบบ — CSS ฝังในหน้าเพราะหน้านี้ต้องขึ้นได้แม้ระบบพัง */
        .brand-name {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            background: linear-gradient(135deg, #f97316 0%, #d946ef 55%, #6366f1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text
        }

        .code {
            font-size: 13px;
            letter-spacing: .08em;
            color: #94a3b8;
            margin: 0 0 8px
        }

        h1 {
            font-size: 21px;
            margin: 0 0 12px;
            color: #f1f5f9
        }

        p {
            margin: 0 0 24px;
            font-size: 15px;
            color: #cbd5e1
        }

        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 24px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: #fff;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none
        }
    </style>
</head>

<body>
    <div class="box">
        <?php /* ⚠️ ต้องมีชื่อแอปอยู่บนสุด — กติกาเดียวกับหน้าลืมรหัสผ่าน/ตั้งรหัสใหม่
                 หน้านี้คือหน้าที่ผู้ใช้มาถึงโดย "ไม่ได้ตั้งใจ" มากที่สุดในระบบ (ลิงก์ผิด ·
                 ลิงก์เก่า · ระบบขัดข้อง) การ์ดเปล่าที่ไม่บอกว่าเป็นเว็บอะไรทำให้ยืนยันไม่ได้
                 ว่ามาถูกที่ ซึ่งเป็นจังหวะที่คนระวังลิงก์ปลอมที่สุดพอดี
                 ⚠️ ใช้คำว่า "วิเคราะห์ยอดขาย" ไม่ใช่ APP_NAME — เป็นชื่อที่ผู้ใช้เห็นบนจอทั้งระบบ */ ?>
        <div class="brand">
            <div class="brand-mark">📊</div>
            <p class="brand-name">วิเคราะห์ยอดขาย</p>
        </div>
        <p class="code">รหัส <?= $escape((string)$status) ?></p>
        <h1><?= $escape($heading) ?></h1>
        <p><?= $escape($detail) ?></p>
        <a href="<?= $escape($dashboardUrl) ?>">กลับหน้าแดชบอร์ด</a>
    </div>
</body>

</html>
