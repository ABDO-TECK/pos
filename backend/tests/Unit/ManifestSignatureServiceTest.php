<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ManifestSignatureService;

class ManifestSignatureServiceTest extends TestCase
{
    private ManifestSignatureService $service;
    private string $privateKey;
    private string $publicKey;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sig_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);

        $keys = ManifestSignatureService::generateKeyPair(2048);
        $this->privateKey = $keys['private_key'];
        $this->publicKey = $keys['public_key'];

        file_put_contents($this->tempDir . '/public_key.pem', $this->publicKey);
        file_put_contents($this->tempDir . '/private_key.pem', $this->privateKey);

        $this->service = new ManifestSignatureService($this->tempDir . '/public_key.pem');
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->deleteDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public function testSignAndVerifyValidSignature(): void
    {
        $manifestJson = json_encode([
            'version' => '1.1.48',
            'files' => [
                ['path' => 'backend/Helpers/Logger.php', 'sha256' => 'abc123']
            ]
        ], JSON_PRETTY_PRINT);

        $sig = $this->service->signData($manifestJson, $this->privateKey);
        $this->assertNotEmpty($sig);

        $isValid = $this->service->verifySignature($manifestJson, $sig);
        $this->assertTrue($isValid, 'Valid RSA signature must be verified successfully.');
    }

    public function testTamperedDataRejectsSignature(): void
    {
        $manifestJson = json_encode(['version' => '1.1.48']);
        $tamperedJson = json_encode(['version' => '1.1.48', 'malicious_file' => 'hack.php']);

        $sig = $this->service->signData($manifestJson, $this->privateKey);

        $isValid = $this->service->verifySignature($tamperedJson, $sig);
        $this->assertFalse($isValid, 'Tampered data must fail signature verification.');
    }

    public function testWrongPublicKeyRejectsSignature(): void
    {
        $manifestJson = json_encode(['version' => '1.1.48']);
        $sig = $this->service->signData($manifestJson, $this->privateKey);

        // Generate a different keypair
        $differentKeys = ManifestSignatureService::generateKeyPair(2048);

        $isValid = $this->service->verifySignature($manifestJson, $sig, $differentKeys['public_key']);
        $this->assertFalse($isValid, 'Signature signed with different private key must be rejected.');
    }

    public function testEmptyOrCorruptedSignatureRejected(): void
    {
        $manifestJson = json_encode(['version' => '1.1.48']);

        $this->assertFalse($this->service->verifySignature($manifestJson, ''));
        $this->assertFalse($this->service->verifySignature($manifestJson, 'not-a-real-base64-signature'));
        $this->assertFalse($this->service->verifySignature('', 'some-sig'));
    }

    public function testVerifyManifestFileOnDisk(): void
    {
        $manifestPath = $this->tempDir . '/manifest.json';
        $sigPath = $this->tempDir . '/manifest.sig';

        $manifestContent = json_encode(['version' => '1.1.48', 'files' => []]);
        file_put_contents($manifestPath, $manifestContent);

        $sig = $this->service->signData($manifestContent, $this->privateKey);
        file_put_contents($sigPath, $sig);

        $result = $this->service->verifyManifestFile($manifestPath, $sigPath);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);

        // Modify manifest on disk
        file_put_contents($manifestPath, $manifestContent . ' ');
        $tamperedResult = $this->service->verifyManifestFile($manifestPath, $sigPath);
        $this->assertFalse($tamperedResult['ok']);
    }
}
