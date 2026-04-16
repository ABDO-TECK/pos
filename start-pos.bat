@echo off
setlocal

:: Use project folder (where this .bat lives)
set "POS_ROOT=%~dp0"
if "%POS_ROOT:~-1%"=="\" set "POS_ROOT=%POS_ROOT:~0,-1%"

:: ═══════════════════════════════════════════════════════════════
:: Frontend mode — change only the next line:
::   start or dev  → Vite dev server (port 5173, same as npm start / npm run dev)
::                   Opens https:// if certs\*-key.pem exists, else http://
::   preview       → Production preview after build (port 4173, default HTTP)
:: Note: run "npm run build" at least once before using preview
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

:: ── Get Local IP Address ───────────────────────────────────────
set "LOCAL_IP=localhost"
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /I "IPv4"') do (
    set "LOCAL_IP=%%a"
    goto :ip_found
)
:ip_found
set "LOCAL_IP=%LOCAL_IP: =%"

:: ── 4. Start frontend (npm) ──────────────────────────────────
if /I "%FRONTEND_MODE%"=="preview" (
    set FRONTEND_PORT=4173
    set "FRONTEND_URL=http://%LOCAL_IP%:4173"
    set NPM_CMD=npm run preview
) else (
    set FRONTEND_PORT=5173
    set "FRONTEND_URL=http://%LOCAL_IP%:5173"
    for /f "delims=" %%F in ('dir /b "%POS_ROOT%\certs\*-key.pem" 2^>nul') do set "FRONTEND_URL=https://%LOCAL_IP%:5173"
    if /I "%FRONTEND_MODE%"=="dev" (
        set NPM_CMD=npm run dev
    ) else (
        set NPM_CMD=npm start
    )
)

echo [%date% %time%] Checking frontend on port %FRONTEND_PORT%... >> "%LOG%"
call :port_listening %FRONTEND_PORT%
if errorlevel 1 (
    echo [%date% %time%] Starting: %NPM_CMD% >> "%LOG%"
    cd /d "%POS_ROOT%\frontend"
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
echo [%date% %time%] Opening Chrome browser at %FRONTEND_URL%... >> "%LOG%"
start chrome "%FRONTEND_URL%" || start "" "%FRONTEND_URL%"
echo [%date% %time%] Done. >> "%LOG%"
goto :eof

:: ── Returns 0 if TCP port is LISTENING, 1 otherwise ───────────
:port_listening
set "_p=%~1"
netstat -an 2>nul | find "LISTENING" | find ":%_p% " >nul
exit /b %errorlevel%
