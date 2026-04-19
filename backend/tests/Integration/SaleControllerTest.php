<?php

use PHPUnit\Framework\TestCase;

class SaleControllerTest extends TestCase
{
    private SaleService $saleService;

    protected function setUp(): void
    {
        // يتطلب وجود قاعدة بيانات اختبارية تحتوي على منتجات
        $this->saleService = new SaleService();
    }

    public function testEnrichItems()
    {
        // 1. إعداد بيانات سلة وهمية
        $items = [
            ['product_id' => 999999, 'quantity' => 2] // منتج غير موجود لضمان التقاط الخطأ
        ];

        // 2. التنفيذ
        $result = $this->saleService->enrichItems($items);

        // 3. التحقق
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(400, $result['code']);
    }

    public function testCalculateTotals()
    {
        $enrichedItems = [
            ['price' => 100, 'quantity' => 2],
            ['price' => 50,  'quantity' => 1]
        ];

        // المجموع = 250
        // الخصم = 0، الضريبة = 0
        $data = ['payment_method' => 'cash', 'amount_paid' => 300];

        // نفترض أن الضريبة معطلة مؤقتاً في هذا الاختبار
        $totals = $this->saleService->calculateTotals($enrichedItems, 0, $data);

        $this->assertEquals(250, $totals['subtotal']);
        $this->assertEquals(300, $totals['amount_paid']);
        // قد نحتاج Mocking لـ getSettings لضمان عزل الاختبار عن قاعدة البيانات،
        // لكن هذا هيكل مبدئي لمبيعات الكاشير للتطوير اللاحق.
    }
}
