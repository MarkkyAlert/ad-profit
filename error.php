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
        <p class="code">รหัส <?= $escape((string)$status) ?></p>
        <h1><?= $escape($heading) ?></h1>
        <p><?= $escape($detail) ?></p>
        <a href="/dashboard.php">กลับหน้าแดชบอร์ด</a>
    </div>
</body>

</html>
