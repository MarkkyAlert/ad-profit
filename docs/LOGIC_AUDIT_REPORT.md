# รายงานการตรวจสอบ Logic และ Flow การทำงาน (System Logic Audit)
## สำหรับ "Ad-Profit" Project (Small/Medium System)

> หมายเหตุ: รายการปรับแก้ที่ทำแล้วบางส่วนบันทึกไว้ที่ `docs/CHANGELOG_FIXES.md`

จากการตรวจสอบ Source Code (API, Services, Repositories) เพื่อวิเคราะห์ Core Business Flows ตามมาตรฐานสำหรับ Production ของระบบขนาดเล็ก (Small System) พบว่าระบบได้รับการออกแบบมาค่อนข้างดีเยี่ยม มีการป้องกัน Race Condition, Double Submit (Idempotency แบบพื้นฐาน), และมีการควบคุม Data Integrity ผ่าน Database Constraints และ Transactions

สรุปผลการตรวจสอบ Core Flows:

### Flow 1: Authentication & Account Initialization (Register / Login)
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** ไม่พบปัญหาร้ายแรง ระบบมีการใช้ Transaction ครอบคลุมการสร้าง User + Shop เริ่มต้น และมีการจัดการ Duplicate Email ด้วยการดัก `PDOException 23000`
- **หลักฐานในโค้ด:** `AuthService.php::register()` บรรทัดที่ 92-121 (`$this->db->beginTransaction()`, `$this->userRepository->create()`, `$this->shopRepository->create()`)
- **ผลกระทบจริง:** ไม่มีผลกระทบด้านลบ ข้อมูล User และ Shop แรกถูกสร้างพร้อมกันเสมอ (Atomic)
- **วิธีแก้:** -
- **Regression tests:**
  - สมัครสมาชิกด้วยอีเมลใหม่ ต้องได้ทั้ง User และ 1 Shop
  - สมัครสมาชิกด้วยอีเมลซ้ำ ต้องถูกปฏิเสธและไม่เกิด Orphan Data

### Flow 2: Shop Management (Create / Rename / Delete)
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** ไม่พบปัญหาร้ายแรง ระบบจัดการเรื่อง Race Condition ได้ดีมาก (มีการใช้ `FOR UPDATE` ล็อกแถว User และ Shop ก่อนแก้ไขชื่อหรือลบ) และมีการเช็กว่าต้องเหลืออย่างน้อย 1 ร้านค้า (`countByUserIdForUpdate`)
- **หลักฐานในโค้ด:** `ShopService.php::renameShop()`, `deleteShop()` มีการเรียก `$this->lockUserRowForUpdate()` และ `$this->lockShopRowForUpdate()` ภายใต้ Transaction
- **ผลกระทบจริง:** ป้องกันการเปลี่ยนชื่อร้านซ้ำกันในเสี้ยววินาที และป้องกันการลบร้านค้าร้านสุดท้ายจน User ไม่มีร้านให้ใช้งาน
- **วิธีแก้:** -
- **Regression tests:**
  - ลบร้านค้าเมื่อมีร้านเดียว ต้องลบไม่ได้
  - เปลี่ยนชื่อร้านค้าให้ซ้ำกับชื่อร้านอื่นของตัวเอง ต้องถูกปฏิเสธ

### Flow 3: Daily Record Management (Upsert / Update / Delete)
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** ปรับให้ write actions สำคัญของ Daily Record (Upsert/Delete) มี Transaction และ Row Lock แล้ว เพื่อให้ Pattern สอดคล้องกับ write-flows อื่น และกัน concurrency edge-case แบบ real-world (double click / 2 tabs)
- **หลักฐานในโค้ด:**
  - `RecordService.php::upsertRecord()` เพิ่ม `beginTransaction()` + `findByShopIdAndRecordDateForUpdate(... FOR UPDATE)` + `commit()/rollBack()`
  - `RecordService.php::deleteRecord()` เพิ่ม `beginTransaction()` + `findByIdAndShopIdForUpdate(... FOR UPDATE)` + `commit()/rollBack()`
  - `RecordService.php::updateRecord()` ปรับให้ `commit()/rollBack()` ทำเฉพาะเมื่อ method นี้เป็นคนเริ่ม transaction
- **หมายเหตุ:** เพิ่มการ guard `Content-Type` สำหรับ POST actions ใน API (ตอบ 415 หากไม่ใช่ form/multipart) เพื่อให้ behavior predictable ใน production
- **ผลกระทบจริง:** ลดความเสี่ยง data race/TOCTOU และทำให้ผลลัพธ์ predictable ภายใต้ concurrent requests
- **วิธีแก้ที่พอดี:** (ทำแล้ว) ครอบ transaction เฉพาะช่วง write และ lock เฉพาะ 1 แถวที่เกี่ยวข้อง
- **Regression tests:**
  - กดปุ่มบันทึกรายได้ของ "วันเดียวกัน" รัวๆ 10 ครั้ง ต้องมีเพียง 1 Record ที่อัปเดตเป็นค่าล่าสุด ไม่เกิด Error 500 หรือข้อมูลซ้ำ

### Flow 4: Goal Management (Upsert)
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** อาศัย `ON DUPLICATE KEY UPDATE` ใน `GoalRepository::upsert()` เป็นหลักเช่นเดียวกับ Record ซึ่งสำหรับ Small System ถือว่าเพียงพอ
- **หลักฐานในโค้ด:** `GoalRepository.php::upsert()` ใช้ `ON DUPLICATE KEY UPDATE target_revenue = VALUES(target_revenue)...`
- **ผลกระทบจริง:** ไม่มีผลกระทบร้ายแรง
- **วิธีแก้:** -
- **Regression tests:**
  - ตั้งเป้าหมายเดือนเดิมซ้ำๆ ต้องเป็นการอัปเดตทับเป้าหมายเดิม

### Flow 5: Profile Management (Change Email / Password)
- **สถานะ:** ✅ ใช้ได้แล้ว
- **ปัญหา:** ไม่พบปัญหาร้ายแรง การเปลี่ยน Email และ Password มีการใช้ Transaction และ Lock User Row ป้องกันคนแฮกหรือทำ State เสียหายได้ดีมาก
- **หลักฐานในโค้ด:** `ProfileService.php::changeEmail()` และ `changePassword()` มีการเรียก `lockUserRowForUpdate()` ภายใน Transaction
- **ผลกระทบจริง:** มั่นใจได้ว่าการอัปเดต Session Version ควบคู่กับ Password จะเป็น Atomic
- **วิธีแก้:** -
- **Regression tests:**
  - ยิง API เปลี่ยนรหัสผ่านพร้อมกัน 2 Request ด้วยรหัสผ่านใหม่ต่างกัน ผลลัพธ์ต้องได้รหัสใดรหัสหนึ่งและอีกอันต้องถูกปฏิเสธ (หรือถ้าผ่านทั้งคู่ Session Version ต้องอัปเดต 2 ครั้ง)

---

## สรุป (Conclusion)
**ผ่าน: เพียงพอสำหรับขายเป็น template**

โครงสร้าง Business Logic ของระบบนี้ออกแบบมาได้รัดกุมเกินมาตรฐานของระบบขนาดเล็กทั่วไปมาก มีการนำ Defensive Programming และ Concurrency Control (Transaction, Row Locking `FOR UPDATE`, DB Constraints) มาใช้เพื่อป้องกันการเกิด Data Corruption อย่างเป็นระบบ ผมไม่พบช่องโหว่ทาง Logic ที่จะทำให้ระบบ "พังจริง" จากการใช้งานปกติหรือจากการรัวปุ่ม (Double Submit) ครับ
