<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\CategoryService;
use App\Repositories\CategoryRepository;
use Exception;

class CategoryServiceTest extends TestCase
{
    private CategoryService $service;
    private $categoryRepoMock;

    protected function setUp(): void
    {
        $this->categoryRepoMock = $this->createMock(CategoryRepository::class);
        $this->service = new CategoryService($this->categoryRepoMock);
    }

    public function testGetAll()
    {
        $expected = [
            ['id' => 1, 'name' => 'Cat 1'],
            ['id' => 2, 'name' => 'Cat 2']
        ];
        $this->categoryRepoMock->method('all')->willReturn($expected);

        $result = $this->service->getAll();

        $this->assertEquals($expected, $result);
    }

    public function testCreateCategorySuccess()
    {
        $data = ['name' => 'New Category'];
        $this->categoryRepoMock->method('create')
            ->with(['name' => 'New Category'])
            ->willReturn(1);

        $result = $this->service->createCategory($data);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('New Category', $result['name']);
    }

    public function testCreateCategoryFailure()
    {
        $data = ['name' => 'Fail'];
        $this->categoryRepoMock->method('create')
            ->willThrowException(new Exception('DB Error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('فشل إنشاء الفئة');

        $this->service->createCategory($data);
    }

    public function testUpdateCategorySuccess()
    {
        $data = ['name' => 'Updated Category'];
        $this->categoryRepoMock->expects($this->once())
            ->method('update')
            ->with(1, ['name' => 'Updated Category']);

        $result = $this->service->updateCategory(1, $data);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Updated Category', $result['name']);
    }

    public function testUpdateCategoryFailure()
    {
        $data = ['name' => 'Fail'];
        $this->categoryRepoMock->method('update')
            ->willThrowException(new Exception('DB Error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('فشل تحديث الفئة');

        $this->service->updateCategory(1, $data);
    }

    public function testDeleteCategorySuccess()
    {
        $this->categoryRepoMock->expects($this->once())
            ->method('delete')
            ->with(1);

        $this->service->deleteCategory(1);
        $this->assertTrue(true); // If no exception, it passes
    }

    public function testDeleteCategoryFailure()
    {
        $this->categoryRepoMock->method('delete')
            ->willThrowException(new Exception('DB Error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('فشل حذف الفئة');

        $this->service->deleteCategory(1);
    }
}
