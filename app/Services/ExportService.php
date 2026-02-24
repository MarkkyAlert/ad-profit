<?php

declare(strict_types=1);

class ExportService
{
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

            $compareText = '–';
            if ($comparePercent !== null) {
                $compareText = ($comparePercent > 0 ? '+' : '') . number_format($comparePercent, 1) . '%';
            }

            $rows[] = [
                formatThaiDate((string)($record['record_date'] ?? '')),
                number_format($revenue, 2, '.', ''),
                number_format($adCost, 2, '.', ''),
                number_format($profit, 2, '.', ''),
                $roas === null ? '–' : number_format($roas, 2, '.', ''),
                $compareText,
                $note,
            ];
        }

        $totalsRow = [
            'รวม',
            number_format((float)($totals['total_revenue'] ?? 0), 2, '.', ''),
            number_format((float)($totals['total_ad_cost'] ?? 0), 2, '.', ''),
            number_format((float)($totals['total_profit'] ?? 0), 2, '.', ''),
            isset($totals['avg_roas']) && $totals['avg_roas'] !== null ? number_format((float)$totals['avg_roas'], 2, '.', '') : '–',
            '–',
            '–',
        ];

        return [
            'success' => true,
            'data' => [
                'shop_name' => (string)($shop['name'] ?? 'ร้านค้า'),
                'month' => $month,
                'headers' => ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'เทียบเมื่อวาน', 'โน้ต'],
                'rows' => $rows,
                'totals_row' => $totalsRow,
            ],
        ];
    }

    public function buildMonthlyCsvFilename(string $shopName, string $month): string
    {
        $baseName = trim($shopName);
        if ($baseName === '') {
            $baseName = 'shop';
        }

        $baseName = preg_replace('/[\\\\\/\:\*\?"<>\|]+/u', '_', $baseName) ?? $baseName;
        $baseName = preg_replace('/\s+/u', ' ', $baseName) ?? $baseName;
        $baseName = trim($baseName);

        if ($baseName === '') {
            $baseName = 'shop';
        }

        return $baseName . '_' . $month . '.csv';
    }
}
