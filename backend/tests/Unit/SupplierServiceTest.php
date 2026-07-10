<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\SupplierService;
use App\Repositories\SupplierRepository;
use App\Repositories\SupplierLedgerRepository;
use App\Repositories\ProductRepository;

class SupplierServiceTest extends TestCase
{
    private SupplierService $service;
    private SupplierLedgerRepository|\PHPUnit\Framework\MockObject\MockObject $ledgerRepoMock;
    private SupplierRepository|\PHPUnit\Framework\MockObject\MockObject $supplierRepoMock;
    private ProductRepository|\PHPUnit\Framework\MockObject\MockObject $productRepoMock;

    protected function setUp(): void
    {
        $this->ledgerRepoMock = $this->createMock(SupplierLedgerRepository::class);
        $this->supplierRepoMock = $this->createMock(SupplierRepository::class);
        $this->productRepoMock = $this->createMock(ProductRepository::class);
        $this->service = new SupplierService(
            $this->ledgerRepoMock,
            $this->supplierRepoMock,
            $this->productRepoMock
        );
    }

    public function testAddPaymentThrowsIfSupplierNotFound()
    {
        $this->supplierRepoMock->method('findById')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('المورد غير موجود');

        $this->service->addPayment(999, ['amount' => 100, 'type' => 'credit'], ['id' => 1]);
    }

    public function testAddPaymentThrowsIfAmountIsZero()
    {
        $this->supplierRepoMock->method('findById')->willReturn(['id' => 1, 'name' => 'Supplier']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('يجب أن يكون المبلغ أكبر من صفر');

        $this->service->addPayment(1, ['amount' => 0, 'type' => 'credit'], ['id' => 1]);
    }

    public function testUpdateLedgerEntryThrowsIfNotFound()
    {
        $this->ledgerRepoMock->method('findById')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('القيد غير موجود');

        $this->service->updateLedgerEntry(999, ['amount' => 100, 'type' => 'credit']);
    }

    public function testUpdateLedgerEntryThrowsIfInvalidType()
    {
        $this->ledgerRepoMock->method('findById')
            ->willReturn(['id' => 1, 'supplier_id' => 1, 'type' => 'credit']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('نوع القيد غير صحيح');

        $this->service->updateLedgerEntry(1, ['amount' => 100, 'type' => 'bad_type']);
    }
}
