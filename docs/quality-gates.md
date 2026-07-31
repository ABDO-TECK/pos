# Reproducible quality gates

Run the complete local/CI gate from the repository root:

```powershell
npm run quality
```

The command performs clean `npm ci` installs at the root and in `frontend`,
rejects invalid direct dependency trees with `npm ls --depth=0`, runs the
quality-runner regression tests, frontend lint/typecheck/unit tests/build, and
the complete backend PHPUnit suite.

## Prerequisites

- Node.js matching the engines required by the lockfiles (Node.js 22.12+ is
  sufficient for the current Vite toolchain).
- PHP 8.1+ with the extensions required by `backend/composer.lock`, including
  `gd`, `zip`, and `pdo_mysql`. Composer also needs `unzip` or `7z` when the PHP
  ZIP extension is unavailable. On Windows, the runner checks `PHP_BINARY`,
  `php.exe` on `PATH`, then the standard XAMPP path
  `C:\xampp\php\php.exe`.
- Backend dependencies installed exactly from the Composer lock:

  ```powershell
  composer install --working-dir=backend --no-interaction --prefer-dist
  ```

- MySQL 8.0 for integration tests. Create an isolated test database and user,
  import the schema, and point the backend test process at it:

  ```sql
  CREATE DATABASE pos_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'pos_test'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-local-secret';
  GRANT ALL PRIVILEGES ON pos_test.* TO 'pos_test'@'127.0.0.1';
  ```

  ```powershell
  mysql -h 127.0.0.1 -u pos_test -p pos_test < database/pos_schema.sql
  $env:DB_HOST = '127.0.0.1'
  $env:DB_PORT = '3306'
  $env:DB_NAME = 'pos_test'
  $env:DB_USER = 'pos_test'
  $env:DB_PASS = 'replace-with-a-local-secret'
  npm run quality
  ```

Never point the integration suite at a production database. CI should provision
an empty MySQL 8.0 service/database, install Composer dependencies from
`backend/composer.lock`, and invoke the same `npm run quality` command.
