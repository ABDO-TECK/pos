<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Helpers\Cache;
use RuntimeException;

class CacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('test_key');
        Cache::forget('test_expired');
    }

    public function testSetAndGetReturnsValue()
    {
        Cache::set('test_key', ['name' => 'POS'], 60);
        $result = Cache::get('test_key');

        $this->assertIsArray($result);
        $this->assertEquals('POS', $result['name']);
    }

    public function testGetReturnsNullForMissingKey()
    {
        $result = Cache::get('nonexistent_key_xyz');
        $this->assertNull($result);
    }

    public function testForgetRemovesKey()
    {
        Cache::set('test_key', 'hello', 60);
        Cache::forget('test_key');

        $this->assertNull(Cache::get('test_key'));
    }

    public function testFallsBackToFileCacheWhenApcuIsDisabledForCli(): void
    {
        $storageDirectory = sys_get_temp_dir() . '/pos-cache-' . bin2hex(random_bytes(8));
        $scriptPath = $storageDirectory . '.php';
        $cachePath = dirname(__DIR__, 2) . '/Helpers/Cache.php';
        $envLoaderPath = dirname(__DIR__, 2) . '/Helpers/EnvLoader.php';

        try {
            $script = sprintf(
                "<?php\nputenv('APP_STORAGE_DIR=' . %s);\n\$_ENV['APP_STORAGE_DIR'] = %s;\nrequire %s;\nrequire %s;\n\\App\\Helpers\\Cache::set('disabled-apcu', ['source' => 'file'], 60);\necho json_encode(\\App\\Helpers\\Cache::get('disabled-apcu'));\n",
                var_export($storageDirectory, true),
                var_export($storageDirectory, true),
                var_export($envLoaderPath, true),
                var_export($cachePath, true),
            );
            file_put_contents($scriptPath, $script);

            $process = proc_open(
                [PHP_BINARY, '-d', 'apc.enable_cli=0', $scriptPath],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start the isolated APCu fallback test process.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), $stderr);
            $this->assertSame(['source' => 'file'], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            @unlink($scriptPath);
            foreach (glob($storageDirectory . '/cache/*') ?: [] as $cacheFile) {
                @unlink($cacheFile);
            }
            @rmdir($storageDirectory . '/cache');
            @rmdir($storageDirectory);
        }
    }
}
