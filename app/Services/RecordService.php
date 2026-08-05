<?php

declare(strict_types=1);

class RecordService
{
    /** จำนวนแถวสูงสุดต่อการบันทึกแบบหลายวัน 1 ครั้ง (ครอบคลุม 1 เดือน) */
    public const BULK_MAX_ROWS = 31;

    /** จำนวนแถวสูงสุดต่อการนำเข้าไฟล์ CSV 1 ครั้ง */
    public const IMPORT_MAX_ROWS = 1000;

    /** เพดานของ revenue/ad_cost — ตรงกับ DECIMAL(12,2) ใน database/schema.sql */
    public const MAX_AMOUNT = 9999999999.99;

    /** จำนวนทศนิยมที่คอลัมน์เก็บได้ — daily_records/monthly_goals เป็น DECIMAL(12,2) */
    public const AMOUNT_DECIMALS = 2;

    /**
     * ความยาวโน้ตสูงสุด — ต้องเท่ากับ `daily_records.note` ใน schema เป๊ะ ๆ
     *
     * ⚠️ ตัวเลขนี้กับความยาวคอลัมน์ต้องขยับพร้อมกัน · ถ้าคอลัมน์เล็กกว่า MySQL จะ
     * ตัดข้อความทิ้งเงียบ ๆ บนโฮสต์ที่ไม่ได้เปิด strict mode แล้วรายงานว่าสำเร็จ
     * (`SchemaContractTest` ล็อกคู่นี้ไว้)
     */
    public const NOTE_MAX_LENGTH = 255;

    /** ข้อความบอกวิธีเขียนตัวเลขให้ถูก — ใช้ร่วมกันทุกที่ที่ปฏิเสธรูปแบบตัวเลข */
    public const AMOUNT_FORMAT_HINT = 'อ่านไม่ได้หรือกำกวม — ใช้จุดเป็นทศนิยมไม่เกิน 2 ตำแหน่ง '
        . 'เช่น 1234.56 (ถ้าหมายถึงหนึ่งพันสองร้อยสามสิบสี่ ให้พิมพ์ 1234 หรือ 1,234.00)';

    /**
     * จำนวนวันขั้นต่ำที่ถือว่า "พอจะสรุปแนวโน้มของวันนั้นในสัปดาห์ได้"
     *
     * ใช้ทั้งคำวินิจฉัยของการ์ดเทียบวันล่าสุด (`trend_reliable`) และการเลือกวันดี/วันเงียบ
     * ในตารางแยกตามวัน — เดิมสองที่นี้ใช้เกณฑ์คนละตัว (1 กับ 3) ทั้งที่อยู่หน้าจอเดียวกัน
     */
    public const WEEKDAY_MIN_SAMPLE = 3;

    /** ช่วงปีที่รายงานรองรับ — ตรงกับ resolve_calendar_year() และ isValidYear ของ service รายปี */
    public const MIN_RECORD_YEAR = 2000;
    public const MAX_RECORD_YEAR = 2100;

    /** ชื่อเดือนย่อภาษาไทย → เลขเดือน (ใช้ parse วันที่จากไฟล์ export) */
    private const THAI_MONTH_ABBREVIATIONS = [
        'ม.ค.' => 1,
        'ก.พ.' => 2,
        'มี.ค.' => 3,
        'เม.ย.' => 4,
        'พ.ค.' => 5,
        'มิ.ย.' => 6,
        'ก.ค.' => 7,
        'ส.ค.' => 8,
        'ก.ย.' => 9,
        'ต.ค.' => 10,
        'พ.ย.' => 11,
        'ธ.ค.' => 12,
    ];

    /** หัวคอลัมน์ที่ยอมรับ (ตัวพิมพ์เล็ก) → ฟิลด์ภายใน */
    private const IMPORT_HEADER_ALIASES = [
        'วันที่' => 'record_date',
        'วัน' => 'record_date',
        'date' => 'record_date',
        'record_date' => 'record_date',
        'รายได้' => 'revenue',
        'ยอดขาย' => 'revenue',
        'revenue' => 'revenue',
        'ค่าแอด' => 'ad_cost',
        'ค่าโฆษณา' => 'ad_cost',
        'ad_cost' => 'ad_cost',
        'adcost' => 'ad_cost',
        'ad cost' => 'ad_cost',
        'โน้ต' => 'note',
        'หมายเหตุ' => 'note',
        'note' => 'note',
    ];

    /** ข้อความที่ถือว่าเป็น "แถวรวม" ท้ายตาราง → ข้าม */
    private const IMPORT_TOTAL_LABELS = ['รวม', 'ผลรวม', 'รวมทั้งหมด', 'total', 'sum'];

    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;
    private ?PDO $db;

    public function __construct(RecordRepository $recordRepository, ShopRepository $shopRepository, ?PDO $db = null)
    {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
        $this->db = $db;
    }

    /** ร้านหายไประหว่างที่หน้าเปิดค้างอยู่ — ข้อความเดียวกันทุกทางที่เขียน */
    private static function shopVanishedResult(): array
    {
        return [
            'success' => false,
            'error' => 'ร้านนี้ถูกลบไปแล้ว กรุณาโหลดหน้าใหม่แล้วเลือกร้านอีกครั้ง',
        ];
    }

    public function upsertRecord(
        int $userId,
        int $shopId,
        string $recordDate,
        float $revenue,
        float $adCost,
        ?string $note
    ): array {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $validation = $this->validateRecordPayload($recordDate, $revenue, $adCost, $note);
        if (($validation['success'] ?? false) !== true) {
            return $validation;
        }

        $payload = $validation['data'];

        $startedTransaction = false;
        $canLockRows = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                $canLockRows = $this->db->inTransaction();
                if ($canLockRows) {
                    // ⚠️ จองแถว "ร้าน" ก่อน ไม่ใช่แถว "วัน"
                    //
                    // เดิมจองแถวของวันนั้นด้วย FOR UPDATE ก่อนเขียน ซึ่ง (1) ไม่ได้ช่วยอะไร
                    // เพราะคำสั่งเขียนเป็น INSERT … ON DUPLICATE KEY UPDATE ที่กันชนกันเอง
                    // อยู่แล้ว และ (2) เมื่อยังไม่มีข้อมูลของวันนั้น MySQL จะจอง "ช่องว่าง"
                    // ระหว่างวันแทน สองคนที่บันทึกคนละวันในช่องว่างเดียวกันจึงล็อกกันเอง
                    // จนถูกตัดทิ้งเป็น deadlock · ที่ต้องจองจริง ๆ คือแถวร้าน เพื่อให้ลำดับ
                    // ตรงกับตอนลบร้าน (ร้าน → ข้อมูลในร้าน) ดู ShopRepository::lockForWrite()
                    if (!$this->shopRepository->lockForWrite($shopId, $userId)) {
                        // ร้านถูกลบจากอีกอุปกรณ์ระหว่างที่หน้านี้เปิดค้างไว้ — ถ้าปล่อยผ่าน
                        // จะไปตายที่ foreign key แล้วผู้ใช้เห็นแค่ "ไม่สามารถบันทึกข้อมูลได้"
                        //
                        // ⚠️ ยกเลิกได้เฉพาะทรานแซกชันที่เมธอดนี้เปิดเอง — ถ้าผู้เรียกเปิดมาให้
                        // การ rollBack ตรงนี้จะล้างงานของเขาทิ้งโดยที่เขาไม่รู้ตัว
                        if ($startedTransaction && $this->db->inTransaction()) {
                            $this->db->rollBack();
                        }

                        return self::shopVanishedResult();
                    }
                }
            }

