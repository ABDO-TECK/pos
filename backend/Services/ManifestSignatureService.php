<?php

namespace App\Services;

use App\Helpers\Logger;
use RuntimeException;
use Throwable;

class ManifestSignatureService
{
    private ?string $publicKeyPath;

    public function __construct(?string $publicKeyPath = null)
    {
        $this->publicKeyPath = $publicKeyPath ? str_replace('\\', '/', $publicKeyPath) : $this->resolveDefaultPublicKeyPath();
    }

    public function getPublicKeyPath(): ?string
    {
        return $this->publicKeyPath;
    }

    /**
     * Verify data against an RSA signature using SHA-256.
     *
     * @param string $data The original string data (e.g. manifest.json content)
     * @param string $signature The signature (raw binary or base64 encoded)
     * @param string|null $customPublicKey Optional public key content or path
     * @return bool True if valid, false otherwise
     */
    public function verifySignature(string $data, string $signature, ?string $customPublicKey = null): bool
    {
        if (trim($data) === '' || trim($signature) === '') {
            Logger::warning('Manifest signature verification failed: empty data or signature provided.');
            return false;
        }

        $publicKey = $this->loadPublicKey($customPublicKey);
        if (!$publicKey) {
            Logger::error('Manifest signature verification failed: Public key could not be loaded.');
            return false;
        }

        // Handle base64 encoded signatures or raw binary signatures
        $rawSignature = $signature;
        $decoded = base64_decode(trim($signature), true);
        if ($decoded !== false && base64_encode($decoded) === trim($signature)) {
            $rawSignature = $decoded;
        }

        try {
            $result = openssl_verify($data, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256);
            if ($result === 1) {
                return true;
            }

            if ($result === 0) {
                Logger::warning('Manifest signature verification rejected: Invalid cryptographic signature.');
                return false;
            }

            $sslError = openssl_error_string() ?: 'Unknown OpenSSL error';
            Logger::error('OpenSSL error during signature verification', ['error' => $sslError]);
            return false;
        } catch (Throwable $e) {
            Logger::error('Exception during manifest signature verification', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verify a manifest file on disk against its signature file.
     *
     * @param string $manifestPath Path to manifest.json
     * @param string $signaturePath Path to manifest.sig
     * @param string|null $customPublicKey Optional custom public key path or string
     * @return array{ok: bool, error: ?string}
     */
    public function verifyManifestFile(string $manifestPath, string $signaturePath, ?string $customPublicKey = null): array
    {
        if (!is_file($manifestPath)) {
            return ['ok' => false, 'error' => "Manifest file not found at: {$manifestPath}"];
        }

        if (!is_file($signaturePath)) {
            return ['ok' => false, 'error' => "Signature file not found at: {$signaturePath}"];
        }

        $manifestContent = @file_get_contents($manifestPath);
        if ($manifestContent === false) {
            return ['ok' => false, 'error' => "Could not read manifest file: {$manifestPath}"];
        }

        $signatureContent = @file_get_contents($signaturePath);
        if ($signatureContent === false) {
            return ['ok' => false, 'error' => "Could not read signature file: {$signaturePath}"];
        }

        $valid = $this->verifySignature($manifestContent, $signatureContent, $customPublicKey);
        if (!$valid) {
            return ['ok' => false, 'error' => 'Digital signature verification failed. The update manifest may have been modified or signed with an unauthorized key.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Sign data using a private RSA key (for developer / CI release automation).
     *
     * @param string $data Content to sign
     * @param string $privateKeyContentOrPath Private key PEM content or path to .pem file
     * @param string|null $passphrase Optional passphrase for private key
     * @return string Base64 encoded signature
     */
    public function signData(string $data, string $privateKeyContentOrPath, ?string $passphrase = null): string
    {
        $privKeyResource = $this->loadPrivateKey($privateKeyContentOrPath, $passphrase);
        if (!$privKeyResource) {
            throw new RuntimeException('Could not load private key for signing.');
        }

        $signature = '';
        $success = openssl_sign($data, $signature, $privKeyResource, OPENSSL_ALGO_SHA256);
        if (!$success) {
            $sslError = openssl_error_string() ?: 'Unknown OpenSSL signing error';
            throw new RuntimeException("Failed to sign manifest: {$sslError}");
        }

        return base64_encode($signature);
    }

    /**
     * Generate a new RSA KeyPair (helper for test suites and initial key setup).
     *
     * @param int $bits Key length (default: 2048)
     * @return array{private_key: string, public_key: string}
     */
    public static function generateKeyPair(int $bits = 2048): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $cnfPath = self::resolveOpenSslCnfPath();
        if ($cnfPath !== null) {
            $config['config'] = $cnfPath;
        }

        $res = openssl_pkey_new($config);
        if (!$res) {
            throw new RuntimeException('Failed to generate OpenSSL keypair: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $privateKey = '';
        if ($cnfPath !== null) {
            openssl_pkey_export($res, $privateKey, null, $config);
        } else {
            openssl_pkey_export($res, $privateKey);
        }

        $details = openssl_pkey_get_details($res);
        $publicKey = $details['key'] ?? '';

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }

    private static function resolveOpenSslCnfPath(): ?string
    {
        $envCnf = getenv('OPENSSL_CONF');
        if ($envCnf && is_file($envCnf)) {
            return str_replace('\\', '/', $envCnf);
        }

        $candidates = [
            'C:/xampp/php/extras/ssl/openssl.cnf',
            'C:/xampp/apache/bin/openssl.cnf',
            'C:/php/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }


    /**
     * Load public key resource or string.
     */
    private function loadPublicKey(?string $customPublicKey = null): mixed
    {
        $keyContent = null;

        if ($customPublicKey !== null) {
            if (is_file($customPublicKey)) {
                $keyContent = @file_get_contents($customPublicKey);
            } else {
                $keyContent = $customPublicKey;
            }
        } elseif ($this->publicKeyPath && is_file($this->publicKeyPath)) {
            $keyContent = @file_get_contents($this->publicKeyPath);
        }

        if (!$keyContent) {
            return false;
        }

        return openssl_pkey_get_public($keyContent);
    }

    /**
     * Load private key resource.
     */
    private function loadPrivateKey(string $keyContentOrPath, ?string $passphrase = null): mixed
    {
        $keyContent = is_file($keyContentOrPath) ? @file_get_contents($keyContentOrPath) : $keyContentOrPath;
        if (!$keyContent) {
            return false;
        }

        return openssl_pkey_get_private($keyContent, $passphrase ?? '');
    }

    /**
     * Auto-discover default public key from certs directories.
     */
    private function resolveDefaultPublicKeyPath(): ?string
    {
        $baseDir = str_replace('\\', '/', realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        $candidates = [
            $baseDir . '/backend/certs/update_public_key.pem',
            $baseDir . '/certs/update_public_key.pem',
            $baseDir . '/release/public_key.pem',
            dirname(__DIR__) . '/certs/update_public_key.pem',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }
}
