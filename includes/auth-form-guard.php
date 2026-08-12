<?php

declare(strict_types=1);

/**
 * ⭐⭐ ตัวกัน "กดส่งซ้ำ" + สัญญาณว่ากำลังส่ง — สำหรับหน้าที่ไม่ได้ใช้ footer.php ร่วม
 *
 * ⚠️⚠️ `login.php` · `forgot-password.php` · `reset-password.php` เป็นหน้าเดี่ยว
 * (มี `<head>` ของตัวเอง ไม่ผ่าน `includes/footer.php`) จึงไม่เคยได้ตัวกันกดซ้ำเลย
 * ขณะที่ **ทุกฟอร์มของหน้าที่ล็อกอินแล้วมี**
 *
 * ผลที่เกิดจริงเมื่อไม่มี:
 *  · เน็ตช้าแล้วกดปุ่มซ้ำ → ส่งคำขอเข้าสู่ระบบ/ขอลิงก์ซ้ำหลายครั้ง ซึ่ง **กินโควตา
 *    ตัวจำกัดจำนวนครั้ง** (ขอลิงก์รีเซ็ตมีเพดาน 1 ครั้ง/นาที) คนที่แค่ใจร้อนจึงถูกกัน
 *  · ไม่มีอะไรบอกว่ากำลังส่งอยู่ ผู้ใช้จึงไม่รู้ว่าต้องรอหรือกดใหม่
 *
 * ⚠️ ตั้งใจไม่ใช้ฉากโหลดเต็มจอแบบ `footer.php` — หน้าเหล่านี้ไม่มีมาร์กอัปนั้น
 * และการเปลี่ยนข้อความบนปุ่มก็เพียงพอสำหรับฟอร์มช่องเดียว/สองช่อง
 *
 * ⚠️ ห้ามปิดปุ่มก่อนเบราว์เซอร์ตรวจช่องที่ `required` เสร็จ — ไม่งั้นกรอกไม่ครบแล้วกดส่ง
 * ปุ่มจะดับค้างทั้งที่ฟอร์มไม่ได้ถูกส่ง (เหตุการณ์ `submit` ไม่ยิงเมื่อ validation ไม่ผ่าน
 * อยู่แล้ว จึงปลอดภัย แต่ต้องไม่ไปดักที่ `click`)
 */
?>
<script>
    (function() {
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (form.getAttribute('data-submitted') === '1') {
                    event.preventDefault();   // กดซ้ำระหว่างรอ — ไม่ส่งอีก
                    return;
                }

                form.setAttribute('data-submitted', '1');

                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                    button.disabled = true;
                    button.classList.add('opacity-70', 'cursor-not-allowed');

                    // บอกให้เห็นว่ากำลังส่ง — คนบนเน็ตช้าจะได้ไม่กดซ้ำ
                    if (button.tagName === 'BUTTON' && !button.hasAttribute('data-busy-label-set')) {
                        button.setAttribute('data-busy-label-set', '1');
                        button.textContent = 'กำลังดำเนินการ...';
                    }
                });
            });
        });
    })();
</script>
