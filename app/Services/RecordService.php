<?php

declare(strict_types=1);

class RecordService
{
    /** จำนวนแถวสูงสุดต่อการบันทึกแบบหลายวัน 1 ครั้ง (ครอบคลุม 1 เดือน) */
    public const BULK_MAX_ROWS = 31;

    /** จำนวนแถวสูงสุดต่อการนำเข้าไฟล์ CSV 1 ครั้ง */
    public const IMPORT_MAX_ROWS = 1000;

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
                    // Serialize concurrent upserts/updates for the same shop+date.
                    $this->recordRepository->findByShopIdAndRecordDateForUpdate($shopId, (string)$payload['record_date']);
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
                'error' => 'ไม่สามารถบันทึกข้อมูลได้',
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

        foreach (['record_date' => 'วันที่', 'revenue' => 'รายได้', 'ad_cost' => 'ค่าแอด'] as $field => $label) {
            if (!isset($columnMap[$field])) {
                fclose($handle);
                return $fail('ไฟล์ต้องมีคอลัมน์ "' . $label . '" (ไม่พบในหัวตาราง)');
            }
        }

        $rows = [];
        $lineNumber = 1; // นับรวมบรรทัดหัวตารางแล้ว

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $lineNumber++;

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

            $recordDate = $this->parseImportDate($rawDate);
            if ($recordDate === null) {
                fclose($handle);
                return $fail('บรรทัดที่ ' . $lineNumber . ': อ่านวันที่ "' . $rawDate . '" ไม่ได้');
            }

            $revenue = $this->cleanImportNumber($cellAt($columnMap['revenue']));
            $adCost = $this->cleanImportNumber($cellAt($columnMap['ad_cost']));

            if ($revenue !== '' && !is_numeric($revenue)) {
                fclose($handle);
                return $fail('บรรทัดที่ ' . $lineNumber . ': รายได้ "' . $revenue . '" ไม่ใช่ตัวเลข');
            }

            if ($adCost !== '' && !is_numeric($adCost)) {
                fclose($handle);
                return $fail('บรรทัดที่ ' . $lineNumber . ': ค่าแอด "' . $adCost . '" ไม่ใช่ตัวเลข');
            }

            $note = isset($columnMap['note']) ? $cellAt($columnMap['note']) : '';
            if ($note !== '' && $note[0] === "'") {
                $note = substr($note, 1); // ถอด guard formula injection ตอน export
            }

            $rows[] = [
                'record_date' => $recordDate,
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'note' => $note === '' ? null : $note,
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
    private function cleanImportNumber(string $raw): string
    {
        $value = trim($raw);
        if ($value !== '' && $value[0] === "'") {
            $value = substr($value, 1);
        }

        $value = str_replace(['฿', ',', ' ', "\xC2\xA0"], '', $value);

        return trim($value);
    }

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
        foreach ($filledRows as $row) {
            $rowNumber = (int)$row['row_number'];

            if (!is_numeric($row['revenue']) || !is_numeric($row['ad_cost'])) {
                return [
                    'success' => false,
                    'error' => 'แถวที่ ' . $rowNumber . ': กรุณากรอกรายได้และค่าแอดให้ถูกต้อง',
                ];
            }

            $validation = $this->validateRecordPayload(
                (string)$row['record_date'],
                (float)$row['revenue'],
                (float)$row['ad_cost'],
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
            }

            foreach ($payloads as $payload) {
                if ($canLockRows) {
                    // Serialize concurrent upserts for the same shop+date.
                    $this->recordRepository->findByShopIdAndRecordDateForUpdate($shopId, (string)$payload['record_date']);
                }

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
                'error' => 'ไม่สามารถบันทึกข้อมูลได้',
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
                'error' => 'ไม่สามารถแก้ไขรายการได้',
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[record] updateRecord failed: ' . $exception->getMessage());

            return [
                'success' => false,
                'error' => 'ไม่สามารถแก้ไขรายการได้',
            ];
        }

        return [
            'success' => true,
            'message' => 'แก้ไขรายการเรียบร้อยแล้ว',
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
    public function getWeekdayContext(int $userId, int $shopId, ?string $targetDate = null): array
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
            // ไม่ระบุ → ใช้วันล่าสุดที่กรอกไว้
            try {
                $recentRecords = $this->recordRepository->getRecentByShopId($shopId, 1);
            } catch (Throwable $exception) {
                error_log('[record] getWeekdayContext failed: ' . $exception->getMessage());
                return [
                    'success' => false,
                    'error' => 'ไม่สามารถโหลดข้อมูลเปรียบเทียบได้',
                ];
            }

            $latest = $recentRecords[0] ?? null;
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
        $monthEnd = $dateObject->format('Y-m-t');

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
                'comparable' => $sampleCount >= 1,
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

        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

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

        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

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
        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

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

        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

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
            }

            $existingRecord = $canLockRows
                ? $this->recordRepository->findByIdAndShopIdForUpdate($recordId, $shopId)
                : $this->recordRepository->findByIdAndShopId($recordId, $shopId);

            if ($existingRecord === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบรายการที่ต้องการลบ',
                ];
            }

            $deleted = $this->recordRepository->deleteByIdAndShopId($recordId, $shopId);
            if (!$deleted) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบรายการที่ต้องการลบ',
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
                'error' => 'ไม่สามารถลบรายการได้',
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

        if ($revenue < 0 || $adCost < 0) {
            return [
                'success' => false,
                'error' => 'รายได้และค่าแอดต้องไม่ติดลบ',
            ];
        }

        $normalizedNote = $note === null ? null : trim($note);
        if ($normalizedNote !== null && $normalizedNote !== '') {
            $length = function_exists('mb_strlen') ? mb_strlen($normalizedNote) : strlen($normalizedNote);
            if ($length > 255) {
                return [
                    'success' => false,
                    'error' => 'โน้ตยาวเกิน 255 ตัวอักษร',
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
