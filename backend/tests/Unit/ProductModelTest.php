<?php

use PHPUnit\Framework\TestCase;

class ProductModelTest extends TestCase
{
    /**
     * @var Product
     */
    private $productModel;

    protected function setUp(): void
    {
        // تهيئة البيئة والاحتياجات قبل كل اختبار
        // يمكنك إعداد اتصال بقاعدة بيانات اختبارية (Test DB) هنا.
        $this->productModel = new Product();
    }

    public function testNormalizeAdditionalBarcodes()
    {
        $mainBarcode = '123456';
        $additional = [' 123456 ', '789012', '', ' 112233 '];

        $result = Product::normalizeAdditionalBarcodes($mainBarcode, $additional);

        $this->assertCount(2, $result);
        $this->assertContains('789012', $result);
        $this->assertContains('112233', $result);
        $this->assertNotContains('123456', $result, 'Main barcode should be removed from additional');
    }
}
