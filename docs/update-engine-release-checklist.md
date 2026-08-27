# POS UPDATE ENGINE PRODUCTION RELEASE CHECKLIST

This checklist must be followed before, during, and after publishing any new incremental (delta) or full release to GitHub Releases.

---

## 1. Pre-Release Preparation (Developer Workstation)

- [ ] **Automated Test Suites Passed**:
  - Run Backend PHPUnit: `C:\xampp\php\php.exe backend/vendor/bin/phpunit backend/tests/Unit`
  - Run Frontend Vitest: `cd frontend && npm test`
  - Run Build: `cd frontend && npm run build`
- [ ] **Version Bump & Changelog**:
  - Update `version.json` with the new semantic version (e.g. `1.1.47`).
  - Add bulleted release notes to `"changelog"` in `version.json`.
  - Set `"minimum_supported_version"` appropriately if breaking schema changes are introduced.
- [ ] **Private Key Present**:
  - Ensure developer private key exists at `release/private_key.pem` (never commit to git).

---

## 2. Delta Package Generation

- [ ] **Execute Packaging Script**:
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts/generate-delta-package.ps1 -ToVersion 1.1.47 -FromVersion 1.1.46
  ```
- [ ] **Verify Generated Assets in `release/{version}/`**:
  - `manifest.json`: Check that paths are relative and clean, SHA-256 hashes match changed files.
  - `manifest.sig`: Verify that RSA-2048 signature was generated.
  - `delta-{from}-to-{to}.zip` and `delta.zip`: Verify ZIP archive contains only modified/added files without `.env` or sensitive credentials.
- [ ] **Verify Cryptographic Signatures Locally**:
  ```bash
  C:\xampp\php\php.exe scripts/simulate-production-release.php
  ```

---

## 3. GitHub Release Publishing

- [ ] **Draft New GitHub Release**:
  - Go to GitHub Repository: `https://github.com/ABDO-TECK/pos/releases/new`
  - Create Tag: `v1.1.47` (must match `v{version}` format).
  - Release Title: `POS System v1.1.47`
  - Paste release notes / changelog in description body.
- [ ] **Upload Release Assets**:
  - [ ] `manifest.json`
  - [ ] `manifest.sig`
  - [ ] `delta-1.1.46-to-1.1.47.zip` (and/or `delta.zip`)
  - [ ] *(Optional)* Full installer / portable package if full update is needed.
- [ ] **Publish Release**:
  - Click **"Publish release"**.

---

## 4. Post-Publish Verification (POS Client)

- [ ] **Check Updates via Admin Update Center**:
  - Login as Administrator (`updates.view` and `updates.check` permissions).
  - Open **Settings > System and Maintenance > Admin Update Center**.
  - Click **"التحقق من التحديثات" (Check Updates)**.
  - Verify release tag `v1.1.47`, file count, and changelog display correctly.
- [ ] **Test Delta Installation**:
  - Click **"تثبيت التحديث الآن" (Install Update)**.
  - Watch live terminal output:
    1. Pre-update disk space check (>= 100MB).
    2. Automatic MySQL database dump created.
    3. Atomic file backup snapshot created.
    4. RSA digital signature verified.
    5. Delta ZIP extracted with ZipSlip guards.
    6. Files replaced atomically via temp renames.
    7. Database migrations applied.
    8. Transaction state marked as `completed`.
- [ ] **Verify Rollback Functionality**:
  - Open **"نقاط الاسترجاع" (Rollback Snapshots)**.
  - Verify pre-update snapshot `patch_1.1.46_to_1.1.47_{timestamp}` is listed.
- [ ] **Audit History Verification**:
  - Click **"سجل التحديثات" (Update History)** and verify successful entry recorded.
