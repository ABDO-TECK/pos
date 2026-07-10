<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\CustomerService;
use App\Repositories\CustomerRepository;

class CustomerServiceTest extends TestCase
{
    private CustomerService $service;
    private CustomerRepository $customerMock;

    protected function setUp(): void
    {
        $this->customerMock = $this->createMock(CustomerRepository::class);
        $this->service = new CustomerService($this->customerMock);
    }

    public function testAddPaymentThrowsIfCustomerNotFound()
    {
        $this->customerMock->method('findById')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('العميل غير موجود');

        $this->service->addPayment(999, ['amount' => 100, 'type' => 'credit'], ['id' => 1]);
    }

    public function testAddPaymentThrowsIfAmountIsZero()
    {
        $this->customerMock->method('findById')->willReturn(['id' => 1, 'name' => 'Test']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('يجب أن يكون المبلغ أكبر من صفر');

        $this->service->addPayment(1, ['amount' => 0, 'type' => 'credit'], ['id' => 1]);
    }

    public function testAddPaymentThrowsIfAmountIsNegative()
    {
        $this->customerMock->method('findById')->willReturn(['id' => 1, 'name' => 'Test']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('يجب أن يكون المبلغ أكبر من صفر');

        $this->service->addPayment(1, ['amount' => -50, 'type' => 'credit'], ['id' => 1]);
    }

    public function testUpdateLedgerEntryThrowsIfNotFound()
    {
        $this->customerMock->method('getLedgerEntry')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('القيد غير موجود');

        $this->service->updateLedgerEntry(999, ['amount' => 100, 'type' => 'credit']);
    }

    public function testUpdateLedgerEntryThrowsIfInvalidType()
    {
        $this->customerMock->method('getLedgerEntry')
            ->willReturn(['id' => 1, 'customer_id' => 1, 'type' => 'credit']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('نوع القيد غير صحيح');

        $this->service->updateLedgerEntry(1, ['amount' => 100, 'type' => 'invalid']);
    }
}
