<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // ⚠️ อย่าพึ่ง sql_mode ปริยายของโฮสต์ — ถ้าไม่ strict MySQL จะ "ตัดให้พอดี"
        // แทนที่จะปฏิเสธ: โน้ต 256 ตัวถูกตัดเหลือ 255 · ยอด 10,000,000,000 กลายเป็น
        // 9,999,999,999.99 · เงียบสนิทและระบบรายงานว่าบันทึกสำเร็จ
        // ตัวกันฝั่ง PHP ควรจับได้ก่อนอยู่แล้ว นี่คือตาข่ายชั้นสุดท้าย
        //
        // ⚠️ สั่งเป็น query ธรรมดา ไม่ใช่ `MYSQL_ATTR_INIT_COMMAND` — ค่าคงที่นั้น
        // ถูกประกาศเลิกใช้ใน PHP 8.5 แล้วคำเตือนหลุดลงไปในหน้าเว็บ (เจอมาแล้ว)
        // ส่วนตัวแทนใหม่ (`Pdo\Mysql::ATTR_INIT_COMMAND`) มีเฉพาะ 8.4+ ซึ่งต่ำกว่า
        // ที่โปรเจกต์รองรับ (8.2) · query ธรรมดาทำงานเหมือนกันทุกเวอร์ชัน
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES,NO_ZERO_DATE')");
    } catch (PDOException $exception) {
        error_log('[database] ' . $exception->getMessage());
        throw new RuntimeException('Database connection failed.');
    }

    return $pdo;
}
