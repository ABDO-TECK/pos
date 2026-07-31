<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Repositories\InvoiceRepository;
use App\Repositories\PurchaseInvoiceRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class InvoiceLockingTest extends TestCase
{
    public function testSaleHeaderLockIsBranchScopedAndUsesForUpdate(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())
            ->method('execute')
            ->with([41, 1])
            ->willReturn(true);
        $statement->method('fetch')->willReturn(['id' => 41, 'branch_id' => 1]);

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->callback(function (string $sql): bool {
                $normalized = preg_replace('/\s+/', ' ', trim($sql));
                return str_contains($normalized, 'i.branch_id = ?')
                    && str_ends_with($normalized, 'FOR UPDATE');
            }))
            ->willReturn($statement);

        $repository = new InvoiceRepository(new Invoice($db));

        $this->assertSame(41, $repository->findHeaderForUpdate(41)['id']);
    }

    public function testPurchaseHeaderLockIsBranchScopedAndUsesForUpdate(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())
            ->method('execute')
            ->with([52, 1])
            ->willReturn(true);
        $statement->method('fetch')->willReturn(['id' => 52, 'branch_id' => 1]);

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->callback(function (string $sql): bool {
                $normalized = preg_replace('/\s+/', ' ', trim($sql));
                return str_contains($normalized, 'pi.branch_id = ?')
                    && str_ends_with($normalized, 'FOR UPDATE');
            }))
            ->willReturn($statement);

        $repository = new PurchaseInvoiceRepository(new PurchaseInvoice($db));

        $this->assertSame(52, $repository->findHeaderForUpdate(52)['id']);
    }

    public function testLockedHeaderDeleteReturnsExactAffectedRowCount(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(1);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);

        $repository = new InvoiceRepository(new Invoice($db));

        $this->assertSame(1, $repository->deleteLocked(9));
    }

    public function testProductIncrementRejectsMissingOrOutOfScopeProduct(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(0);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Out-of-scope product');

        (new Product($db))->incrementQuantity(99, 2.0);
    }
}
