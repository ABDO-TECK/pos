<?php

namespace App\Services;

/**
 * Resolves the directory containing deployed, update-managed artifacts.
 *
 * In a packaged desktop build the PHP entrypoint is
 * app.asar.unpacked/backend/backend.phar. The PHAR itself is executable
 * payload, not the directory that delta updates may target.
 */
final class UpdateRuntimePaths
{
    public static function deployedRoot(?string $developmentRoot = null): string
    {
        $configuredRoot = getenv('APP_DEPLOY_ROOT');
        if (is_string($configuredRoot) && trim($configuredRoot) !== '') {
            return self::normalize($configuredRoot);
        }

        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return self::normalize(dirname($pharPath, 2));
        }

        return self::normalize($developmentRoot ?: (realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2)));
    }

    public static function isPackagedPharDeployment(string $root): bool
    {
        return is_file(self::normalize($root) . '/backend/backend.phar');
    }

    public static function backendHealthEntrypoint(string $root): string
    {
        $normalizedRoot = self::normalize($root);
        return self::isPackagedPharDeployment($normalizedRoot)
            ? $normalizedRoot . '/backend/backend.phar'
            : $normalizedRoot . '/backend/index.php';
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
