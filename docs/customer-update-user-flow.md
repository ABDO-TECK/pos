# POS Desktop: Customer Update User Flow Guide
## Friendly & Seamless Update Experience for Non-Technical Users

---

## 1. Executive Summary

This document describes the customer-facing update experience implemented in **POS Desktop Electron**.
The update system is engineered so that store managers, cashiers, and non-technical business owners can safely upgrade their POS application with a single click while guaranteeing **100% database protection, automatic backup, cryptographic verification, and silent self-healing**.

---

## 2. Customer User Journey

```
+-----------------------------------------------------------------------------------+
|                            CUSTOMER UPDATE FLOW                                   |
+-----------------------------------------------------------------------------------+
|  1. POS Started by Store Clerk (e.g. v1.1.46)                                    |
|       │                                                                           |
|       ▼                                                                           |
|  2. Update Available Auto-Detected                                                |
|       │                                                                           |
|       ▼                                                                           |
|  3. Friendly Notification Prompt Appears                                          |
|       │  "يوجد تحديث جديد لنظام نقاط البيع (v1.1.47)"                             |
|       │  [تحديث الآن]     [لاحقاً]                                               |
|       │                                                                           |
|       ▼                                                                           |
|  4. User Clicks [تحديث الآن] -> Download & Verification Progress UI               |
|       │  - Step 1: Downloading update (جاري التحميل... 80%)                      |
|       │  - Step 2: Cryptographic SHA-256 verification (التحقق من الأمان)         |
|       │  - Step 3: Pre-update safety snapshot (النسخ الاحتياطي)                   |
|       │                                                                           |
|       ▼                                                                           |
|  5. Safe Application Shutdown (SafeShutdown.js)                                   |
|       │  - Flush database connections                                             |
|       │  - Write shutdown marker (update-installer-staged.json)                   |
|       │  - Terminate child processes cleanly                                      |
|       │                                                                           |
|       ▼                                                                           |
|  6. Run Windows Installer (POS-Desktop-Setup-1.1.47.exe /S)                       |
|       │                                                                           |
|       ▼                                                                           |
|  7. Automatic Relaunch into POS (v1.1.47)                                         |
|       │                                                                           |
|       ▼                                                                           |
|  8. Success Confirmation Banner ("تم تحديث النظام بنجاح")                        |
+-----------------------------------------------------------------------------------+
```

---

## 3. Screen & Component Architecture

### A. Update Notification (`UpdateNotification.tsx`)
- **Location**: Displayed upon startup or via `Settings > System & Maintenance > تحديث النظام`.
- **Audience**: Non-technical users.
- **Language**: Clean Arabic phrasing free of developer jargon.
- **Information Shown**:
  - Main Title: *"يوجد تحديث جديد لنظام نقاط البيع"*
  - Subtitle: *"إصدار جديد متاح لتحسين الأداء وإضافة ميزات جديدة"*
  - Current Version vs. New Version: `v1.1.46` $\rightarrow$ `v1.1.47`
  - Update Size (e.g. `283.2 ميجابايت`)
  - Highlighted Release Notes
  - Safety Reassurance Badge: *"يتم الاحتفاظ بجميع بيانات وفواتير وسجلات المتجر بأمان تام أثناء التحديث."*
- **Actions**:
  - `[تحديث الآن]`: Initiates the safe download and install pipeline.
  - `[لاحقاً]`: Dismisses modal until next launch or scheduled check.

### B. Download Progress (`UpdateProgress.tsx`)
- **Interactive Multi-Step Progress Tracker**:
  1. **التحميل (Downloading)**: Live percentage bar, transferred megabytes, total payload.
  2. **التحقق من الأمان (Verifying)**: Validates SHA-256 hash against signed manifest.
  3. **النسخ الاحتياطي (Preparing)**: Captures snapshot of database and settings.
  4. **التثبيت والتشغيل (Installing)**: Graceful shutdown and installer handover.
- **Resilience**:
  - If network drops during download, shows friendly retry prompt (`[إعادة المحاولة]`).

---

## 4. Electron Backend Modules

### `electron/update/CustomerUpdateManager.js`
- Orchestrates update discovery, user notifications, progress tracking, cryptographic integrity, backup creation, safe shutdown, installer execution, and telemetry reporting.
- **Core Methods**:
  - `checkForUpdates()`: Resolves local vs remote version, detects `bootstrap_installer` vs `delta_update`.
  - `showUpdateNotification()`: Emits update status and dispatches `update_ui_opened` event.
  - `downloadUpdate(onProgress)`: Downloads package with real-time transfer stats.
  - `verifyUpdate(filePath, expectedSha256)`: Ensures file is authentic and untampered.
  - `createBackup()`: Saves snapshot to `storage/backups/snapshots/`.
  - `installUpdate(installerPath)`: Initiates `SafeShutdown` and spawns installer detached (`/S`).
  - `restartApplication()`: Relaunches app upon completion.
  - `reportUpdateResult(eventType, success, details)`: Logs telemetry events.

### `electron/update/SafeShutdown.js`
- Guarantees zero database corruption and eliminates Windows file lock conflicts:
  1. Creates shutdown status marker: `update-installer-staged.json`.
  2. Stops background PHP/MariaDB workers and QZ Tray gracefully.
  3. Closes all active renderer windows.
  4. Releases file handles before launching installer executable.

---

## 5. Failure Handling & Self-Healing Scenarios

| Failure Scenario | Automatic System Behavior | User Experience |
|---|---|---|
| **Network Interrupted during Download** | Incomplete temporary files (`.tmp`) cleaned up; download resets or resumes. | Friendly message: *"تعذر تحميل التحديث، يرجى التحقق من الاتصال بالإنترنت والمحاولة مجدداً."* with `[إعادة المحاولة]`. |
| **Checksum / Signature Mismatch** | Corrupted installer rejected immediately and purged from staging. | System alerts user safely without applying corrupted binaries. |
| **Power Failure / Sudden Reboot** | `UpdateRecoveryService` detects incomplete staging on boot, diagnoses health, and offers automatic rollback to pre-update snapshot. | Zero lost records or corrupted sales data. |
| **Installer Execution Error** | Application restores database snapshot from `storage/backups/snapshots/` and re-opens previous version. | Client returns to operational baseline (`v1.1.46`). |

---

## 6. Support Troubleshooting Guide

1. **Verify Customer Telemetry**:
   - Check `GET /api/admin/fleet/devices` or inspect `update_telemetry` table for event records (`update_ui_opened`, `update_download_started`, `update_download_completed`, `installer_started`, `installer_completed`, `installer_failed`).
2. **Inspect Recovery Status**:
   - Navigate to `Settings > System & Maintenance > Health Check & Recovery`.
   - Run `GET /api/admin/updates/recovery/diagnose` to view snapshot availability and database integrity.
3. **Manual Rollback**:
   - In the event of customer hardware failure, trigger rollback via `POST /api/updates/rollback` or click *"استرجاع النسخة السابقة"* in the UI.

---

## 7. Verification Checklist

```text
CUSTOMER UPDATE EXPERIENCE:
[✔] Update notification
[✔] Download flow
[✔] Verification
[✔] Backup
[✔] Safe shutdown
[✔] Installer launch
[✔] Restart
[✔] Success confirmation
[✔] Failure recovery
[✔] Telemetry tracking

FINAL STATUS: READY FOR NON-TECHNICAL CUSTOMER USE 🎉
```
