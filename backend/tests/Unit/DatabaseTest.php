<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Config\Database;
use PDOException;

class DatabaseTest extends TestCase
{
    public function testIsConnectionLostDetectsGoneAway()
    {
        $e = new PDOException('MySQL server has gone away');
        $e->errorInfo = [null, 2006, 'MySQL server has gone away'];
        $this->assertTrue(Database::isConnectionLost($e));
    }

    public function testIsConnectionLostDetectsLostConnection()
    {
        $e = new PDOException('Lost connection to MySQL server');
        $e->errorInfo = [null, 2013, 'Lost connection'];
        $this->assertTrue(Database::isConnectionLost($e));
    }

    public function testIsConnectionLostReturnsFalseForOtherErrors()
    {
        $e = new PDOException('Duplicate entry');
        $e->errorInfo = [null, 1062, 'Duplicate entry'];
        $this->assertFalse(Database::isConnectionLost($e));
    }

    public function testIsConnectionLostDetectsByMessage()
    {
        $e = new PDOException('server has gone away');
        $e->errorInfo = [null, 0, ''];
        $this->assertTrue(Database::isConnectionLost($e));
    }
}
