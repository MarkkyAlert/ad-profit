# Daily Record Flow Fix

- Updated `app/Services/RecordService.php::upsertRecord()` to run inside a DB transaction (when PDO is available)
- Added row-level lock using `RecordRepository::findByShopIdAndRecordDateForUpdate()` to serialize concurrent upserts for the same shop/date
- Updated `app/Services/RecordService.php::deleteRecord()` to run inside a DB transaction (when PDO is available) and lock the target row via `findByIdAndShopIdForUpdate()`
- Updated `app/Services/RecordService.php::updateRecord()` to commit/rollback only when it starts the transaction (avoid touching outer transactions)

Files:
- app/Services/RecordService.php
- docs/LOGIC_AUDIT_REPORT.md

Quick manual regression tests:
1) เปิด 2 แท็บ -> บันทึกวันเดียวกันรัวๆ / พร้อมกัน -> ต้องเหลือ 1 รายการ และไม่ขึ้น error
2) แท็บ A กดลบรายการ, แท็บ B กดแก้ไขรายการเดียวกันพร้อมๆกัน -> ต้องไม่ทำให้ระบบ error/ข้อมูลค้าง (ผลลัพธ์ต้องชัดเจนว่าเหลือหรือถูกลบ)
