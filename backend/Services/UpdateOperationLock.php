<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cross-process coordination for backend and Electron update operations.
 *
 * The lock is intentionally a small JSON file in the shared runtime data
 * directory so PHP and Node can enforce the same ownership contract.
 */
final class UpdateOperationLock
{
    public const TTL_SECONDS = 300;

    private string $storageDir;
    private string $lockPath;
    private int $ttlSeconds;
    private ?string $ownerId = null;

    public function __construct(string $storageDir, int $ttlSeconds = self::TTL_SECONDS)
    {
        $this->storageDir = rtrim(str_replace('\\', '/', $storageDir), '/');
        $this->lockPath = $this->storageDir . '/update-operation.lock';
        $this->ttlSeconds = max(1, $ttlSeconds);
    }

    /**
     * @return array{acquired:bool, owner_id?:string, operation?:string, lock_path?:string, reason_code?:string, message?:string, owner?:array|null, recovered_from?:array|null}
     */
    public function acquire(string $operation, array $context = []): array
    {
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            return $this->unavailable('Unable to create the shared update coordination directory.');
        }

        $ownerId = bin2hex(random_bytes(16));
        $payload = [
            'owner_id' => $ownerId,
            'operation' => $operation,
            'pid' => getmypid(),
            'time' => time(),
            'started_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'context' => $context,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $recoveredFrom = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = @fopen($this->lockPath, 'x');
            if (is_resource($handle)) {
                $written = @fwrite($handle, $encoded);
                @fflush($handle);
                @fclose($handle);
                if ($written !== strlen($encoded)) {
                    @unlink($this->lockPath);
                    return $this->unavailable('Unable to persist shared update ownership.');
                }

                $this->ownerId = $ownerId;
                $result = [
                    'acquired' => true,
                    'owner_id' => $ownerId,
                    'operation' => $operation,
                    'lock_path' => $this->lockPath,
                ];
                if ($recoveredFrom !== null) {
                    $result['recovered_from'] = $this->safeOwner($recoveredFrom);
                }
                return $result;
            }

            $owner = $this->readOwner();
            if ($owner !== null && $this->ageSeconds($owner) <= $this->ttlSeconds) {
                return [
                    'acquired' => false,
                    'reason_code' => 'update_in_progress',
                    'message' => 'Another update or sale operation is in progress.',
                    'owner' => $this->safeOwner($owner),
                ];
            }

            if ($owner === null && is_file($this->lockPath)) {
                $age = time() - ((int) @filemtime($this->lockPath));
                if ($age <= $this->ttlSeconds) {
                    return $this->unavailable('The shared update coordination lock is unreadable.');
                }
            }

            $stalePath = $this->lockPath . '.stale.' . bin2hex(random_bytes(8));
            if (@rename($this->lockPath, $stalePath)) {
                @unlink($stalePath);
                $recoveredFrom = $owner;
                continue;
            }

            // Another process may have claimed or quarantined the stale file
            // after our read. Retry the atomic create before reporting a
            // coordination failure, never unlinking a newer owner.
            if (!is_file($this->lockPath)) {
                continue;
            }

            return $this->unavailable('The stale shared update coordination lock could not be recovered.');
        }

        return $this->unavailable('The shared update coordination lock could not be acquired.');
    }

    public function release(): bool
    {
        if ($this->ownerId === null || !is_file($this->lockPath)) {
            return false;
        }

        $owner = $this->readOwner();
        if ($owner === null || ($owner['owner_id'] ?? null) !== $this->ownerId) {
            return false;
        }

        $released = @unlink($this->lockPath);
        if ($released) {
            $this->ownerId = null;
        }
        return $released;
    }

    public function heartbeat(string $phase, array $context = []): bool
    {
        if ($this->ownerId === null) {
            return false;
        }

        $owner = $this->readOwner();
        if ($owner === null || ($owner['owner_id'] ?? null) !== $this->ownerId) {
            return false;
        }

        $owner['phase'] = $phase;
        $owner['updated_at'] = gmdate('c');
        $owner['time'] = time();
        $owner['context'] = array_merge(is_array($owner['context'] ?? null) ? $owner['context'] : [], $context);
        $encoded = json_encode($owner, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return @file_put_contents($this->lockPath, $encoded, LOCK_EX) !== false;
    }

    public static function heartbeatOwner(
        string $storageDir,
        string $ownerId,
        string $phase,
        array $context = []
    ): bool {
        $lock = new self($storageDir);
        $lock->ownerId = $ownerId;
        return $lock->heartbeat($phase, $context);
    }

    public function currentOwner(): ?array
    {
        $owner = $this->readOwner();
        return $owner === null ? null : $this->safeOwner($owner);
    }

    public static function isActiveState(?array $state, int $ttlSeconds = self::TTL_SECONDS): bool
    {
        if (!is_array($state) || !in_array($state['state'] ?? null, [
            'backing_up',
            'downloading',
            'verifying',
            'applying',
            'migrating',
            'desktop_handoff_pending',
            'full_ready_to_install',
            'installing',
        ], true)) {
            return false;
        }

        $updatedAt = isset($state['updated_at']) ? strtotime((string) $state['updated_at']) : false;
        if ($updatedAt === false) {
            return true;
        }

        return (time() - $updatedAt) <= max(1, $ttlSeconds);
    }

    private function readOwner(): ?array
    {
        if (!is_file($this->lockPath)) {
            return null;
        }
        $content = @file_get_contents($this->lockPath);
        $decoded = json_decode((string) $content, true);
        return is_array($decoded) && isset($decoded['owner_id'], $decoded['operation']) ? $decoded : null;
    }

    private function ageSeconds(array $owner): int
    {
        if (isset($owner['time'])) {
            return max(0, time() - (int) $owner['time']);
        }
        $updatedAt = isset($owner['updated_at']) ? strtotime((string) $owner['updated_at']) : false;
        return $updatedAt === false ? $this->ttlSeconds + 1 : max(0, time() - $updatedAt);
    }

    private function safeOwner(array $owner): array
    {
        return [
            'owner_id' => (string) ($owner['owner_id'] ?? ''),
            'operation' => (string) ($owner['operation'] ?? 'unknown'),
            'pid' => isset($owner['pid']) ? (int) $owner['pid'] : null,
            'phase' => isset($owner['phase']) ? (string) $owner['phase'] : null,
            'time' => isset($owner['time']) ? (int) $owner['time'] : null,
            'updated_at' => isset($owner['updated_at']) ? (string) $owner['updated_at'] : null,
            'context' => is_array($owner['context'] ?? null) ? $owner['context'] : [],
            'age_seconds' => $this->ageSeconds($owner),
        ];
    }

    /** @return array{acquired:false, reason_code:string, message:string, owner:null} */
    private function unavailable(string $message): array
    {
        return [
            'acquired' => false,
            'reason_code' => 'update_lock_unavailable',
            'message' => $message,
            'owner' => null,
        ];
    }
}
