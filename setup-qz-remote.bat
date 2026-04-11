@echo off
:: ══════════════════════════════════════════════════════════════
:: إعداد QZ Tray للطباعة من الهاتف عبر الشبكة المحلية
:: يجب تشغيل هذا الملف كمسؤول (Run as Administrator)
:: ══════════════════════════════════════════════════════════════

echo.
echo ╔══════════════════════════════════════════════╗
echo ║  إعداد QZ Tray للطباعة عبر الشبكة المحلية  ║
echo ╚══════════════════════════════════════════════╝
echo.

:: التحقق من صلاحيات المسؤول
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo ❌ يجب تشغيل هذا الملف كمسؤول ^(Run as Administrator^)
    echo    اضغط كليك يمين ^> Run as administrator
    pause
    exit /b 1
)

:: إضافة قاعدة فايروول لمنافذ QZ Tray (insecure — للاتصال من الهاتف)
echo 🔧 إضافة قواعد الفايروول لمنافذ QZ Tray...

netsh advfirewall firewall delete rule name="QZ Tray Insecure Ports" >nul 2>&1
netsh advfirewall firewall add rule name="QZ Tray Insecure Ports" dir=in action=allow protocol=TCP localport=8182,8283,8384,8485 enable=yes profile=private

if %errorlevel% equ 0 (
    echo ✅ تم إضافة قاعدة الفايروول بنجاح
) else (
    echo ⚠ فشل إضافة قاعدة الفايروول
)

:: إضافة قاعدة فايروول لمنافذ QZ Tray (secure)
netsh advfirewall firewall delete rule name="QZ Tray Secure Ports" >nul 2>&1
netsh advfirewall firewall add rule name="QZ Tray Secure Ports" dir=in action=allow protocol=TCP localport=8181,8282,8383,8484 enable=yes profile=private

if %errorlevel% equ 0 (
    echo ✅ تم إضافة قاعدة الفايروول للمنافذ الآمنة
) else (
    echo ⚠ فشل إضافة قاعدة الفايروول للمنافذ الآمنة
)

echo.
echo ══════════════════════════════════════════════════
echo   ✅ الإعداد اكتمل!
echo.
echo   الآن يمكنك الطباعة من الهاتف عبر:
echo   https://192.168.1.22:5173
echo.
echo   تأكد من:
echo   1. QZ Tray يعمل على الكمبيوتر
echo   2. الهاتف والكمبيوتر على نفس الشبكة
echo   3. اضغط على أيقونة الترس لاختيار الطابعة
echo ══════════════════════════════════════════════════
echo.
pause
