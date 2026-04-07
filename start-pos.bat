@echo off
setlocal

:: ═══════════════════════════════════════════════════════════════
::  واجهة الفرونت إند — اختر أحد الخيارين (غيّر السطر التالي فقط):
::    start أو dev  → تطوير مع Vite (منفذ 5173، نفس npm run dev)
::    preview       → تشغيل نسخة الإنتاج بعد build (منفذ 4173)
::  ملاحظة: preview يحتاج تشغيل "npm run build" مرة واحدة على الأقل
:: ═══════════════════════════════════════════════════════════════
set FRONTEND_MODE=start

set LOG=%TEMP%\pos-launcher.log
echo [%date% %time%] === POS Launcher started (FRONTEND_MODE=%FRONTEND_MODE%) === > "%LOG%"

:: ── 1. Start Apache ──────────────────────────────────────────
echo [%date% %time%] Starting Apache... >> "%LOG%"
tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I "httpd.exe" >nul
if errorlevel 1 (
    start "" /min "C:\xampp\apache\bin\httpd.exe"
    echo [%date% %time%] Apache started. >> "%LOG%"
) else (
    echo [%date% %time%] Apache already running. >> "%LOG%"
)

:: ── 2. Start MySQL ───────────────────────────────────────────
echo [%date% %time%] Starting MySQL... >> "%LOG%"
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if errorlevel 1 (
    start "" /min "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
    echo [%date% %time%] MySQL started. >> "%LOG%"
) else (
    echo [%date% %time%] MySQL already running. >> "%LOG%"
)

:: ── 3. Wait briefly for services ────────────────────────────
timeout /t 2 /nobreak >nul

:: ── 4. Start frontend (npm) ──────────────────────────────────
if /I "%FRONTEND_MODE%"=="preview" (
    set FRONTEND_PORT=4173
    set FRONTEND_URL=http://localhost:4173
    set NPM_CMD=npm run preview
) else (
    set FRONTEND_PORT=5173
    set FRONTEND_URL=http://localhost:5173
    if /I "%FRONTEND_MODE%"=="dev" (
        set NPM_CMD=npm run dev
    ) else (
        set NPM_CMD=npm start
    )
)

echo [%date% %time%] Checking frontend on port %FRONTEND_PORT%... >> "%LOG%"
:: يجب أن يكون المنفذ في حالة LISTENING فقط — تجاهل TIME_WAIT/CLOSE_WAIT بعد إغلاق cmd
call :port_listening %FRONTEND_PORT%
if errorlevel 1 (
    echo [%date% %time%] Starting: %NPM_CMD% >> "%LOG%"
    cd /d "C:\xampp\htdocs\pos\frontend"
    start "" /min cmd /k "%NPM_CMD%"
) else (
    echo [%date% %time%] Frontend already listening on %FRONTEND_PORT%. >> "%LOG%"
)

:: ── 5. Wait for frontend ─────────────────────────────────────
echo [%date% %time%] Waiting for LISTENING on %FRONTEND_PORT%... >> "%LOG%"
set /a tries=0
:wait_loop
timeout /t 2 /nobreak >nul
call :port_listening %FRONTEND_PORT%
if not errorlevel 1 goto open_browser
set /a tries+=1
if %tries% lss 15 goto wait_loop
echo [%date% %time%] Timeout waiting for port %FRONTEND_PORT%. Opening anyway... >> "%LOG%"

:open_browser
echo [%date% %time%] Opening browser... >> "%LOG%"
start "" "%FRONTEND_URL%"
echo [%date% %time%] Done. >> "%LOG%"
goto :eof

:: ── Returns 0 if TCP port is LISTENING, 1 otherwise ───────────
:port_listening
set "_p=%~1"
:: LISTENING + مسافة بعد رقم المنفذ لتجنب مطابقة 5173 داخل 51730
netstat -an 2>nul | find "LISTENING" | find ":%_p% " >nul
exit /b %errorlevel%
