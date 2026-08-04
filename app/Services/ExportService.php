<?php

declare(strict_types=1);

class ExportService
{
    /**
     * ตำแหน่งคอลัมน์ "โน้ต" ในแต่ละรูปแบบไฟล์ — ⚠️ คนละฐานเลขกัน
     *
     * CSV : 0-based เพราะ controller ใช้กับ index ของ array แถว (ตาราง 7 คอลัมน์)
     * xlsx: 1-based เพราะ PhpSpreadsheet นับ A = 1 (ตาราง 6 คอลัมน์)
     *
     * ตอนนี้ค่าบังเอิญเท่ากัน (6) ทั้งคู่ ถ้าเพิ่ม/ลบคอลัมน์ในตารางใดตารางหนึ่ง
     * ต้องแก้เฉพาะค่าของฝั่งนั้น อย่าสมมติว่าต้องเท่ากันเสมอ
     */
    private const CSV_NOTE_COLUMN_INDEX = 6;
    private const XLSX_NOTE_COLUMN_INDEX = 6;

    private RecordService $recordService;
    private ShopRepository $shopRepository;

    public function __construct(RecordService $recordService, ShopRepository $shopRepository)
    {
        $this->recordService = $recordService;
        $this->shopRepository = $shopRepository;
    }

    public function buildMonthlyCsvPayload(int $userId, int $shopId, string $month): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        try {
            $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        } catch (Throwable $exception) {
            error_log('[export] buildMonthlyCsvPayload shop lookup failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลร้านค้าได้',
            ];
        }

        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        try {
            $monthlyResult = $this->recordService->getMonthlyRecords($userId, $shopId, $month);
        } catch (Throwable $exception) {
            error_log('[export] buildMonthlyCsvPayload getMonthlyRecords failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้',
            ];
        }

