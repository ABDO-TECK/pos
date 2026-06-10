<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ExpenseService;
use App\Models\Expense;
use App\Repositories\ExpenseCategoryRepository;
use Exception;
use PDOException;

use PHPUnit\Framework\MockObject\MockObject;

class ExpenseServiceTest extends TestCase
{
    private ExpenseService $service;
    /** @var Expense&MockObject */
    private $expenseMock;
    /** @var ExpenseCategoryRepository&MockObject */
    private $categoryRepoMock;

    protected function setUp(): void
    {
        $this->expenseMock = $this->createMock(Expense::class);
        $this->categoryRepoMock = $this->createMock(ExpenseCategoryRepository::class);
        $this->service = new ExpenseService($this->expenseMock, $this->categoryRepoMock);
    }

    // ── Expense CRUD (الاختبارات الأصلية) ──────────────────

    public function testCreateExpenseSuccess(): void
    {
        $data = [
            'category_id' => 1,
            'amount' => 150.00,
            'description' => 'شراء مستلزمات',
            'expense_date' => '2026-01-15',
        ];

        $this->expenseMock->expects($this->once())
            ->method('create')
            ->willReturn(1);

        $result = $this->service->createExpense($data, ['id' => 1]);

        $this->assertEquals(1, $result);
    }

    public function testCreateExpenseRejectsInvalidData(): void
    {
        $data = [
            'category_id' => 1,
            // amount is missing
            'expense_date' => '2026-01-15',
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('البيانات المطلوبة غير مكتملة');
        $this->expectExceptionCode(400);
        
        $this->service->createExpense($data, ['id' => 1]);
    }

    public function testUpdateExpenseSuccess(): void
    {
        $data = [
            'category_id' => 2,
            'amount' => 200.00,
            'description' => 'شراء معدات',
            'expense_date' => '2026-02-20',
        ];

        $this->expenseMock->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(['id' => 1]);

        $this->expenseMock->expects($this->once())
            ->method('update')
            ->with(1, $data);

        $this->service->updateExpense(1, $data);
        
        // If no exception is thrown, the test passes
        $this->assertTrue(true);
    }

    public function testUpdateExpenseRejectsNotFound(): void
    {
        $data = [
            'category_id' => 2,
            'amount' => 200.00,
            'description' => 'شراء معدات',
            'expense_date' => '2026-02-20',
        ];

        $this->expenseMock->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('المصروف غير موجود');
        $this->expectExceptionCode(404);
        
        $this->service->updateExpense(999, $data);
    }

    // ── Category CRUD (اختبارات جديدة) ─────────────────────

    public function testCreateCategorySuccess(): void
    {
        $this->categoryRepoMock->expects($this->once())
            ->method('create')
            ->with(['name' => 'كهرباء'])
            ->willReturn(5);

        $this->categoryRepoMock->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn(['id' => 5, 'name' => 'كهرباء']);

        $result = $this->service->createCategory(['name' => 'كهرباء']);

        $this->assertTrue($result['ok']);
        $this->assertEquals('كهرباء', $result['category']['name']);
    }

    public function testCreateCategoryRejectsEmptyName(): void
    {
        $result = $this->service->createCategory(['name' => '']);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
        $this->assertStringContainsString('اسم التصنيف مطلوب', $result['error']);
    }

    public function testCreateCategoryRejectsDuplicate(): void
    {
        $this->categoryRepoMock->expects($this->once())
            ->method('create')
            ->willThrowException(new PDOException('Duplicate', 23000));

        $result = $this->service->createCategory(['name' => 'مكرر']);

        $this->assertFalse($result['ok']);
        $this->assertEquals(422, $result['code']);
    }

    public function testUpdateCategorySuccess(): void
    {
        $this->categoryRepoMock->method('findById')
            ->willReturn(['id' => 1, 'name' => 'قديم']);

        $this->categoryRepoMock->expects($this->once())
            ->method('update')
            ->with(1, ['name' => 'جديد']);

        $result = $this->service->updateCategory(1, ['name' => 'جديد']);

        $this->assertTrue($result['ok']);
    }

    public function testUpdateCategoryRejectsNotFound(): void
    {
        $this->categoryRepoMock->method('findById')->willReturn(null);

        $result = $this->service->updateCategory(999, ['name' => 'جديد']);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testDeleteCategorySuccess(): void
    {
        $this->categoryRepoMock->method('findById')
            ->willReturn(['id' => 1, 'name' => 'قابل للحذف']);

        $this->categoryRepoMock->expects($this->once())
            ->method('delete')
            ->with(1);

        $result = $this->service->deleteCategory(1);

        $this->assertTrue($result['ok']);
    }

    public function testDeleteCategoryRejectsNotFound(): void
    {
        $this->categoryRepoMock->method('findById')->willReturn(null);

        $result = $this->service->deleteCategory(999);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testDeleteCategoryRejectsWithRelatedExpenses(): void
    {
        $this->categoryRepoMock->method('findById')
            ->willReturn(['id' => 1, 'name' => 'مرتبط']);

        $this->categoryRepoMock->expects($this->once())
            ->method('delete')
            ->willThrowException(new PDOException('FK constraint', 23000));

        $result = $this->service->deleteCategory(1);

        $this->assertFalse($result['ok']);
        $this->assertEquals(422, $result['code']);
        $this->assertStringContainsString('مصروفات مرتبطة', $result['error']);
    }

    public function testGetAllCategories(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'كهرباء'],
            ['id' => 2, 'name' => 'ماء'],
        ];
        $this->categoryRepoMock->method('all')->willReturn($expected);

        $result = $this->service->getAllCategories();

        $this->assertEquals($expected, $result);
    }
}
