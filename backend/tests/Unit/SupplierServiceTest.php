<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\SupplierService;
use App\Models\Supplier;
use App\Models\SupplierLedger;

class SupplierServiceTest extends TestCase
{
    private SupplierService $service;
    private Supplier|\PHPUnit\Framework\MockObject\MockObject $supplierMock;
    private SupplierLedger|\PHPUnit\Framework\MockObject\MockObject $ledgerMock;
    private \App\Repositories\SupplierRepository|\PHPUnit\Framework\MockObject\MockObject $supplierRepoMock;
    private \App\Repositories\ProductRepository|\PHPUnit\Framework\MockObject\MockObject $productRepoMock;

    protected function setUp(): void
    {
        $this->supplierMock = $this->createMock(Supplier::class);
        $this->ledgerMock = $this->createMock(SupplierLedger::class);
        $this->supplierRepoMock = $this->createMock(\App\Repositories\SupplierRepository::class);
        $this->productRepoMock = $this->createMock(\App\Repositories\ProductRepository::class);
        $this->service = new SupplierService(
            $this->supplierMock, 
            $this->ledgerMock, 
            $this->supplierRepoMock, 
            $this->productRepoMock
        );
    }

    public function testAddPaymentThrowsIfSupplierNotFound()
    {
        $this->supplierMock->method('findById')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('المورد غير موجود');

        $this->service->addPayment(999, ['amount' => 100, 'type' => 'credit'], ['id' => 1]);
    }

    public function testAddPaymentThrowsIfAmountIsZero()
    {
        $this->supplierMock->method('findById')->willReturn(['id' => 1, 'name' => 'Supplier']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('يجب أن يكون المبلغ أكبر من صفر');

        $this->service->addPayment(1, ['amount' => 0, 'type' => 'credit'], ['id' => 1]);
    }

    public function testUpdateLedgerEntryThrowsIfNotFound()
    {
        $this->ledgerMock->method('getLedgerEntry')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('القيد غير موجود');

        $this->service->updateLedgerEntry(999, ['amount' => 100, 'type' => 'credit']);
    }

    public function testUpdateLedgerEntryThrowsIfInvalidType()
    {
        $this->ledgerMock->method('getLedgerEntry')
            ->willReturn(['id' => 1, 'supplier_id' => 1, 'type' => 'credit']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('نوع القيد غير صحيح');

        $this->service->updateLedgerEntry(1, ['amount' => 100, 'type' => 'bad_type']);
    }
}
