# QA_REPORT.md — รายงานผลการตรวจสอบ Core Business Flows

## ภาพรวมของระบบ (Ad-Profit)
จากการตรวจสอบโค้ด ระบบมี Core business flows หลัก 4 ส่วน ได้แก่:
1. สมัครสมาชิกและสร้างร้านเริ่มต้น (Registration & Default Shop Creation)
2. จัดการร้านค้า (Shop Management: สร้าง, เปลี่ยนชื่อ, สลับ, ลบ)
3. จัดการบันทึกรายวัน (Daily Record Management: บันทึก, แก้ไข, ลบ)
4. จัดการเป้าหมายรายเดือน (Monthly Goal Management: บันทึก, ลบเป้าหมาย)

---

## 1. Flow: สมัครสมาชิกและสร้างร้านเริ่มต้น (Registration)
เชื่อมโยงกับ: `login.php`, `api/auth.php` (action=register)
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** ไม่พบปัญหาร้ายแรง (Happy path ทำงานครบ, Transaction ครอบคลุมการสร้าง user และร้านแรก, จัดการ error กรณี email ซ้ำได้ถูกต้อง)
- **หลักฐานในโค้ด:** `app/Services/AuthService.php` ฟังก์ชัน `register()` บรรทัดที่ 92-100 (`$this->db->beginTransaction(); ... $this->db->commit();`)
- **ผลกระทบจริง:** ไม่มี
- **วิธีแก้ที่ “พอดี”:** -
- **Regression tests ที่ต้องมี:**
  - สมัครด้วยอีเมลใหม่ ต้องได้ user และร้านเริ่มต้น 1 ร้าน
  - สมัครด้วยอีเมลซ้ำ ต้องถูกปฏิเสธและขึ้น error ชัดเจน
  - ถ้า DB พังตรงสร้างร้าน (จำลอง) ต้อง rollback ไม่เกิด user กำพร้า

---

## 2. Flow: จัดการร้านค้า (Shop Management)
เชื่อมโยงกับ: `shops.php`, `api/shops.php`
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** การลบร้านเป็นการลบข้อมูลแบบถาวร (รวมข้อมูลที่ผูกกับร้าน) จึงมีความเสี่ยง “เผลอกดลบ” ในการใช้งานจริง
- **หลักฐานในโค้ด:**
  - Frontend บังคับยืนยันพิมพ์ชื่อร้าน: `shops.php` (ฟอร์มลบร้านมี `data-confirm-typed-expected` + ส่ง `confirm_shop_name`)
  - Global confirm modal รองรับ typed-confirm: `includes/footer.php` (อ่าน `data-confirm-typed-*` แล้ว `prompt` ก่อน submit)
  - Backend บังคับตรวจชื่อร้านก่อนลบ: `api/shops.php` (เช็ค `confirm_shop_name` เทียบกับชื่อร้านจริงก่อนเรียก `deleteShop()`)
  - Logic ลบร้าน (รวมเงื่อนไขห้ามลบร้านสุดท้าย): `app/Services/ShopService.php` ฟังก์ชัน `deleteShop()`
- **ผลกระทบจริง:** ลดโอกาสลบร้านพลาด (ถ้าพิมพ์ชื่อร้านไม่ตรง ระบบจะไม่ลบ)
- **วิธีแก้ที่ “พอดี”:** ใช้ “ยืนยัน 2 ชั้น” (modal + พิมพ์ชื่อร้าน) + บังคับตรวจที่ API เพื่อกันการข้าม UI
- **Regression tests ที่ต้องมี:**
  - ลบร้านโดยไม่ส่ง `confirm_shop_name` ต้องได้ 422
  - ลบร้านโดยส่ง `confirm_shop_name` ไม่ตรง ต้องได้ 422
  - ลบร้านโดยส่ง `confirm_shop_name` ตรง ต้องลบสำเร็จ และสลับไปยังร้านถัดไป
  - ลบร้านสุดท้าย ต้องถูกปฏิเสธ (ระบบบังคับให้มีอย่างน้อย 1 ร้าน)

---

## 3. Flow: จัดการบันทึกรายวัน (Daily Record)
เชื่อมโยงกับ: `add-record.php`, `history.php`, `api/records.php`
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** การอัปเดตข้อมูล (`updateRecord`) ป้องกันการแก้ไขแล้วเกิดวันที่ซ้ำซ้อนได้อย่างถูกต้องด้วย `FOR UPDATE` และ Transaction (ป้องกัน double click + race condition ได้ดีสำหรับระดับเล็ก)
- **หลักฐานในโค้ด:** `app/Services/RecordService.php` ฟังก์ชัน `updateRecord()` บรรทัดที่ 95-118 (เช็ค `$oldDate !== $newDate` และทำ `findByShopIdAndRecordDateForUpdate`)
- **ผลกระทบจริง:** ไม่มี (โค้ดป้องกัน race condition และ data integrity ไว้ดีแล้วระดับหนึ่งสำหรับอัปเดต)
- **วิธีแก้ที่ “พอดี”:** -
- **Regression tests ที่ต้องมี:**
  - บันทึกข้อมูลวันใหม่ ต้องสร้างบรรทัดใหม่
  - บันทึกข้อมูลลงวันเดิมซ้ำ (upsert) ต้องนำค่าไปอัปเดตบรรทัดเดิม
  - Update ข้อมูลโดยเปลี่ยนวันที่ ไปเป็นวันที่ที่มีข้อมูลอยู่แล้ว ต้องถูกปฏิเสธและขึ้น error

---

## 4. Flow: จัดการเป้าหมายรายเดือน (Monthly Goal)
เชื่อมโยงกับ: `dashboard.php`, `api/goals.php`
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** Validation ครบถ้วน (รูปแบบเดือน, เป้ารายได้/กำไรไม่ติดลบ) และใช้ Upsert ลดปัญหาส่งซ้ำ 
- **หลักฐานในโค้ด:** `app/Services/GoalService.php` ฟังก์ชัน `upsertGoal()` บรรทัดที่ 31-57
- **ผลกระทบจริง:** ไม่มี
- **วิธีแก้ที่ “พอดี”:** -
- **Regression tests ที่ต้องมี:**
  - ตั้งเป้าหมายเดือนใหม่ ต้องสำเร็จ
  - ตั้งเป้าหมายด้วยค่าติดลบ ต้องถูกปฏิเสธ
  - ลบเป้าหมายของร้านคนอื่น ต้องถูกปฏิเสธ

---

## สรุปท้ายรายงาน
ผ่าน: เพียงพอสำหรับขายเป็น template 

(ไม่พบข้อผิดพลาดระดับ ❌ ที่ต้องแก้ก่อนขาย โครงสร้างของ Service และ Repository ออกแบบมารองรับ Small System ได้ดี มีการจัดการ Transaction, Lock Row เบื้องต้น และ Validation ครบถ้วนในจุดที่เกิด Data Corruption ได้ง่าย)
