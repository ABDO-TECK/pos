<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Repositories\CachedRepository;
use App\Repositories\ProductRepository;
use App\Services\AuthService;
use PHPUnit\Framework\TestCase;

final class ProductRepositoryBranchCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        CachedRepository::invalidate('products');
        (new AuthService())->setBranchId(1);
    }

    public function testIdenticalFiltersDoNotShareCachedProductsAcrossBranches(): void
    {
        CachedRepository::invalidate('products');
        $auth = new AuthService();
        $model = $this->createMock(Product::class);
        $model->expects(self::exactly(2))
            ->method('all')
            ->willReturnCallback(static fn(): array => [[
                'branch_id' => AuthService::getGlobalBranchId(),
            ]]);

        $repository = new ProductRepository($model);
        $filters = ['search' => 'branch-cache-' . bin2hex(random_bytes(6))];

        $auth->setBranchId(1);
        self::assertSame(1, $repository->all($filters)[0]['branch_id']);
        self::assertSame(1, $repository->all($filters)[0]['branch_id']);

        $auth->setBranchId(2);
        self::assertSame(2, $repository->all($filters)[0]['branch_id']);
    }
}
