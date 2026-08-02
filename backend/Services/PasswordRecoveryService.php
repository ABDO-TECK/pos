<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use App\Helpers\AuditLog;
use App\Helpers\Logger;
use App\Helpers\PasswordHasher;
use PDO;

/**
 * Local password recovery used by the trusted desktop recovery flow.
 *
 * This service deliberately has no HTTP endpoint. A forgotten-password reset
 * is a local operator action and must not become an unauthenticated remote
 * account-takeover primitive. The Electron main process invokes the CLI
 * command only after the user has confirmed the operation in the desktop UI.
 */
final class PasswordRecoveryService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Reset an active account password and revoke every existing session.
     *
     * @return array{ok: bool, user_id?: int, error?: string, errors?: array}
     */
    public function resetByEmail(string $email, string $password): array
    {
        $email = trim($email);
        $validationErrors = Validator::validate(
            ['email' => $email, 'password' => $password],
            [
                'email' => 'required|string|email|max:254',
                'password' => 'required|string|max:256|strong_password',
            ]
        );
        if ($validationErrors !== []) {
            return ['ok' => false, 'error' => 'Invalid recovery details.', 'errors' => $validationErrors];
        }

        $statement = $this->db->prepare(
            'SELECT id, is_active FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int) $user['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'Active account not found.'];
        }

        $userId = (int) $user['id'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE users
                 SET password = :password, force_password_change = 0
                 WHERE id = :id AND is_active = 1'
            )->execute([
                'password' => PasswordHasher::hash($password),
                'id' => $userId,
            ]);

            // A password reset must invalidate both short-lived and refresh
            // sessions, including sessions on other devices.
            $this->db->prepare('DELETE FROM tokens WHERE user_id = :user_id')
                ->execute(['user_id' => $userId]);
            $timestampExpression = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'UTC_TIMESTAMP()'
                : 'CURRENT_TIMESTAMP';
            $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = COALESCE(revoked_at, ' . $timestampExpression . ')
                 WHERE user_id = :user_id'
            )->execute(['user_id' => $userId]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('Local password recovery failed', Logger::exceptionContext($exception));
            throw $exception;
        }

        // No password or reset token is ever written to the audit trail.
        AuditLog::log(null, 'local_password_recovery', 'user', $userId, null, [
            'sessions_revoked' => true,
            'source' => 'desktop_recovery',
        ]);

        return ['ok' => true, 'user_id' => $userId];
    }
}
