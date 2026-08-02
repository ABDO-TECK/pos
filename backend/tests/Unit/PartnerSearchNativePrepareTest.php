<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Supplier;
use App\Services\AuthService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PartnerSearchNativePrepareTest extends TestCase
{
    protected function setUp(): void
    {
        (new AuthService())->setBranchId(1);
    }

    public function testCustomerAndSupplierSearchesUseUniqueNamedPlaceholders(): void
    {
        $pdo = new NativePrepareGuardPdo();

        $customers = (new Customer($pdo))->all(['search' => 'Ahmed', 'page' => 1, 'limit' => 20]);
        $suppliers = (new Supplier($pdo))->all(['search' => 'Cairo', 'page' => 1, 'limit' => 20]);

        self::assertSame([], $customers['data']);
        self::assertSame([], $suppliers['data']);
        self::assertCount(4, $pdo->preparedQueries);
    }
}

final class NativePrepareGuardPdo extends PDO
{
    /** @var list<string> */
    public array $preparedQueries = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        preg_match_all('/(?<!:):[A-Za-z_][A-Za-z0-9_]*/', $query, $matches);
        $placeholders = $matches[0];
        if (count($placeholders) !== count(array_unique($placeholders))) {
            throw new RuntimeException('Native PDO prepare received a duplicate named placeholder.');
        }

        $this->preparedQueries[] = $query;
        return new NativePrepareGuardStatement();
    }
}

final class NativePrepareGuardStatement extends PDOStatement
{
    public function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return 0;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