            $this->recordRepository->upsert(
                $shopId,
                (string)$payload['record_date'],
                (float)$payload['revenue'],
                (float)$payload['ad_cost'],
                $payload['note']
            );

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[record] upsertRecord failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => write_failure_message($exception, 'ไม่สามารถบันทึกข้อมูลได้'),
            ];
        }

        return [
            'success' => true,
            'message' => 'บันทึกข้อมูลเรียบร้อยแล้ว',
        ];
    }

    /**
     * แปลงเนื้อหาไฟล์ CSV เป็น rows สำหรับส่งต่อให้ upsertManyRecords()
     *
     * เป็น pure function (ไม่แตะ DB) — validation ธุรกิจยังเป็นหน้าที่ของ upsertManyRecords
     * รองรับไฟล์ที่ export จากระบบนี้โดยตรง (BOM · วันที่ไทย · คอลัมน์คำนวณ · แถว "รวม")
     *
     * @return array{success: bool, rows: array<int,array<string,mixed>>, error: string|null}
     */
    public function parseImportCsv(string $content): array
    {
        $fail = static fn(string $message): array => [
            'success' => false,
            'rows' => [],
            'error' => $message,
        ];

        // ตัด BOM ที่ export ใส่ไว้ ไม่งั้นหัวคอลัมน์แรกจะ match ไม่ติด
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (trim($content) === '') {
            return $fail('ไฟล์ว่าง ไม่มีข้อมูลให้นำเข้า');
        }

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return $fail('ไม่สามารถอ่านไฟล์ได้');
        }

        fwrite($handle, $content);
        rewind($handle);

        $headerCells = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($headerCells)) {
            fclose($handle);
            return $fail('อ่านหัวตารางไม่ได้ กรุณาตรวจสอบไฟล์');
        }

        // map ตำแหน่งคอลัมน์ → ฟิลด์ (คอลัมน์ที่ไม่รู้จัก เช่น กำไร/ROAS จะถูกเพิกเฉย)
        $columnMap = [];
        foreach ($headerCells as $index => $headerCell) {
            $key = mb_strtolower(trim((string)$headerCell));
            if (isset(self::IMPORT_HEADER_ALIASES[$key])) {
                $columnMap[self::IMPORT_HEADER_ALIASES[$key]] = (int)$index;
            }
        }

        // ไม่เจอคอลัมน์ที่ต้องการ "สักอันเดียว" มักไม่ได้แปลว่าไฟล์ขาดคอลัมน์จริง
        // แต่แปลว่าอ่านหัวตารางไม่ออก — encoding ไม่ใช่ UTF-8 (เช่น CP874 จาก Excel ไทย)
        // หรือใช้ตัวคั่นอื่น (`;`) หรือไม่ใช่ไฟล์ CSV เลย (เช่น .xlsx ที่เปลี่ยนนามสกุลมา)
        // ข้อความเดิมบอกว่า "ไม่พบคอลัมน์วันที่" ทั้งที่ผู้ใช้เปิดไฟล์แล้วเห็นคอลัมน์ครบ
        if ($columnMap === []) {
            fclose($handle);
            return $fail(
                'อ่านหัวตารางไม่ออก — ไฟล์อาจไม่ใช่ CSV แบบ UTF-8 หรือใช้ตัวคั่นอื่นที่ไม่ใช่จุลภาค '
                . 'กรุณาเปิดใน Excel แล้ว Save as "CSV UTF-8 (Comma delimited)" หรือใช้ไฟล์ที่ดาวน์โหลดจากหน้าประวัติ'
            );
        }

        foreach (['record_date' => 'วันที่', 'revenue' => 'รายได้', 'ad_cost' => 'ค่าแอด'] as $field => $label) {
            if (!isset($columnMap[$field])) {
                fclose($handle);
                return $fail('ไฟล์ต้องมีคอลัมน์ "' . $label . '" (ไม่พบในหัวตาราง)');
            }
        }

        $rows = [];
        // นับ "แถว" ไม่ใช่ "บรรทัด" — fgetcsv คืน 1 record ต่อครั้ง แต่โน้ตที่มีขึ้นบรรทัดใหม่
        // อยู่ในเครื่องหมายคำพูดกินหลายบรรทัดในไฟล์ ตัวเลขนี้จึงตรงกับเลขแถวใน Excel
        // (ซึ่งเป็นสิ่งที่ผู้ใช้เปิดดูจริง) ไม่ตรงกับเลขบรรทัดของไฟล์ดิบ
        $rowNumber = 1; // นับรวมแถวหัวตารางแล้ว

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rowNumber++;

            if (!is_array($cells)) {
                continue;
            }

            $cellAt = static function (?int $index) use ($cells): string {
                if ($index === null || !array_key_exists($index, $cells)) {
                    return '';
                }

                return trim((string)($cells[$index] ?? ''));
            };

            // แถวว่างสนิท → ข้าม
            $nonEmpty = array_filter($cells, static fn($cell): bool => trim((string)$cell) !== '');
            if ($nonEmpty === []) {
                continue;
            }

            $rawDate = $cellAt($columnMap['record_date']);

            // แถวรวมท้ายตาราง / แถวที่ไม่มีวันที่ → ข้าม
            if ($rawDate === '' || in_array(mb_strtolower($rawDate), self::IMPORT_TOTAL_LABELS, true)) {
                continue;
            }

            if ($this->isAmbiguousSlashDate($rawDate)) {
                fclose($handle);
                return $fail(
                    'แถวที่ ' . $rowNumber . ': วันที่ "' . $rawDate . '" กำกวม '
                    . '(อ่านได้ทั้งวัน/เดือน และเดือน/วัน) — กรุณาใช้รูปแบบ ปี-เดือน-วัน เช่น 2026-08-03'
                );
            }

            $recordDate = $this->parseImportDate($rawDate);
            if ($recordDate === null) {
                fclose($handle);
                return $fail('แถวที่ ' . $rowNumber . ': อ่านวันที่ "' . $rawDate . '" ไม่ได้');
            }

            $rawRevenue = $cellAt($columnMap['revenue']);
            $rawAdCost = $cellAt($columnMap['ad_cost']);

            // ช่องว่าง = 0 — วันที่ไม่ได้ยิงแอด/ไม่มีรายได้เป็นเรื่องปกติ และ UI ก็ปล่อยว่างได้
            //
            // ⚠️ ต้องจำไว้ด้วยว่า 0 นี้มาจาก "ช่องว่าง" ไม่ใช่ "ผู้ใช้พิมพ์ 0" — ชั้นบันทึก
            // ใช้แยกสองกรณีนี้: ทับวันที่มีข้อมูลอยู่แล้วด้วยช่องว่าง = อุบัติเหตุ ต้องปฏิเสธ
            $revenueWasBlank = trim($rawRevenue) === '';
            $adCostWasBlank = trim($rawAdCost) === '';

            // ใช้ตัวแปลงกลางตัวเดียวกับฟอร์ม — อ่านไม่ได้/กำกวม = ปฏิเสธพร้อมบอกค่าที่ผู้ใช้เห็นจริง
            $revenue = $revenueWasBlank ? '0' : normalize_money_string($rawRevenue);
            $adCost = $adCostWasBlank ? '0' : normalize_money_string($rawAdCost);

            if ($revenue === null) {
                fclose($handle);
                return $fail('แถวที่ ' . $rowNumber . ': รายได้ "' . $rawRevenue . '" ' . self::AMOUNT_FORMAT_HINT);
            }

            if ($adCost === null) {
                fclose($handle);
                return $fail('แถวที่ ' . $rowNumber . ': ค่าแอด "' . $rawAdCost . '" ' . self::AMOUNT_FORMAT_HINT);
            }

            $note = isset($columnMap['note']) ? $cellAt($columnMap['note']) : '';
            $note = $this->stripExportFormulaGuard($note);

            $rows[] = [
                // เลขบรรทัดจริงในไฟล์ — ชั้นบันทึกจะได้ชี้แถวที่ผู้ใช้เปิดเจอ ไม่ใช่ลำดับ
                // ใน array ที่ตัดหัวตาราง/แถวว่าง/แถวรวม/แถวไม่มีวันที่ ออกไปแล้ว
                'row_number' => $rowNumber,
                'record_date' => $recordDate,
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'note' => $note === '' ? null : $note,
                'revenue_was_blank' => $revenueWasBlank,
                'ad_cost_was_blank' => $adCostWasBlank,
                // ไฟล์ไม่มีคอลัมน์โน้ตเลย = ไม่ได้ตั้งใจล้างโน้ตของวันนั้น
                'note_was_missing' => !isset($columnMap['note']),
            ];
        }

        fclose($handle);

        if ($rows === []) {
            return $fail('ไม่พบแถวข้อมูลที่นำเข้าได้');
        }

        return [
            'success' => true,
            'rows' => $rows,
            'error' => null,
        ];
    }

    /**
     * parse วันที่จาก CSV — ISO · D/M/YYYY · "2 ส.ค. 2569" (+ แปลง พ.ศ.)
     * คืน null ถ้าอ่านไม่ได้
     */
    /**
     * วันที่แบบ x/y/zzzz ที่เลขทั้งสองตัวไม่เกิน 12 อ่านได้สองทาง
     *
     * ระบบตีความเป็น วัน/เดือน เสมอ แต่ Excel ภาษาอังกฤษเขียน เดือน/วัน → "3/8/2026"
     * เข้าเป็น 3 ส.ค. ทั้งที่ไฟล์หมายถึง 8 มี.ค. โดยไม่มีสัญญาณเตือน
     * ปฏิเสธไปเลยดีกว่าให้ข้อมูลเข้าผิดเดือนเงียบ ๆ (ปี พ.ศ. ไม่ได้ช่วยแก้ความกำกวมนี้)
     */
    /**
     * seam "วันนี้" ที่ใช้ร่วมกันทุกเมธอดที่ต้องรู้ว่าวันไหนผ่านมาแล้ว
     * ส่ง Y-m-d ที่ถูกต้องมา = ใช้ค่านั้น · ส่งอย่างอื่นหรือไม่ส่ง = วันนี้จริง
     *
     * เดิมบล็อกนี้ถูกคัดลอกไว้ 4 ที่ — รวมไว้จุดเดียวเพื่อไม่ให้แก้ที่หนึ่งแล้วลืมอีกสามที่
     */
    /**
     * ค่าเงินนี้มีทศนิยมเกินที่คอลัมน์เก็บได้ไหม (DECIMAL(12,2) = 2 ตำแหน่ง)
     *
     * ⚠️ ห้ามเทียบสตริงตรง ๆ — 0.1 + 0.2 ในเลขทศนิยมฐานสองได้ 0.30000000000000004
     * ค่าที่ผู้ใช้ตั้งใจว่าเป็น 2 ตำแหน่งจะถูกปฏิเสธผิด ๆ · เทียบกับค่าที่ปัดแล้วแทน
     * โดยเผื่อคลาดเคลื่อนระดับ floating point ไว้
     *
     * ที่ต้องมีเพราะ MySQL จะปัดให้เงียบ ๆ แล้วระบบรายงานว่า "บันทึกเรียบร้อยแล้ว"
     * ทั้งที่ตัวเลขที่เก็บไม่ใช่ตัวเลขที่ผู้ใช้กรอก (และถ้าไม่ใช่ strict mode ก็ตัดเงียบเช่นกัน)
     */
    public static function hasTooManyDecimals(float $amount): bool
    {
        if (!is_finite($amount)) {
            return false;
        }

        return abs($amount - round($amount, self::AMOUNT_DECIMALS)) > 1e-9;
    }

    private function resolveToday(?string $today): string
    {
        $input = is_string($today) ? trim($today) : '';
        $object = $input !== ''
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $input)
            : false;

        if (!$object || $object->format('Y-m-d') !== $input) {
            $object = new DateTimeImmutable('today');
        }

        return $object->format('Y-m-d');
    }

    private function isAmbiguousSlashDate(string $raw): bool
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($raw), $matched) !== 1) {
            return false;
        }

        return (int)$matched[1] <= 12 && (int)$matched[2] <= 12;
    }

    /**
     * ถอด ' ที่ export เติมไว้กัน formula injection — เฉพาะกรณีที่ export เติมจริงเท่านั้น
     *
     * api/export.php เติม ' ให้เซลล์ที่ขึ้นต้นด้วย = + - @ \t \r การถอดทุกครั้งที่เจอ '
     * ทำให้โน้ตที่ผู้ใช้พิมพ์ ' นำหน้าเอง เสียอักขระตัวแรกไปถาวรเมื่อ export→import
     */
    private function stripExportFormulaGuard(string $value): string
    {
        if ($value === '' || $value[0] !== "'") {
            return $value;
        }

        $rest = substr($value, 1);

        return preg_match('/^[=+\-@\t\r]/', $rest) === 1 ? $rest : $value;
    }

    private function parseImportDate(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matched) === 1) {
            return $this->buildImportDate((int)$matched[1], (int)$matched[2], (int)$matched[3]);
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matched) === 1) {
            return $this->buildImportDate((int)$matched[3], (int)$matched[2], (int)$matched[1]);
        }

        // รูปแบบไทยจาก export เช่น "2 ส.ค. 2569"
        if (preg_match('/^(\d{1,2})\s+(\S+)\s+(\d{4})$/u', $value, $matched) === 1) {
            $month = self::THAI_MONTH_ABBREVIATIONS[$matched[2]] ?? null;
            if ($month !== null) {
                return $this->buildImportDate((int)$matched[3], $month, (int)$matched[1]);
            }
        }

        return null;
    }

    private function buildImportDate(int $year, int $month, int $day): ?string
    {
        if ($year >= 2400 && $year <= 2700) {
            $year -= 543; // พ.ศ. → ค.ศ.
        }

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

        // กันวันที่ที่ไม่มีจริง เช่น 31 ก.พ.
        return ($dateObject && $dateObject->format('Y-m-d') === $candidate) ? $candidate : null;
    }

    /** ตัด ฿ / comma / ช่องว่าง / leading ' (จาก guard formula injection ตอน export) */
    /**
     * บันทึกรายวันหลายแถวในครั้งเดียว (atomic — สำเร็จทั้งหมด หรือไม่เขียนเลย)
     *
     * @param array<int,array{row_number?: mixed, record_date?: mixed, revenue?: mixed, ad_cost?: mixed, note?: mixed}> $rows
     *        row_number = เลขแถวที่ผู้ใช้เห็น (ไม่ส่งมาก็นับตามลำดับใน $rows)
     * @param int|null $maxRows เพดานจำนวนแถว — null = BULK_MAX_ROWS (ฟอร์มกรอกมือ)
     */
    public function upsertManyRecords(int $userId, int $shopId, array $rows, ?int $maxRows = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        // ตัดแถวว่างสนิททิ้ง (ผู้ใช้เว้นแถวไว้ในตารางได้)
        $filledRows = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $recordDate = trim((string)($row['record_date'] ?? ''));
            $revenueRaw = trim((string)($row['revenue'] ?? ''));
            $adCostRaw = trim((string)($row['ad_cost'] ?? ''));
            $noteRaw = trim((string)($row['note'] ?? ''));

            if ($recordDate === '' && $revenueRaw === '' && $adCostRaw === '' && $noteRaw === '') {
                continue;
            }

            $filledRows[] = [
                // ผู้เรียกส่งเลขแถวที่ผู้ใช้เห็นมาได้ (ฟอร์ม bulk ตัดแถวที่ไม่ได้กรอกออกก่อนส่ง
                // ลำดับใน payload จึงไม่ตรงกับบนหน้าจอ) ไม่ส่งมาก็นับตามลำดับเหมือนเดิม
                'row_number' => (int)($row['row_number'] ?? 0) > 0
                    ? (int)$row['row_number']
                    : (int)$index + 1,
                'record_date' => $recordDate,
                'revenue' => $row['revenue'] ?? null,
                'ad_cost' => $row['ad_cost'] ?? null,
                'note' => $noteRaw === '' ? null : $noteRaw,
                // มาจาก parser ของ CSV เท่านั้น — ทางตารางกรอกหลายวันไม่เคยตั้งธงนี้
                'revenue_was_blank' => ($row['revenue_was_blank'] ?? false) === true,
                'ad_cost_was_blank' => ($row['ad_cost_was_blank'] ?? false) === true,
                'note_was_missing' => ($row['note_was_missing'] ?? false) === true,
            ];
        }

        if ($filledRows === []) {
            return [
                'success' => false,
                'error' => 'กรุณากรอกข้อมูลอย่างน้อย 1 แถว',
            ];
        }

        $rowLimit = ($maxRows !== null && $maxRows > 0) ? $maxRows : self::BULK_MAX_ROWS;
        if (count($filledRows) > $rowLimit) {
            return [
                'success' => false,
                'error' => 'กรอกได้สูงสุด ' . $rowLimit . ' แถวต่อครั้ง',
            ];
        }

        // validate ทุกแถวให้ครบก่อน แล้วค่อยเขียน (กันเขียนครึ่ง ๆ กลาง ๆ)
        $payloads = [];
        $seenDates = [];
        /** @var array<string,array{row_number:int,revenue:bool,ad_cost:bool}> $blankCells */
        $blankCells = [];
        foreach ($filledRows as $row) {
            $rowNumber = (int)$row['row_number'];

            // ⚠️⚠️ ต้องใช้กติกากลาง (`parse_decimal_input` → `normalize_money_string`)
            // ไม่ใช่ `is_numeric()`
            //
            // `is_numeric()` รับค่าที่กติกากลาง **ตั้งใจปฏิเสธเพราะอ่านได้สองแบบ**:
            //   `1.000` → ผ่าน แล้วบันทึกเป็น ฿1.00 (ผู้ใช้ที่ใช้รูปแบบยุโรปหมายถึงหนึ่งพัน)
            //   `1e3`   → ผ่าน แล้วบันทึกเป็น ฿1,000
            // ฟอร์มเดี่ยวกับการนำเข้า CSV ปฏิเสธทั้งคู่ · ตารางกรอกหลายวันกลับบันทึกให้
            // พร้อมข้อความ "สำเร็จ" — ยอดผิดพันเท่าโดยไม่มีอะไรเตือน (วัดจริงแล้ว)
            //
            // controller ส่งค่าที่ parse ไม่ผ่านมาเป็น "สตริงดิบ" เพื่อให้รายงานเลขแถวได้
            // ตรงนี้จึงเป็นด่านจริง และต้องเข้มเท่ากติกากลาง ไม่ใช่หลวมกว่า
            $revenueParsed = parse_decimal_input($row['revenue']);
            $adCostParsed = parse_decimal_input($row['ad_cost']);

            if (($revenueParsed['valid'] ?? false) !== true || ($adCostParsed['valid'] ?? false) !== true) {
                return [
                    'success' => false,
                    'error' => 'แถวที่ ' . $rowNumber . ': กรุณากรอกรายได้และค่าแอดให้ถูกต้อง',
                ];
            }

            $validation = $this->validateRecordPayload(
                (string)$row['record_date'],
                (float)$revenueParsed['value'],
                (float)$adCostParsed['value'],
                $row['note']
            );

            if (($validation['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'error' => 'แถวที่ ' . $rowNumber . ': ' . (string)($validation['error'] ?? 'ข้อมูลไม่ถูกต้อง'),
                ];
            }

            $payload = (array)$validation['data'];
            $recordDate = (string)$payload['record_date'];

            if (isset($seenDates[$recordDate])) {
                return [
                    'success' => false,
                    'error' => 'มีวันที่ซ้ำกันในตาราง (' . $recordDate . ') กรุณากรอกวันละ 1 แถว',
                ];
            }

            $seenDates[$recordDate] = true;
            $payloads[] = $payload;

            // ช่องที่ "เว้นว่าง" — ถ้าวันนั้นมีข้อมูลอยู่แล้ว การเขียนทับจะลบของจริงทิ้ง
            // โดยที่ผู้ใช้ตั้งใจแค่ "ไม่แก้ช่องนี้" · เก็บไว้เช็กทีเดียวหลังลูป
            // (ต้องรู้ช่วงวันทั้งหมดก่อนจึงจะ query ครั้งเดียวได้)
            //
            // โน้ตนับรวมด้วยและใช้ได้กับ "ทุกทาง" ไม่ใช่เฉพาะ CSV — ตารางกรอกหลายวัน
            // เคยลบโน้ตของวันเดิมทิ้งเงียบ ๆ แล้วตอบว่าบันทึกสำเร็จ
            // ⚠️ โน้ตนับเป็น "ช่องว่างที่ไม่ตั้งใจ" เฉพาะเมื่อ **ไม่มีคอลัมน์โน้ตในไฟล์เลย**
            //
            // ถ้าช่องมีอยู่แต่ผู้ใช้ลบข้อความออก = ตั้งใจล้าง ต้องทำได้ (ตารางกรอกหลายวัน
            // เติมโน้ตเดิมมาให้เห็นก่อนแล้ว) · เดิมเช็กจาก "ค่าที่ส่งมาว่างไหม" จึงล้างโน้ต
            // ไม่ได้เลยสักทาง และไฟล์ CSV 3 คอลัมน์ถูกปฏิเสธทั้งไฟล์
            $noteColumnMissing = ($row['note_was_missing'] ?? false) === true;

            if (($row['revenue_was_blank'] ?? false) === true
                || ($row['ad_cost_was_blank'] ?? false) === true
                || $noteColumnMissing
            ) {
                $blankCells[$recordDate] = [
                    'row_number' => $rowNumber,
                    'revenue' => ($row['revenue_was_blank'] ?? false) === true,
                    'ad_cost' => ($row['ad_cost_was_blank'] ?? false) === true,
                    'note' => $noteColumnMissing,
                ];
            }
        }

        $startedTransaction = false;
        $canLockRows = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                $canLockRows = $this->db->inTransaction();
                if ($canLockRows) {
                    // จองแถวร้านครั้งเดียวก่อนเขียนทั้งชุด — ลำดับเดียวกับตอนลบร้าน
                    if (!$this->shopRepository->lockForWrite($shopId, $userId)) {
                        // ⚠️ เช่นเดียวกับ upsertRecord: ยกเลิกได้เฉพาะทรานแซกชันของตัวเอง
                        if ($startedTransaction && $this->db->inTransaction()) {
                            $this->db->rollBack();
                        }

                        return self::shopVanishedResult();
                    }
                }
            }

            // ⚠️ ตรวจ "ช่องว่างจะทับของเดิมไหม" **ข้างใน** transaction เท่านั้น
            //
            // เดิมตรวจก่อนเปิด transaction — ระหว่างที่ผู้ใช้กำลังนำเข้าไฟล์ ถ้าอีกแท็บ
            // (หรือมือถืออีกเครื่อง) บันทึกวันเดียวกันแทรกเข้ามา ตัวกันจะเห็นภาพเก่าว่า
            // "วันนั้นยังว่าง" แล้วปล่อยให้เขียนทับยอดที่เพิ่งลงไปด้วยศูนย์ พร้อมบอกว่าสำเร็จ
            // ตอนนี้อ่านหลังจองแถวร้านแล้ว จึงเห็นภาพเดียวกับตอนที่กำลังจะเขียนจริง
            $overwriteCheck = $this->rejectBlankCellsOverwritingExistingDays($shopId, $blankCells);
            if ($overwriteCheck !== null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return $overwriteCheck;
            }

            // ⚠️ เขียนเรียงตามวันที่เสมอ ไม่ใช่ตามลำดับที่ผู้ใช้พิมพ์
            //
            // เดิมเขียนตามลำดับในตาราง — สองแท็บที่บันทึกวันชุดเดียวกันแต่เรียงคนละแบบ
            // (แท็บ A: 1 ส.ค. แล้ว 5 ส.ค. · แท็บ B: 5 ส.ค. แล้ว 1 ส.ค.) จะจองแถวไขว้กัน
            // แล้ว MySQL ตัดฝ่ายหนึ่งทิ้ง ผู้ใช้เห็นแค่ "ไม่สามารถบันทึกข้อมูลได้"
            // ทั้งที่ไม่มีอะไรผิด · เรียงให้ทุกคำขอหยิบทางเดียวกัน = ไขว้กันไม่ได้
            usort(
                $payloads,
                static fn(array $a, array $b): int => strcmp((string)$a['record_date'], (string)$b['record_date'])
            );

            foreach ($payloads as $payload) {
                $this->recordRepository->upsert(
                    $shopId,
                    (string)$payload['record_date'],
                    (float)$payload['revenue'],
                    (float)$payload['ad_cost'],
                    $payload['note']
                );
            }

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[record] upsertManyRecords failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => write_failure_message($exception, 'ไม่สามารถบันทึกข้อมูลได้'),
            ];
        }

        $savedCount = count($payloads);

        return [
            'success' => true,
            'message' => 'บันทึกข้อมูล ' . $savedCount . ' วันเรียบร้อยแล้ว',
            'saved_count' => $savedCount,
        ];
    }

    public function updateRecord(
        int $userId,
        int $shopId,
        int $recordId,
        string $recordDate,
        float $revenue,
        float $adCost,
        ?string $note
    ): array {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if ($recordId <= 0) {
            return [
                'success' => false,
                'error' => 'ไม่พบรายการที่ต้องการแก้ไข',
            ];
        }

        $validation = $this->validateRecordPayload($recordDate, $revenue, $adCost, $note);
        if (($validation['success'] ?? false) !== true) {
            return $validation;
        }

        $payload = $validation['data'];

        $startedTransaction = false;
        try {
            if ($this->db instanceof PDO && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            if ($this->db instanceof PDO && $this->db->inTransaction()) {
                // ⚠️ ต้องจองก่อนเหมือนทางเขียนอื่น ๆ — เมธอดนี้ยังจองแถว "วัน" ที่จะย้ายไป
                // ด้วย FOR UPDATE (เช็กว่าวันนั้นมีข้อมูลอยู่แล้วไหม) ซึ่งกลายเป็น gap lock
                // เมื่อวันนั้นยังว่าง สองแท็บที่ย้ายรายการไปคนละวันว่างจึงเคยชนกันได้
                if (!$this->shopRepository->lockForWrite($shopId, $userId)) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }

                    return self::shopVanishedResult();
                }
            }

            $existingRecord = $this->recordRepository->findByIdAndShopIdForUpdate($recordId, $shopId);
            if ($existingRecord === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบรายการที่ต้องการแก้ไข',
                ];
            }

            $oldDate = (string)($existingRecord['record_date'] ?? '');
            $newDate = (string)$payload['record_date'];

            if ($oldDate !== $newDate) {
                $conflictRecord = $this->recordRepository->findByShopIdAndRecordDateForUpdate($shopId, $newDate);
                if ($conflictRecord !== null && (int)($conflictRecord['id'] ?? 0) !== $recordId) {
                    if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }

                    return [
                        'success' => false,
                        'error' => 'วันที่ที่เลือกมีข้อมูลอยู่แล้ว กรุณาแก้ไขรายการของวันที่ดังกล่าวแทน',
                    ];
                }
            }

            $this->recordRepository->updateByIdAndShopId(
                $recordId,
                $shopId,
                $newDate,
                (float)$payload['revenue'],
                (float)$payload['ad_cost'],
                $payload['note']
            );

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (PDOException $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ((string)$exception->getCode() === '23000') {
                return [
                    'success' => false,
                    'error' => 'วันที่ที่เลือกมีข้อมูลอยู่แล้ว กรุณาแก้ไขรายการของวันที่ดังกล่าวแทน',
                ];
            }

            error_log('[record] updateRecord failed: ' . $exception->getMessage());

            return [
                'success' => false,
                // ชนคิวกับอีกหน้าจอ = บอกให้กดใหม่ ไม่ใช่ข้อความลอย ๆ ที่อ่านแล้วนึกว่าระบบพัง
                'error' => write_failure_message($exception, 'ไม่สามารถแก้ไขรายการได้'),
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[record] updateRecord failed: ' . $exception->getMessage());

            return [
                'success' => false,
                // ชนคิวกับอีกหน้าจอ = บอกให้กดใหม่ ไม่ใช่ข้อความลอย ๆ ที่อ่านแล้วนึกว่าระบบพัง
                'error' => write_failure_message($exception, 'ไม่สามารถแก้ไขรายการได้'),
            ];
        }

        return [
            'success' => true,
            'message' => 'แก้ไขรายการเรียบร้อยแล้ว',
        ];
    }

    /**
     * ข้อมูลของวันเดียว — ไว้ให้ฟอร์มเติมค่าเดิมก่อนให้ผู้ใช้แก้
     *
     * ⚠️ จำเป็นเพราะการบันทึกเป็น upsert ที่เขียนทับทุกช่องเสมอ ถ้าฟอร์มไม่เติมโน้ตเดิม
     * กลับมา การแก้แค่ยอดขายจะลบโน้ตของวันนั้นทิ้งไปด้วย (หน้าประวัติกับตารางกรอก
     * หลายวันเติมค่าเดิมอยู่แล้ว — ฟอร์มหลักเป็นจุดเดียวที่ตกหล่น)
     *
     * @return array{success:bool,data?:array<string,mixed>|null,error?:string}
     */
    /**
     * ปฏิเสธการนำเข้าเมื่อ "ช่องว่างในไฟล์" จะไปทับวันที่มีข้อมูลอยู่แล้ว
     *
     * กติกา "ช่องว่าง = 0" ใช้กับวันใหม่ (ไม่ได้ยิงแอด/ไม่มียอด เป็นเรื่องปกติ) แต่ถ้าวันนั้น
     * มีตัวเลขอยู่แล้ว การเขียนทับด้วย 0 เกือบทุกครั้งคืออุบัติเหตุตอนแก้ไฟล์ใน Excel
     * — ตัดสินแล้วว่าให้ปฏิเสธทั้งไฟล์พร้อมบอกแถว ดีกว่าลบยอดจริงแล้วรายงานว่าสำเร็จ
     *
     * ผู้ใช้ที่ตั้งใจให้เป็น 0 จริง ๆ ยังพิมพ์ 0 ลงไปได้ตามปกติ
     *
     * @param array<string,array{row_number:int,revenue:bool,ad_cost:bool}> $blankCells
     * @return array{success:bool,error:string}|null null = ผ่าน
     */
    private function rejectBlankCellsOverwritingExistingDays(int $shopId, array $blankCells): ?array
    {
        if ($blankCells === []) {
            return null;
        }

        $dates = array_keys($blankCells);
        sort($dates);

        try {
            $existing = $this->recordRepository->getByDateRange($shopId, $dates[0], $dates[count($dates) - 1]);
        } catch (Throwable $exception) {
            error_log('[record] blank-cell overwrite check failed: ' . $exception->getMessage());

            return [
                'success' => false,
                'error' => 'ไม่สามารถตรวจสอบข้อมูลเดิมได้ กรุณาลองใหม่อีกครั้ง',
            ];
        }

        $existingByDate = [];
        foreach ($existing as $row) {
            $existingByDate[(string)($row['record_date'] ?? '')] = $row;
        }

        // รวบรวมทุกแถวที่มีปัญหา แล้วรายงาน "แถวแรกตามที่ผู้ใช้เห็น" (เลขแถวน้อยสุด)
        // เดิมไล่ตามลำดับวัน ทำให้ไฟล์ที่เรียงวันใหม่ก่อนชี้ไปที่บรรทัดท้ายไฟล์
        $problems = [];

        foreach ($dates as $date) {
            $row = $existingByDate[$date] ?? null;
            if ($row === null) {
                continue;
            }

            $blank = $blankCells[$date];
            $fields = [];

            // ⚠️ เตือนเฉพาะเมื่อ "มีของจริงจะหาย" — ค่าเดิมที่เป็น 0 อยู่แล้วเขียนทับด้วย 0
            // ไม่ได้ทำให้ข้อมูลหาย การปฏิเสธจึงเป็นการขวางงานที่ไม่มีผลอะไร
            $existingRevenue = (float)($row['revenue'] ?? 0);
            $existingAdCost = (float)($row['ad_cost'] ?? 0);
            $existingNote = trim((string)($row['note'] ?? ''));

            if ($blank['revenue'] && $existingRevenue > 0) {
                $fields[] = 'รายได้ (มียอด ' . number_format($existingRevenue, 2) . ' อยู่แล้ว)';
            }
            if ($blank['ad_cost'] && $existingAdCost > 0) {
                $fields[] = 'ค่าแอด (มียอด ' . number_format($existingAdCost, 2) . ' อยู่แล้ว)';
            }
            if (($blank['note'] ?? false) && $existingNote !== '') {
                $fields[] = 'โน้ต (มีข้อความ "' . $existingNote . '" อยู่แล้ว)';
            }

            if ($fields === []) {
                continue;
            }

            $problems[] = [
                'row_number' => (int)$blank['row_number'],
                'date' => $date,
                'fields' => $fields,
            ];
        }

        if ($problems === []) {
            return null;
        }

        usort($problems, static fn(array $a, array $b): int => $a['row_number'] <=> $b['row_number']);
        $first = $problems[0];

        return [
            'success' => false,
            'error' => 'แถวที่ ' . $first['row_number'] . ': เว้นช่อง' . implode(' และ ', $first['fields'])
                . ' — วันที่ ' . $first['date'] . ' มีข้อมูลอยู่แล้ว ระบบไม่บันทึกทั้งชุดเพื่อไม่ให้ของเดิมหาย '
                . 'ถ้าต้องการล้างค่าเดิมจริง ๆ กรุณากรอก 0 (หรือแก้โน้ต) ลงในช่องนั้น',
        ];
    }

    public function getRecordForDate(int $userId, int $shopId, string $recordDate): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $date = trim($recordDate);
        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
            return [
                'success' => false,
                'error' => 'รูปแบบวันที่ต้องเป็น YYYY-MM-DD',
            ];
        }

        try {
            $records = $this->recordRepository->getByDateRange($shopId, $date, $date);
        } catch (Throwable $exception) {
            error_log('[record] getRecordForDate failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลของวันนี้ได้',
            ];
        }

        $row = $records[0] ?? null;
        if (!is_array($row)) {
            return ['success' => true, 'data' => null];
        }

        return [
            'success' => true,
            'data' => [
                'record_date' => (string)($row['record_date'] ?? $date),
                'revenue' => (float)($row['revenue'] ?? 0),
                'ad_cost' => (float)($row['ad_cost'] ?? 0),
                'note' => (string)($row['note'] ?? ''),
            ],
        ];
    }

    public function getRecentRecords(int $userId, int $shopId, int $limit = 7): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [];
        }

        return $this->recordRepository->getRecentByShopId($shopId, $limit);
    }

    /**
     * ดึง record ดิบตามช่วงวันที่ (ใช้กับ export ทั้งปี — ยิงครั้งเดียว ไม่ใช่รายเดือน 12 รอบ)
     * ไม่คำนวณ compare/roas ให้ ปล่อยให้ชั้นบนจัดรูปเอง
     *
     * @return array{success:bool,data?:array<int,array<string,mixed>>,error?:string}
     */
    public function getRecordsByDateRange(int $userId, int $shopId, string $startDate, string $endDate): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        foreach ([$startDate, $endDate] as $date) {
            $dateObject = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                return [
                    'success' => false,
                    'error' => 'รูปแบบวันที่ต้องเป็น YYYY-MM-DD',
                ];
            }
        }

        if ($startDate > $endDate) {
            return [
                'success' => false,
                'error' => 'ช่วงวันที่ไม่ถูกต้อง',
            ];
        }

        return [
            'success' => true,
            'data' => $this->recordRepository->getByDateRange($shopId, $startDate, $endDate),
        ];
    }

    /**
     * ยอดรวมรายเดือนตามช่วงเดือน (ใช้กับ export sheet รายเดือน — ยิงครั้งเดียวคลุมทั้งช่วง)
     *
     * @return array{success:bool,data?:array<int,array<string,mixed>>,error?:string}
     */
    public function getMonthlyTotals(int $userId, int $shopId, string $startMonth, string $endMonth): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        foreach ([$startMonth, $endMonth] as $month) {
            if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                return [
                    'success' => false,
                    'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
                ];
            }
        }

        return [
            'success' => true,
            'data' => $this->recordRepository->getMonthlyTotalsByMonthRange($shopId, $startMonth, $endMonth),
        ];
    }

    public function getMonthlyRecords(int $userId, int $shopId, string $month): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $startDate = $month . '-01';
        $dateObject = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $startDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $endDate = $dateObject->format('Y-m-t');
        $records = $this->recordRepository->getByDateRange($shopId, $startDate, $endDate);

        $mappedRecords = [];
        $previousRevenue = null;
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;

        foreach ($records as $record) {
            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);
            $profit = $revenue - $adCost;
            $roas = $adCost > 0 ? round($revenue / $adCost, 2) : null;

            $compareRevenuePercent = null;
            if ($previousRevenue !== null && $previousRevenue > 0) {
                $compareRevenuePercent = round((($revenue - $previousRevenue) / $previousRevenue) * 100, 1);
            }

            $mappedRecords[] = [
                'id' => (int)($record['id'] ?? 0),
                'record_date' => (string)($record['record_date'] ?? ''),
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'profit' => $profit,
                'roas' => $roas,
                'compare_revenue_percent' => $compareRevenuePercent,
                'note' => (string)($record['note'] ?? ''),
            ];

            $previousRevenue = $revenue;
            $totalRevenue += $revenue;
            $totalAdCost += $adCost;
        }

        $totalProfit = $totalRevenue - $totalAdCost;

        return [
            'success' => true,
            'data' => [
                'month' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'records' => $mappedRecords,
                'totals' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'total_profit' => $totalProfit,
                    // ratio of sums — ไม่ใช่ค่าเฉลี่ยของ ROAS รายวัน ให้ตรงกับ dashboard/annual/weekday
                    // (เดิมเฉลี่ย ROAS รายวันและตัดวันที่ ad_cost = 0 ออกจากตัวหาร → ตัวเลขไม่ตรงกับหน้าอื่น
                    //  และรายได้ของวันที่ไม่ได้ยิงแอดหายไปจากตัวตั้งทั้งวัน)
                    'avg_roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                ],
            ],
        ];
    }

    /**
     * เทียบยอดของวันหนึ่ง กับ "วันเดียวกันในสัปดาห์" วันอื่น ๆ ของเดือนนั้น
     *
     * ใช้ตอบว่า "ยอดวันนี้ตกเพราะเป็นวันที่คนซื้อน้อยอยู่แล้ว หรือผิดปกติ"
     * avg_roas คิดแบบ ratio of sums (รวมรายได้ ÷ รวมค่าแอด) ไม่ใช่เฉลี่ยของ ROAS รายวัน
     *
     * @param string|null $targetDate รูปแบบ Y-m-d — ไม่ส่ง = วันล่าสุดที่กรอกไว้
     */
    public function getWeekdayContext(int $userId, int $shopId, ?string $targetDate = null, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $emptyResult = [
            'success' => true,
            'data' => [
                'has_data' => false,
                'target_date' => null,
                'weekday' => null,
                'target_revenue' => null,
                'target_profit' => null,
                'target_roas' => null,
                'sample_count' => 0,
                'avg_revenue' => null,
                'avg_profit' => null,
                'avg_roas' => null,
                'comparable' => false,
                'trend_reliable' => false,
            ],
        ];

        $resolvedDate = is_string($targetDate) ? trim($targetDate) : '';
        if ($resolvedDate !== '') {
            $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $resolvedDate);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $resolvedDate) {
                return [
                    'success' => false,
                    'error' => 'รูปแบบวันที่ไม่ถูกต้อง',
                ];
            }
        } else {
            // ไม่ระบุ → ใช้วันล่าสุด "ที่ผ่านมาแล้ว" ไม่ใช่ record_date มากที่สุด
            // (ระบบอนุญาตให้บันทึกวันอนาคต แถวพวกนั้นจะลอยขึ้นบนสุดของ ORDER BY เสมอ)
            // เกณฑ์เดียวกับ getDaysSinceLastRecord — แก้ที่นี่ต้องดูที่นั่นด้วย
            try {
                $latest = $this->recordRepository
                    ->findLatestOnOrBeforeDate($shopId, $this->resolveToday($today));
            } catch (Throwable $exception) {
                error_log('[record] getWeekdayContext failed: ' . $exception->getMessage());
                return [
                    'success' => false,
                    'error' => 'ไม่สามารถโหลดข้อมูลเปรียบเทียบได้',
                ];
            }

            $resolvedDate = is_array($latest) ? trim((string)($latest['record_date'] ?? '')) : '';
            if ($resolvedDate === '') {
                return $emptyResult;
            }

            $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $resolvedDate);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $resolvedDate) {
                return $emptyResult;
            }
        }

        $dateObject = $dateObject->setTime(0, 0, 0);
        $weekday = (int)$dateObject->format('N');
        $monthStart = $dateObject->format('Y-m-01');

        // ⚠️⚠️ ต้องจบที่วันนี้ ไม่ใช่สิ้นเดือน — ระบบให้ลงข้อมูลวันล่วงหน้าได้
        //
        // ตาราง "กำไรเฉลี่ยตามวัน" ที่อยู่ใต้การ์ดนี้ในหน้าเดียวกันใช้ช่วงที่จบที่วันนี้
        // (`resolveWeekdayWindow()`) · ถ้าการ์ดไล่ทั้งเดือน สองบรรทัดบนจอเดียวกัน
        // จะใช้คำว่า "เดือนนี้" เหมือนกันแต่คนละฐาน
        //
        // วัดจริง: วันนี้ ศ. 7 ส.ค. · กรอกจริง จ.3 ส.ค. ฿1,000 · ลงล่วงหน้าไว้
        // จ.10, 17, 24 วันละ ฿9,000 →
        //   การ์ด  : "เฉลี่ยจันทร์ของเดือนนี้ ฿9,000" + ป้าย "ต่ำกว่าจันทร์ปกติ"
        //   ตาราง  : "จันทร์ ฿1,000 จาก 1 วัน"
        // การ์ดฟันธงว่าวันนั้นแย่ โดยเทียบกับวันที่ยังมาไม่ถึง
        $monthEnd = min(
            $dateObject->format('Y-m-t'),
            $this->resolveToday($today)
        );

        try {
            $monthRecords = $this->recordRepository->getByDateRange($shopId, $monthStart, $monthEnd);
        } catch (Throwable $exception) {
            error_log('[record] getWeekdayContext failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลเปรียบเทียบได้',
            ];
        }

        $targetRevenue = 0.0;
        $targetAdCost = 0.0;
        $sampleCount = 0;
        $sampleRevenueTotal = 0.0;
        $sampleAdCostTotal = 0.0;

        foreach ($monthRecords as $record) {
            $recordDate = trim((string)($record['record_date'] ?? ''));
            if ($recordDate === '') {
                continue;
            }

            $recordObject = DateTimeImmutable::createFromFormat('Y-m-d', $recordDate);
            if (!$recordObject || $recordObject->format('Y-m-d') !== $recordDate) {
                continue;
            }

            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);

            if ($recordDate === $resolvedDate) {
                $targetRevenue = $revenue;
                $targetAdCost = $adCost;
                continue; // ตัดตัวเองออกจากกลุ่มเทียบ
            }

            if ((int)$recordObject->format('N') !== $weekday) {
                continue;
            }

            $sampleCount++;
            $sampleRevenueTotal += $revenue;
            $sampleAdCostTotal += $adCost;
        }

        $sampleProfitTotal = $sampleRevenueTotal - $sampleAdCostTotal;

        return [
            'success' => true,
            'data' => [
                'has_data' => true,
                'target_date' => $resolvedDate,
                'weekday' => $weekday,
                'target_revenue' => $targetRevenue,
                'target_profit' => $targetRevenue - $targetAdCost,
                'target_roas' => $targetAdCost > 0 ? round($targetRevenue / $targetAdCost, 2) : null,
                'sample_count' => $sampleCount,
                'avg_revenue' => $sampleCount > 0 ? round($sampleRevenueTotal / $sampleCount, 2) : null,
                'avg_profit' => $sampleCount > 0 ? round($sampleProfitTotal / $sampleCount, 2) : null,
                // ratio of sums — ไม่ใช่ค่าเฉลี่ยของ ROAS รายวัน
                'avg_roas' => ($sampleCount > 0 && $sampleAdCostTotal > 0)
                    ? round($sampleRevenueTotal / $sampleAdCostTotal, 2)
                    : null,
                // มีอะไรให้เทียบไหม (โชว์ค่าเฉลี่ยพร้อมกำกับ "จาก N วัน" ได้)
                'comparable' => $sampleCount >= 1,
                // พอจะฟันธงว่า "สูงกว่า/ต่ำกว่าปกติ" ไหม — 1–2 วันยังเป็นความบังเอิญ
                'trend_reliable' => $sampleCount >= self::WEEKDAY_MIN_SAMPLE,
            ],
        ];
    }

    /**
     * คำนวณช่วงวันที่ของตารางกำไรตามวัน ตามโหมดที่เลือก
     *
     *  - '8w'    → 56 วันล่าสุด (today ย้อนกลับ 55 วัน) = แต่ละวันในสัปดาห์ปรากฏ 8 ครั้ง
     *  - 'month' → ตั้งแต่วันที่ 1 ของเดือน today ถึง today (ไม่นับวันอนาคตที่ยังไม่ถึง)
     *
     * โหมดที่ไม่รู้จัก → fallback เป็น '8w'
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์
     * @return array{mode: string, start_date: string, end_date: string}
     */
    public function resolveWeekdayWindow(string $mode, ?string $today = null): array
    {
        $normalizedMode = $mode === 'month' ? 'month' : '8w';

        $todayObject = (new DateTimeImmutable($this->resolveToday($today)))->setTime(0, 0, 0);

        $endDate = $todayObject->format('Y-m-d');
        $startDate = $normalizedMode === 'month'
            ? $todayObject->format('Y-m-01')
            : $todayObject->modify('-55 days')->format('Y-m-d');

        return [
            'mode' => $normalizedMode,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    /**
     * สรุปกำไรเฉลี่ยแยกตามวันในสัปดาห์ ภายในช่วงวันที่ที่กำหนด
     *
     * ใช้คู่กับ resolveWeekdayWindow() ที่ controller เป็นคนเลือกโหมด
     * avg_roas คิดแบบ ratio of sums (Σรายได้ ÷ Σค่าแอด)
     */
    public function getWeekdayBreakdown(int $userId, int $shopId, string $startDate, string $endDate): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $startObject = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $endObject = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if (!$startObject || $startObject->format('Y-m-d') !== $startDate
            || !$endObject || $endObject->format('Y-m-d') !== $endDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบวันที่ไม่ถูกต้อง',
            ];
        }

        if ($startDate > $endDate) {
            return [
                'success' => false,
                'error' => 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',
            ];
        }

        try {
            $records = $this->recordRepository->getByDateRange($shopId, $startDate, $endDate);
        } catch (Throwable $exception) {
            error_log('[record] getWeekdayBreakdown failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลสรุปตามวันได้',
            ];
        }

        // เตรียมถัง 1..7 ไว้ก่อน เพื่อให้ output เรียง จันทร์→อาทิตย์ เสมอ
        $buckets = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $buckets[$weekday] = [
                'count' => 0,
                'revenue_total' => 0.0,
                'ad_cost_total' => 0.0,
            ];
        }

        $hasData = false;

        foreach ($records as $record) {
            $recordDate = trim((string)($record['record_date'] ?? ''));
            if ($recordDate === '') {
                continue;
            }

            $recordObject = DateTimeImmutable::createFromFormat('Y-m-d', $recordDate);
            if (!$recordObject || $recordObject->format('Y-m-d') !== $recordDate) {
                continue;
            }

            $weekday = (int)$recordObject->format('N');
            if (!isset($buckets[$weekday])) {
                continue;
            }

            $buckets[$weekday]['count']++;
            $buckets[$weekday]['revenue_total'] += (float)($record['revenue'] ?? 0);
            $buckets[$weekday]['ad_cost_total'] += (float)($record['ad_cost'] ?? 0);
            $hasData = true;
        }

        $weekdays = [];
        foreach ($buckets as $weekday => $bucket) {
            $count = (int)$bucket['count'];
            $revenueTotal = (float)$bucket['revenue_total'];
            $adCostTotal = (float)$bucket['ad_cost_total'];
            $profitTotal = $revenueTotal - $adCostTotal;

            $weekdays[] = [
                'weekday' => $weekday,
                'sample_count' => $count,
                'avg_profit' => $count > 0 ? round($profitTotal / $count, 2) : null,
                'avg_revenue' => $count > 0 ? round($revenueTotal / $count, 2) : null,
                'avg_roas' => ($count > 0 && $adCostTotal > 0)
                    ? round($revenueTotal / $adCostTotal, 2)
                    : null,
            ];
        }

        return [
            'success' => true,
            'data' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'has_data' => $hasData,
                'weekdays' => $weekdays,
            ],
        ];
    }

    /**
     * นับจำนวนวันนับจากรายการล่าสุดที่กรอกไว้ (ใช้เตือนว่าไม่ได้กรอกนานแล้ว)
     *
     * แยก 3 เคสให้ชัด:
     *  - ไม่เคยกรอกเลย       → has_records = false, days_since = null
     *  - กรอกวันนี้           → has_records = true,  days_since = 0
     *  - มีแต่รายการล่วงหน้า  → has_records = true,  days_since = null (ไม่เตือน แต่ไม่ชวนกรอกครั้งแรก)
     *
     * นับจาก "วันล่าสุดที่ไม่เกินวันนี้" — รายการที่ลงล่วงหน้า (หรือพิมพ์ปีผิด) จะไม่บังคำเตือน
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์, ไม่ส่ง = วันนี้จริง
     */
    public function getDaysSinceLastRecord(int $userId, int $shopId, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $todayObject = (new DateTimeImmutable($this->resolveToday($today)))->setTime(0, 0, 0);

        try {
            $records = $this->recordRepository->getRecentByShopId($shopId, 1);
        } catch (Throwable $exception) {
            error_log('[record] getDaysSinceLastRecord failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถตรวจสอบข้อมูลล่าสุดได้',
            ];
        }

        $emptyResult = [
            'success' => true,
            'data' => [
                'has_records' => false,
                'last_record_date' => null,
                'days_since' => null,
            ],
        ];

        $lastRecord = $records[0] ?? null;
        if (!is_array($lastRecord)) {
            return $emptyResult;
        }

        $lastDate = trim((string)($lastRecord['record_date'] ?? ''));
        if ($lastDate === '') {
            return $emptyResult;
        }

        $lastObject = DateTimeImmutable::createFromFormat('Y-m-d', $lastDate);
        if (!$lastObject || $lastObject->format('Y-m-d') !== $lastDate) {
            return $emptyResult;
        }
        $lastObject = $lastObject->setTime(0, 0, 0);

        // รายการล่าสุดลงวันที่ล่วงหน้า (ตั้งใจ หรือพิมพ์ปีผิด) → ถอยไปหาวันล่าสุดที่กรอกจริง
        // ไม่งั้นรายการเดียวที่ลงปีหน้าจะกลบคำเตือน "ไม่ได้กรอกนาน" ไปทั้งปี
        if ($lastObject > $todayObject) {
            try {
                $fallbackRecord = $this->recordRepository->findLatestOnOrBeforeDate(
                    $shopId,
                    $todayObject->format('Y-m-d')
                );
            } catch (Throwable $exception) {
                error_log('[record] getDaysSinceLastRecord fallback failed: ' . $exception->getMessage());
                return [
                    'success' => false,
                    'error' => 'ไม่สามารถตรวจสอบข้อมูลล่าสุดได้',
                ];
            }

            $fallbackDate = trim((string)($fallbackRecord['record_date'] ?? ''));
            $fallbackObject = $fallbackDate !== ''
                ? DateTimeImmutable::createFromFormat('Y-m-d', $fallbackDate)
                : false;

            if (!$fallbackObject || $fallbackObject->format('Y-m-d') !== $fallbackDate) {
                // มีแต่รายการล่วงหน้า — มีข้อมูลอยู่จริง จึงไม่ชวนกรอกครั้งแรก แต่ก็ยังไม่มีอะไรให้นับ
                return [
                    'success' => true,
                    'data' => [
                        'has_records' => true,
                        'last_record_date' => null,
                        'days_since' => null,
                    ],
                ];
            }

            $lastDate = $fallbackDate;
            $lastObject = $fallbackObject->setTime(0, 0, 0);
        }

        return [
            'success' => true,
            'data' => [
                'has_records' => true,
                'last_record_date' => $lastDate,
                'days_since' => (int)$lastObject->diff($todayObject)->format('%r%a'),
            ],
        ];
    }

    /**
     * หาวันที่ "ยังไม่ได้กรอก" ของเดือนที่เลือก
     *
     * ช่วงที่พิจารณา = วันที่ 1 ของเดือน ถึง "วันตัด":
     *  - เดือนปัจจุบัน → วันตัด = today (ไม่นับวันอนาคต)
     *  - เดือนอดีต     → วันตัด = วันสิ้นเดือน
     *  - เดือนอนาคต    → คืน [] (ยังไม่ถึงกำหนดกรอก)
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์, ไม่ส่ง = วันนี้จริง
     */
    public function getUnfilledDatesForMonth(int $userId, int $shopId, string $month, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $startDate = $month . '-01';
        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$monthStart || $monthStart->format('Y-m-d') !== $startDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }
        $monthStart = $monthStart->setTime(0, 0, 0);

        // $today ไม่ถูกต้อง/ไม่ส่ง → ใช้วันนี้จริง
        $todayObject = (new DateTimeImmutable($this->resolveToday($today)))->setTime(0, 0, 0);

        $selectedMonthKey = $monthStart->format('Y-m');
        $todayMonthKey = $todayObject->format('Y-m');

        // เดือนอนาคต — ยังไม่ถึงกำหนดกรอก
        if ($selectedMonthKey > $todayMonthKey) {
            return [
                'success' => true,
                'data' => [
                    'month' => $selectedMonthKey,
                    'missing_dates' => [],
                    'count' => 0,
                ],
            ];
        }

        $capObject = $selectedMonthKey === $todayMonthKey
            ? $todayObject                                    // เดือนปัจจุบัน: ตัดที่วันนี้
            : $monthStart->modify('last day of this month');  // เดือนอดีต: ตัดที่สิ้นเดือน

        $capDate = $capObject->format('Y-m-d');

        if ($capDate < $startDate) {
            return [
                'success' => true,
                'data' => [
                    'month' => $selectedMonthKey,
                    'missing_dates' => [],
                    'count' => 0,
                ],
            ];
        }

        try {
            $records = $this->recordRepository->getByDateRange($shopId, $startDate, $capDate);
        } catch (Throwable $exception) {
            error_log('[record] getUnfilledDatesForMonth failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถตรวจสอบวันที่ยังไม่ได้กรอกได้',
            ];
        }

        $filledDates = [];
        foreach ($records as $record) {
            $filledDate = (string)($record['record_date'] ?? '');
            if ($filledDate !== '') {
                $filledDates[$filledDate] = true;
            }
        }

        $missingDates = [];
        for ($cursor = $monthStart; $cursor->format('Y-m-d') <= $capDate; $cursor = $cursor->modify('+1 day')) {
            $dateKey = $cursor->format('Y-m-d');
            if (!isset($filledDates[$dateKey])) {
                $missingDates[] = $dateKey;
            }
        }

        return [
            'success' => true,
            'data' => [
                'month' => $selectedMonthKey,
                'missing_dates' => $missingDates,
                'count' => count($missingDates),
            ],
        ];
    }

    /**
     * สร้างตารางทั้งเดือนสำหรับแก้ไข — ทุกวันในช่วง พร้อมค่าที่บันทึกไว้แล้ว (ถ้ามี)
     *
     * ช่วงวันเหมือน getUnfilledDatesForMonth: เดือนปัจจุบันตัดที่ today,
     * เดือนอดีตถึงสิ้นเดือน, เดือนอนาคตคืน days ว่าง
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์
     */
    public function buildEditableMonthGrid(int $userId, int $shopId, string $month, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $startDate = $month . '-01';
        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$monthStart || $monthStart->format('Y-m-d') !== $startDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }
        $monthStart = $monthStart->setTime(0, 0, 0);

        $todayObject = (new DateTimeImmutable($this->resolveToday($today)))->setTime(0, 0, 0);

        $selectedMonthKey = $monthStart->format('Y-m');
        $todayMonthKey = $todayObject->format('Y-m');

        // เดือนอนาคต — ยังไม่ถึงกำหนดกรอก
        if ($selectedMonthKey > $todayMonthKey) {
            return [
                'success' => true,
                'data' => [
                    'month' => $selectedMonthKey,
                    'days' => [],
                ],
            ];
        }

        $cutoffObject = $selectedMonthKey === $todayMonthKey
            ? $todayObject                                    // เดือนปัจจุบัน: ตัดที่วันนี้
            : $monthStart->modify('last day of this month');  // เดือนอดีต: ถึงสิ้นเดือน (leap-aware)
        $cutoffDate = $cutoffObject->format('Y-m-d');

        try {
            $records = $this->recordRepository->getByDateRange($shopId, $startDate, $cutoffDate);
        } catch (Throwable $exception) {
            error_log('[record] buildEditableMonthGrid failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลของเดือนนี้ได้',
            ];
        }

        $recordByDate = [];
        foreach ($records as $record) {
            $recordDate = trim((string)($record['record_date'] ?? ''));
            if ($recordDate !== '') {
                $recordByDate[$recordDate] = $record;
            }
        }

        $days = [];
        for ($cursor = $monthStart; $cursor->format('Y-m-d') <= $cutoffDate; $cursor = $cursor->modify('+1 day')) {
            $dateKey = $cursor->format('Y-m-d');
            $record = $recordByDate[$dateKey] ?? null;

            $days[] = [
                'date' => $dateKey,
                'has_record' => $record !== null,
                'revenue' => $record !== null ? (float)($record['revenue'] ?? 0) : null,
                'ad_cost' => $record !== null ? (float)($record['ad_cost'] ?? 0) : null,
                'note' => $record !== null ? (string)($record['note'] ?? '') : '',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'month' => $selectedMonthKey,
                'days' => $days,
            ],
        ];
    }

    public function deleteRecord(int $userId, int $shopId, int $recordId): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if ($recordId <= 0) {
            return [
                'success' => false,
                'error' => 'ไม่พบรายการที่ต้องการลบ',
            ];
        }

        $startedTransaction = false;
        $canLockRows = false;

        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                $canLockRows = $this->db->inTransaction();
                if ($canLockRows) {
                    // จองแถวร้านก่อนแตะข้อมูลข้างใน — ลำดับเดียวกับทุกทางที่เขียน
                    // ร้านหายไปแล้ว = รายการข้างในหายตามไปด้วย (cascade) → ผลตรงกับที่ขอ
                    // จึงไม่ต้องแจ้ง error แต่ต้องไม่เดินต่อไปแตะแถวที่ไม่มีอยู่แล้ว
                    if (!$this->shopRepository->lockForWrite($shopId, $userId)) {
                        if ($startedTransaction && $this->db->inTransaction()) {
                            $this->db->commit();
                        }

                        return [
                            'success' => true,
                            'message' => 'ลบรายการเรียบร้อยแล้ว',
                        ];
                    }
                }
            }

            $existingRecord = $canLockRows
                ? $this->recordRepository->findByIdAndShopIdForUpdate($recordId, $shopId)
                : $this->recordRepository->findByIdAndShopId($recordId, $shopId);

            if ($existingRecord === null) {
                // ⚠️ ไม่เจอในร้านปัจจุบัน ยังสรุปไม่ได้ว่า "ลบไปแล้ว" — shopId มาจาก session
                // ซึ่งสลับได้ในอีกแท็บ ถ้าแถวยังอยู่ในร้านอื่นของผู้ใช้ ต้องตอบว่าล้มเหลว
                // ไม่งั้นผู้ใช้เห็น flash เขียวทั้งที่ข้อมูลยังอยู่และยอดรวมยังนับต่อ
                $existsElsewhere = $this->recordRepository->existsByIdAndUserId($recordId, $userId);

                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->commit();
                }

                if ($existsElsewhere) {
                    return [
                        'success' => false,
                        'error' => 'รายการนี้อยู่ในร้านอื่น กรุณาสลับไปร้านนั้นก่อนลบ',
                    ];
                }

                // ไม่มีอยู่ในร้านไหนของผู้ใช้เลย = ผลลัพธ์ตรงกับที่ขอไปแล้ว → idempotent
                // (รายการของผู้ใช้อื่นก็มาทางนี้ ตอบเหมือนกันทุกไบต์ จึงไม่บอกใบ้ว่ามีอยู่จริงไหม)
                // ⚠️ ห้ามเพิ่ม flag/ข้อความแยกให้เคสนี้ — ต้องเหมือนการลบที่สำเร็จจริงทุกไบต์
                return [
                    'success' => true,
                    'message' => 'ลบรายการเรียบร้อยแล้ว',
                ];
            }

            $deleted = $this->recordRepository->deleteByIdAndShopId($recordId, $shopId);
            if (!$deleted) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถลบรายการได้',
                ];
            }

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[record] deleteRecord failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => write_failure_message($exception, 'ไม่สามารถลบรายการได้'),
            ];
        }

        return [
            'success' => true,
            'message' => 'ลบรายการเรียบร้อยแล้ว',
        ];
    }

    private function validateRecordPayload(string $recordDate, float $revenue, float $adCost, ?string $note): array
    {
        $dateObject = DateTime::createFromFormat('Y-m-d', $recordDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $recordDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบวันที่ไม่ถูกต้อง',
            ];
        }

        // ปีนอกช่วงที่รายงานรองรับ (resolve_calendar_year, AnnualService::isValidYear ใช้ 2000-2100)
        // บันทึกได้แต่จะหายจากทุกหน้ารายงาน — เคยเกิดจริงกับ CSV ที่มีวันที่แบบ 01/01/2450
        $year = (int)$dateObject->format('Y');
        if ($year < self::MIN_RECORD_YEAR || $year > self::MAX_RECORD_YEAR) {
            return [
                'success' => false,
                'error' => sprintf('ปีต้องอยู่ระหว่าง %d–%d', self::MIN_RECORD_YEAR, self::MAX_RECORD_YEAR),
            ];
        }

        if ($revenue < 0 || $adCost < 0) {
            return [
                'success' => false,
                'error' => 'รายได้และค่าแอดต้องไม่ติดลบ',
            ];
        }

        // คอลัมน์เป็น DECIMAL(12,2) — เกินแล้ว MySQL strict mode จะ throw ทำให้ทั้งชุดถูก
        // rollback พร้อม error ลอย ๆ "ไม่สามารถบันทึกข้อมูลได้" ที่ไม่บอกว่าแถวไหน ส่วน
        // non-strict จะตัดค่าเงียบแล้วรายงานว่าสำเร็จ — ปฏิเสธตั้งแต่ตรงนี้ให้บอกได้ว่าผิดที่ไหน
        if ($revenue > self::MAX_AMOUNT || $adCost > self::MAX_AMOUNT) {
            return [
                'success' => false,
                'error' => 'รายได้และค่าแอดต้องไม่เกิน ' . number_format(self::MAX_AMOUNT, 2),
            ];
        }

        // MySQL จะปัดให้เองแล้วรายงานว่าสำเร็จ — ตัวเลขที่เก็บจะไม่ใช่ตัวเลขที่ผู้ใช้กรอก
        if (self::hasTooManyDecimals($revenue) || self::hasTooManyDecimals($adCost)) {
            return [
                'success' => false,
                'error' => 'รายได้และค่าแอดใส่ทศนิยมได้ไม่เกิน ' . self::AMOUNT_DECIMALS . ' ตำแหน่ง',
            ];
        }

        $normalizedNote = $note === null ? null : trim($note);
        if ($normalizedNote !== null && $normalizedNote !== '') {
            $length = function_exists('mb_strlen') ? mb_strlen($normalizedNote) : strlen($normalizedNote);
            if ($length > self::NOTE_MAX_LENGTH) {
                return [
                    'success' => false,
                    'error' => 'โน้ตยาวเกิน ' . self::NOTE_MAX_LENGTH . ' ตัวอักษร',
                ];
            }
        } else {
            $normalizedNote = null;
        }

        return [
            'success' => true,
            'data' => [
                'record_date' => $recordDate,
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'note' => $normalizedNote,
            ],
        ];
    }
}
