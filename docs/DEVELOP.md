# เตรียมเครื่องเพื่อแก้โปรเจกต์ (หลัง clone)

> สำหรับเวลาจะกลับมาแก้โค้ด — ไม่ใช่การขึ้นเซิร์ฟเวอร์
> (ขึ้นเซิร์ฟเวอร์ดู [HOSTINGER.md](HOSTINGER.md))
>
> ใช้เวลาประมาณ **15 นาที** ครั้งแรก · ครั้งต่อไปแค่ `git pull` + `composer install`

---

## สารบัญ

- [⚠️ เรื่อง PHP ที่ต้องรู้ก่อน](#php)
- [ขั้นตอนติดตั้ง](#ติดตั้ง)
- [ฐานข้อมูลสำหรับรันเทสต์](#ฐานข้อมูลเทสต์)
- [คำสั่งที่ใช้บ่อย](#คำสั่ง)
- [ก่อนแก้โค้ด — อ่านอะไรก่อน](#ก่อนแก้)
- [เจอปัญหา](#ปัญหา)

---

<a name="php"></a>
## ⚠️ เรื่อง PHP ที่ต้องรู้ก่อน — ต้องมี 2 เวอร์ชันในหัว

| ใช้ทำอะไร | ต้องการ | ทำไม |
|---|---|---|
| **รันเว็บ** | **PHP ≥ 8.3** | ไลบรารีทำไฟล์ Excel บังคับ |
| **รันชุดทดสอบ** | **PHP ≥ 8.4.1** | PHPUnit 13 บังคับ |

⚠️⚠️ **PHP ที่มาพร้อม XAMPP ใช้ไม่ได้ทั้งคู่**

XAMPP บนเครื่องนี้เป็น **PHP 8.2.4** ซึ่งต่ำกว่าทั้งสองอย่าง
→ `composer install` จะล้มตั้งแต่แรก

**ต้องติดตั้ง PHP แยกต่างหาก** (macOS ใช้ Homebrew):

```bash
brew install php          # ได้ 8.4 ขึ้นไป
php -v                    # ต้องขึ้น 8.4.x หรือสูงกว่า
```

ตรวจว่ากำลังใช้ตัวไหน:

```bash
which php
# ✅ /opt/homebrew/bin/php   หรือ /usr/local/bin/php
# ❌ /Applications/XAMPP/xamppfiles/bin/php   ← ตัวนี้เก่าเกินไป
```

> ⚠️ ถ้า `which php` ชี้ไปที่ XAMPP ให้แก้ `PATH` ใน `~/.zshrc` ให้ Homebrew มาก่อน

---

<a name="ติดตั้ง"></a>
## ขั้นตอนติดตั้ง

### 1. clone

```bash
cd /Applications/XAMPP/xamppfiles/htdocs
git clone https://github.com/MarkkyAlert/ad-profit.git
cd ad-profit
```

### 2. ติดตั้งไลบรารี (**เอาชุดเต็ม** ต่างจากตอนขึ้นเซิร์ฟเวอร์)

```bash
composer install
```

⚠️ **ตอนพัฒนา *ไม่* ใส่ `--no-dev`** — ต้องได้ PHPUnit กับ PHPStan มาด้วย
(ตอนขึ้นเซิร์ฟเวอร์ค่อยใส่ `--no-dev`)

### 3. เปิด MySQL

จากหน้าจอ XAMPP กด **Start** ที่ MySQL (ใช้ตัวของ XAMPP ได้ ไม่มีปัญหาเรื่องเวอร์ชัน)

### 4. สร้างฐานข้อมูลสำหรับใช้งาน

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS ad_profit CHARACTER SET utf8mb4;"
/Applications/XAMPP/xamppfiles/bin/mysql -u root ad_profit < database/schema.sql
```

> ⚠️ `schema.sql` เป็นคำสั่ง **ลบตารางแล้วสร้างใหม่** — บนเครื่องตัวเองรันซ้ำได้
> (ข้อมูลทดลองหายไม่เป็นไร) **แต่บนเซิร์ฟเวอร์จริงห้ามเด็ดขาด**

### 5. สร้างไฟล์ `.env`

```bash
cp .env.example .env
```

แก้ค่าเท่านี้พอสำหรับเครื่องตัวเอง:

```ini
APP_ENV=development
APP_URL=http://localhost/ad-profit
DB_HOST=127.0.0.1
DB_NAME=ad_profit
DB_USER=root
DB_PASS=
MAIL_ENABLED=false
```

> `APP_ENV=development` ทำให้เห็นข้อความ error เต็ม ๆ — **ห้ามใช้บนเซิร์ฟเวอร์จริง**

### 6. เปิดเว็บ

**ทางที่ 1 — เซิร์ฟเวอร์ในตัวของ PHP** (แนะนำ เร็วและไม่ต้องพึ่ง Apache):

```bash
php -S localhost:8000
```
เปิด http://localhost:8000

⚠️ **`php -S` ไม่อ่านไฟล์ `.htaccess`** → ตัวกันไฟล์ลับกับหน้า 404 จะไม่ทำงาน
เรื่องพวกนั้นต้องทดสอบด้วย Apache จริงเท่านั้น

**ทางที่ 2 — Apache ของ XAMPP:**
กด Start ที่ Apache แล้วเปิด http://localhost/ad-profit

---

<a name="ฐานข้อมูลเทสต์"></a>
## ฐานข้อมูลสำหรับรันเทสต์ (คนละตัวกับที่ใช้งาน)

⚠️⚠️ **ชุดเทสต์จะล้างข้อมูลทุกครั้งที่รัน** — จึงต้องใช้ฐานข้อมูลแยก
ตัวโหลดมีด่านกันไว้: **ชื่อฐานข้อมูลต้องลงท้ายด้วย `_test`** ไม่งั้นมันปฏิเสธทันที

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS ad_profit_test CHARACTER SET utf8mb4;"
```

**ถ้าใช้ค่าปริยาย ไม่ต้องตั้ง env อะไรเลย:**

| ตัวแปร | ค่าปริยาย |
|---|---|
| `TEST_DB_HOST` | `127.0.0.1` |
| `TEST_DB_PORT` | `3306` |
| `TEST_DB_NAME` | `ad_profit_test` |
| `TEST_DB_USER` | `root` |
| `TEST_DB_PASS` | (ว่าง) |

ตั้งเฉพาะตัวที่ต่างจากนี้ เช่น:

```bash
TEST_DB_PASS=รหัสของคุณ composer test
```

> ต่อฐานข้อมูลทดสอบไม่ได้ → เทสต์กลุ่ม integration จะ **ข้าม** (ไม่ error)
> เห็นคำว่า `S` เยอะ ๆ ตอนรัน = ข้ามเพราะต่อ DB ไม่ได้ ไม่ใช่ผ่าน

---

<a name="คำสั่ง"></a>
## คำสั่งที่ใช้บ่อย

```bash
composer test                              # รันทั้งชุด (~5 นาที · 1,400+ เทสต์)
vendor/bin/phpunit --testsuite Unit        # เฉพาะ unit (เร็ว ไม่ต้องใช้ DB)
vendor/bin/phpunit tests/Integration/XxxTest.php    # เฉพาะไฟล์เดียว
vendor/bin/phpunit --filter testชื่อเทสต์            # เฉพาะเทสต์เดียว

composer stan                              # ตรวจโค้ดแบบไม่ต้องรัน (เร็ว ~30 วิ)
php -l ไฟล์.php                             # เช็กไวยากรณ์ไฟล์เดียว
```

**เครื่องมือตรวจ** (ใช้ตอนจะขึ้นเซิร์ฟเวอร์):

```bash
php tools/check-schema.php                 # ฐานข้อมูลตรงกับโค้ดไหม
php tools/check-deploy.php                 # เซิร์ฟเวอร์พร้อมไหม
php tools/check-live.php https://โดเมน      # ไฟล์ลับหลุดไหม
```

> ⚠️ ชุดเทสต์ใช้เวลาประมาณ **5 นาที** — `composer.json` ตั้งเวลารอไว้ 30 นาทีแล้ว
> ถ้าไม่ตั้ง composer จะฆ่ากลางคันที่ 5 นาทีพอดี แล้วดูเหมือนเทสต์ค้าง

---

<a name="ก่อนแก้"></a>
## ก่อนแก้โค้ด — อ่าน 2 ไฟล์นี้ก่อน

| ไฟล์ | มีอะไร |
|---|---|
| **`CLAUDE.md`** | **สัญญาของโปรเจกต์** — กติกาสถาปัตยกรรม + บันทึกบั๊กที่เคยเจอทุกตัวพร้อมเหตุผล · **อ่านก่อนแก้ทุกครั้ง** |
| `docs/WHERE_TO_EDIT.md` | จะแก้เรื่องนี้ต้องไปแก้ไฟล์ไหน |

**กฎเหล็ก 3 ข้อ:**

1. **แก้เสร็จต้องรัน `composer test` ให้ผ่านทั้งหมด** ก่อนถือว่าจบ
2. **แก้บั๊ก → เขียนเทสต์ที่จับบั๊กนั้นก่อน** แล้วค่อยแก้ให้ผ่าน
3. **เทสต์เขียวไม่ได้แปลว่าถูก** — ลองทำให้โค้ดพังแล้วดูว่าเทสต์แดงไหม
   ถ้าไม่แดง แปลว่าเทสต์นั้นไม่ได้ตรวจอะไรเลย

**สิ่งที่ระบบทดสอบแทนไม่ได้** อยู่ใน [manual-checks.md](manual-checks.md) —
การวางข้อมูลจาก Excel และอีเมลจริง ต้องกดเองทุกครั้งที่แก้ส่วนนั้น

---

<a name="ปัญหา"></a>
## เจอปัญหา

| อาการ | สาเหตุ | แก้ |
|---|---|---|
| `composer install` ล้ม บอกว่า php version | ใช้ PHP ของ XAMPP (8.2) | ติดตั้ง PHP 8.4+ แล้วแก้ `PATH` |
| `composer install` ล้ม บอกว่า `ext-zip` / `ext-gd` | PHP ที่ใช้ไม่มีส่วนเสริม | `brew install php` มักมีมาให้ครบ |
| หน้าเว็บขาว | ยังไม่ `composer install` | รัน `composer install` |
| 503 ระบบขัดข้อง | ยังไม่นำเข้า `schema.sql` หรือโครงไม่ตรง | `php tools/check-schema.php` |
| เทสต์ขึ้น `S` เยอะ | ต่อฐานข้อมูลทดสอบไม่ได้ | สร้าง `ad_profit_test` + เปิด MySQL |
| เทสต์ค้างแล้วตายที่ 5 นาที | `process-timeout` ไม่ได้ตั้ง | ใช้ `composer test` (ไม่ใช่ `vendor/bin/phpunit` ตรง ๆ) |
| เทสต์แดงแบบเดี๋ยวเขียวเดี๋ยวแดง | รันหลายโปรเซสพร้อมกันแย่งฐานข้อมูลเดียวกัน | รันทีละอัน (มีตัวเข้าคิวให้แล้ว รอสักครู่) |

---

## กลับมาทำงานครั้งถัดไป

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/ad-profit
git pull
composer install
composer test          # ต้องเขียวก่อนเริ่มแก้อะไร
```

⚠️ **ถ้าเทสต์แดงตั้งแต่ยังไม่แก้อะไร** — หยุดก่อน หาสาเหตุให้ได้
อาจเป็นเพราะฐานข้อมูลทดสอบเก่า (`php tools/check-schema.php` ช่วยได้)
