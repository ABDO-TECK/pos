<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Core\QueryBuilder;

class QueryBuilderTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
        $this->pdo->exec("INSERT INTO items VALUES (1, 'Apple', 2.5)");
        $this->pdo->exec("INSERT INTO items VALUES (2, 'Banana', 1.5)");
    }

    public function testSelectAll()
    {
        $qb = new QueryBuilder($this->pdo);
        $rows = $qb->table('items')->get();
        $this->assertCount(2, $rows);
    }

    public function testSelectWithWhere()
    {
        $qb = new QueryBuilder($this->pdo);
        $row = $qb->table('items')->where('id', '=', 1)->first();
        $this->assertEquals('Apple', $row['name']);
    }

    public function testCount()
    {
        $qb = new QueryBuilder($this->pdo);
        $count = $qb->table('items')->count();
        $this->assertEquals(2, $count);
    }

    public function testInsert()
    {
        $qb = new QueryBuilder($this->pdo);
        $id = $qb->table('items')->insert(['name' => 'Cherry', 'price' => 3.0]);
        $this->assertEquals(3, $id);
    }

    public function testUpdate()
    {
        $qb = new QueryBuilder($this->pdo);
        $affected = $qb->table('items')->where('id', '=', 1)->update(['price' => 5.0]);
        $this->assertEquals(1, $affected);
    }

    public function testDelete()
    {
        $qb = new QueryBuilder($this->pdo);
        $affected = $qb->table('items')->where('id', '=', 2)->delete();
        $this->assertEquals(1, $affected);
        $this->assertEquals(1, $qb->table('items')->count());
    }

    public function testLimitAndOffset()
    {
        $qb = new QueryBuilder($this->pdo);
        $rows = $qb->table('items')->orderBy('id', 'ASC')->limit(1)->offset(1)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('Banana', $rows[0]['name']);
    }
}