        if (($monthlyResult['success'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($monthlyResult['error'] ?? 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้'),
            ];
        }

        $records = (array)($monthlyResult['data']['records'] ?? []);
        $totals = (array)($monthlyResult['data']['totals'] ?? []);
        $rows = [];

        foreach ($records as $record) {
            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);
            $profit = (float)($record['profit'] ?? ($revenue - $adCost));
            $roas = isset($record['roas']) && $record['roas'] !== null ? (float)$record['roas'] : null;
            $comparePercent = isset($record['compare_revenue_percent']) && $record['compare_revenue_percent'] !== null
                ? (float)$record['compare_revenue_percent']
                : null;
            $note = (string)($record['note'] ?? '');

            // ค่าที่ไม่มี → เซลล์ว่าง (ให้ Excel มองเป็น blank ไม่ใช่ข้อความ '–')
            //
            // ⚠️ ห้ามใส่ตัวคั่นหลักพัน — คอลัมน์ตัวเลขอื่นทุกคอลัมน์ส่ง '' เป็น separator
            // อยู่แล้ว เฉพาะช่องนี้ที่เคยใช้ค่า default (จุลภาค) ทำให้ค่าที่โตมาก ๆ ออกมา
            // เป็น "+9,999,900.0%" ซึ่ง Excel อ่านเป็นสูตรที่ผิดไวยากรณ์เพราะมีจุลภาค
            //
            // ⚠️ ห้ามนำหน้าด้วย "+" ด้วย — Excel แปลงเซลล์ที่ขึ้นต้นด้วย + เป็น *สูตร*
            // (มรดกจาก Lotus 1-2-3) ช่องนี้จึงกลายเป็นสูตรช่องเดียวในไฟล์ และพังทันที
            // บนเครื่องที่ตั้งจุดทศนิยมเป็นจุลภาค · ตัวกันสูตรใน controller ไม่ครอบช่องนี้
            // เพราะตั้งใจให้ Excel อ่านเป็นตัวเลข ไม่ใช่ข้อความ · เครื่องหมายลบไม่มีปัญหา
            // (Excel อ่าน -50.0% เป็นจำนวนลบตามปกติ)
            $compareText = '';
            if ($comparePercent !== null) {
                $compareText = number_format($comparePercent, 1, '.', '') . '%';
            }

            $rows[] = [
                // ISO YYYY-MM-DD — Excel sort/filter/pivot ได้ตรง (record_date จาก DB เป็น Y-m-d อยู่แล้ว)
                (string)($record['record_date'] ?? ''),
                number_format($revenue, 2, '.', ''),
                number_format($adCost, 2, '.', ''),
                number_format($profit, 2, '.', ''),
                $roas === null ? '' : number_format($roas, 2, '.', ''),
                $compareText,
                $note,
            ];
        }

        $totalsRow = [
            'รวม',
            number_format((float)($totals['total_revenue'] ?? 0), 2, '.', ''),
            number_format((float)($totals['total_ad_cost'] ?? 0), 2, '.', ''),
            number_format((float)($totals['total_profit'] ?? 0), 2, '.', ''),
            isset($totals['avg_roas']) && $totals['avg_roas'] !== null ? number_format((float)$totals['avg_roas'], 2, '.', '') : '',
            '',
            '',
        ];

        return [
            'success' => true,
            'data' => [
                'shop_name' => (string)($shop['name'] ?? 'ร้านค้า'),
                'month' => $month,
                'headers' => ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'เทียบครั้งก่อน', 'โน้ต'],
                'rows' => $rows,
                'totals_row' => $totalsRow,
                // คอลัมน์เดียวที่มาจากผู้ใช้ → controller sanitize เฉพาะช่องนี้
                // ⚠️ 0-based (ใช้กับ index ของ array แถว) ต่างจาก payload ของ xlsx ที่เป็น 1-based
                'note_column_index' => self::CSV_NOTE_COLUMN_INDEX,
                // ให้ controller เว้น 1 บรรทัดก่อนแถวรวม เพื่อให้ Excel ตัดขอบตารางตรงนั้น
                'blank_row_before_totals' => true,
            ],
        ];
    }

    /**
     * ข้อมูลดิบสำหรับ sheet "รายวัน" ของ xlsx ทั้งปี
     *
     * แยกจากการเขียนไฟล์โดยตั้งใจ — ชั้นนี้เทสต์ได้ตรง ๆ ส่วนการเขียน binary
     * อยู่ที่ controller (api/export-xlsx.php) ซึ่ง unit test ไม่ได้
     *
     * ตัดเดือนอนาคตด้วยขอบเขตเดียวกับ sheet รายเดือน เพื่อให้ยอดรวมสองแท็บตรงกัน
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     */
    public function buildYearlyDailyPayload(int $userId, int $shopId, int $year, ?string $today = null): array
    {
        if ($year < 2000 || $year > 2100) {
            return [
                'success' => false,
                'error' => 'ปีที่เลือกไม่ถูกต้อง',
            ];
        }

        try {
            $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        } catch (Throwable $exception) {
            error_log('[export] buildYearlyDailyPayload shop lookup failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลร้านค้าได้',
            ];
        }

        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $lastMonth = $this->resolveLastMonth($year, $today);

        if ($lastMonth === 0) {
            return [
                'success' => true,
                'data' => [
                    'year' => $year,
                    'shop_name' => (string)($shop['name'] ?? 'ร้านค้า'),
                    'rows' => [],
                    'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
                    'note_column_index' => self::XLSX_NOTE_COLUMN_INDEX,
                ],
            ];
        }

        // ตัดที่ "สิ้นเดือนปัจจุบัน" ไม่ใช่ "วันนี้" — เรคอร์ดที่ลงล่วงหน้าภายในเดือนนี้ยังเก็บครบ
        // ตรงกับ sheet รายเดือนและหน้า annual ที่นับทั้งเดือนปัจจุบัน
        $endOfLastMonth = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $lastMonth)))->format('Y-m-t');

        try {
            $rangeResult = $this->recordService->getRecordsByDateRange(
                $userId,
                $shopId,
                sprintf('%04d-01-01', $year),
                $endOfLastMonth
            );
        } catch (Throwable $exception) {
            error_log('[export] buildYearlyDailyPayload fetch failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้',
            ];
        }

        if (($rangeResult['success'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($rangeResult['error'] ?? 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้'),
            ];
        }

        $rows = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;

        foreach ((array)($rangeResult['data'] ?? []) as $record) {
            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);

            $rows[] = [
                // ISO YYYY-MM-DD — controller แปลงเป็น Excel date serial ให้เรียง/กรองได้จริง
                'record_date' => (string)($record['record_date'] ?? ''),
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'profit' => $revenue - $adCost,
                'roas' => $adCost > 0 ? round($revenue / $adCost, 2) : null,
                'note' => (string)($record['note'] ?? ''),
            ];

            $totalRevenue += $revenue;
            $totalAdCost += $adCost;
        }

        return [
            'success' => true,
            'data' => [
                'year' => $year,
                'shop_name' => (string)($shop['name'] ?? 'ร้านค้า'),
                'rows' => $rows,
                'totals' => [
                    'revenue' => $totalRevenue,
                    'ad_cost' => $totalAdCost,
                    'profit' => $totalRevenue - $totalAdCost,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                ],
                // ตำแหน่งคอลัมน์โน้ต — 1-based เพราะ PhpSpreadsheet ใช้เลขคอลัมน์แบบ 1 = A
                // ⚠️ ฐานเลขต่างจาก payload ของ CSV ที่เป็น 0-based ทั้งที่ชื่อคีย์เหมือนกัน
                'note_column_index' => self::XLSX_NOTE_COLUMN_INDEX,
            ],
        ];
    }

    /** ชื่อไฟล์ใช้ พ.ศ. ให้ตรงกับปีที่แสดงในรายงาน (ข้างในเขียน 2569 ไฟล์ก็ควรเป็น 2569) */
    public function buildYearlyXlsxFilename(string $shopName, int $year): string
    {
        return $this->sanitizeFilenameBase($shopName) . '_' . ($year + 543) . '.xlsx';
    }

    public function buildMonthlyCsvFilename(string $shopName, string $month): string
    {
        return $this->sanitizeFilenameBase($shopName) . '_' . $month . '.csv';
    }

    /**
     * เดือนสุดท้ายที่รายงานควรครอบคลุม — ปีอดีต 12 · ปีปัจจุบันถึงเดือนนี้ · ปีอนาคต 0
     *
     * ใช้ร่วมกันทั้ง sheet รายวันและรายเดือน เพื่อไม่ให้สองแท็บนิยามขอบเขตต่างกัน
     * (เคยเป็นบั๊ก: รายวันดึงเต็มปี รายเดือนตัด → ยอดรวมในไฟล์เดียวกันไม่ตรง)
     */
    private function resolveLastMonth(int $year, ?string $today): int
    {
        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }

        $currentYear = (int)$todayObject->format('Y');

        if ($year < $currentYear) {
            return 12;
        }

        if ($year === $currentYear) {
            return (int)$todayObject->format('n');
        }

        return 0;
    }

    private function sanitizeFilenameBase(string $shopName): string
    {
        $baseName = trim($shopName);
        if ($baseName === '') {
            return 'shop';
        }

        $baseName = preg_replace('/[\\\\\/\:\*\?"<>\|]+/u', '_', $baseName) ?? $baseName;
        $baseName = preg_replace('/\s+/u', ' ', $baseName) ?? $baseName;
        $baseName = trim($baseName);

        return $baseName === '' ? 'shop' : $baseName;
    }
}
