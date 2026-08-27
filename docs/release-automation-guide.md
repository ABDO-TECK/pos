# POS Update Infrastructure: Release Automation Guide (CI/CD)

## 1. Overview

The POS Update Engine release pipeline is automated via GitHub Actions in [`.github/workflows/release.yml`](file:///c:/xampp/htdocs/pos/.github/workflows/release.yml).

Every time a production version tag (`v*`) is pushed, the workflow automatically:
1. Runs full backend and frontend unit test suites.
2. Verifies that the Git tag strictly matches the version in [`version.json`](file:///c:/xampp/htdocs/pos/version.json).
3. Determines release type (**Bootstrap** vs **Delta**).
4. Packages and computes SHA-256 hashes for release files.
5. Signs `manifest.json` with the RSA-2048 private key stored in GitHub Secrets.
6. Automatically creates the GitHub Release and uploads all distribution assets.

---

## 2. Release Flow Diagram

```mermaid
graph TD
    A[Developer Pushes Tag e.g. v1.1.49] --> B[GitHub Actions: release.yml]
    B --> C[1. Run Backend & Frontend Unit Tests]
    C --> D{Tests Pass?}
    D -->|NO| E[FAIL: Abort Pipeline with Error]
    D -->|YES| F[2. Validate Tag matches version.json]
    F --> G{Tag Matches?}
    G -->|NO| E
    G -->|YES| H{Tag contains '-bootstrap'?}
    
    H -->|YES| I[Generate Full Package: full-package.zip]
    H -->|NO| J[Compute Diff vs Previous Tag & Generate Delta Package: delta.zip]
    
    I --> K[Load secrets.UPDATE_PRIVATE_KEY & Sign manifest.json]
    J --> K
    
    K --> L[Verify RSA Signature against update_public_key.pem]
    L --> M[Wipe Ephemeral Private Key from Memory/Disk]
    M --> N[Create GitHub Release & Upload Assets]
    N --> O[POS Clients Automatically Detect & Download Update via GitHub Releases]
```

---

## 3. Required GitHub Secrets

To enable automated cryptographic signing in GitHub Actions:

1. Open your GitHub repository &rarr; **Settings** &rarr; **Secrets and variables** &rarr; **Actions**.
2. Click **New repository secret**.
3. Create the secret:
   - **Name**: `UPDATE_PRIVATE_KEY`
   - **Secret**: Paste the full content of your private key (including `-----BEGIN RSA PRIVATE KEY-----` and `-----END RSA PRIVATE KEY-----`).

> [!CAUTION]
> Never commit `release/private_key.pem` to the Git repository. The workflow reads the key strictly from `secrets.UPDATE_PRIVATE_KEY` into an ephemeral file and wipes it immediately after signing.

---

## 4. Standard Developer Release Workflow

### Step 1: Implement & Test Changes
Make your code changes, run quality checks and tests:
```bash
# Run backend tests
php backend/vendor/bin/phpunit

# Run frontend tests
npm --prefix frontend test
```

### Step 2: Update `version.json`
Bump the version and update the changelog in `version.json`:
```json
{
    "version": "1.1.49",
    "application_version": "1.1.49",
    "update_engine_version": "1.0.0",
    "released_at": "2026-08-28",
    "changelog": [
        "إصلاح: تحسين سرعة معالجة الفواتير في وضع عدم الاتصال.",
        "تحسين: إضافة خيار تصدير التقارير بتنسيق Excel مُحسّن."
    ],
    "requires_npm_install": false
}
```

### Step 3: Commit Changes
```bash
git add .
git commit -m "release: v1.1.49 invoice performance and export improvements"
git push origin main
```

### Step 4: Create and Push Git Release Tag

#### For an Incremental Delta Release (Default):
```bash
git tag -a v1.1.49 -m "Release v1.1.49: POS Incremental Delta Release"
git push origin v1.1.49
```

#### For a Full Bootstrap Migration Release:
```bash
git tag -a v1.1.49-bootstrap -m "Release v1.1.49-bootstrap: POS Bootstrap Release"
git push origin v1.1.49-bootstrap
```

### Step 5: GitHub Actions Automatically Publishes Release
The workflow will run and create the release on GitHub with all required assets:
- `delta-1.1.48-to-1.1.49.zip` (or `full-package.zip`)
- `manifest.json`
- `manifest.sig`
- `release-notes.md`

---

## 5. Local Release Simulation & Testing

You can test the release generation and signing logic locally before pushing:

```bash
# Test release packaging locally
php scripts/build-release-package.php --tag=v1.1.48 --private-key=release/private_key.pem

# Run full release automation validation test suite
php scripts/test-release-workflow.php
```
