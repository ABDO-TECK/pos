#!/usr/bin/env pwsh
# === POS System Network Diagnostics ===
# Run this script while the production POS app is running

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "   POS System Network Diagnostics" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# 1. Check if ports are listening
Write-Host "[1] Checking listening ports..." -ForegroundColor Yellow
$ports8080 = netstat -an | Select-String "8080.*LISTENING"
$ports8443 = netstat -an | Select-String "8443.*LISTENING"

if ($ports8080) {
    Write-Host "   Port 8080: " -NoNewline
    Write-Host "LISTENING" -ForegroundColor Green
    Write-Host "   $($ports8080.Line.Trim())"
} else {
    Write-Host "   Port 8080: " -NoNewline
    Write-Host "NOT LISTENING" -ForegroundColor Red
}

if ($ports8443) {
    Write-Host "   Port 8443: " -NoNewline
    Write-Host "LISTENING" -ForegroundColor Green
    Write-Host "   $($ports8443.Line.Trim())"
} else {
    Write-Host "   Port 8443: " -NoNewline
    Write-Host "NOT LISTENING" -ForegroundColor Red
}

# 2. Network profile
Write-Host "`n[2] Network Profile:" -ForegroundColor Yellow
$profiles = Get-NetConnectionProfile | Select-Object Name, NetworkCategory, InterfaceAlias
foreach ($p in $profiles) {
    $color = if ($p.NetworkCategory -eq "Private") { "Green" } else { "Red" }
    Write-Host "   $($p.InterfaceAlias): $($p.Name) => " -NoNewline
    Write-Host "$($p.NetworkCategory)" -ForegroundColor $color
}

# 3. Wi-Fi IP
Write-Host "`n[3] Wi-Fi IP Address:" -ForegroundColor Yellow
$wifiIp = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -like "Wi-Fi*" -and $_.PrefixOrigin -ne "WellKnown" }).IPAddress
if ($wifiIp) {
    Write-Host "   $wifiIp" -ForegroundColor Green
} else {
    Write-Host "   No Wi-Fi IP found" -ForegroundColor Red
}

# 4. Firewall rules
Write-Host "`n[4] POS Firewall Rules:" -ForegroundColor Yellow
$rules = netsh advfirewall firewall show rule name=all dir=in | Select-String "POS System" -Context 0,8
if ($rules) {
    foreach ($r in $rules) { Write-Host "   $($r.Line)" }
} else {
    Write-Host "   No POS firewall rules found!" -ForegroundColor Red
}

# 5. Try local access
Write-Host "`n[5] Local access test:" -ForegroundColor Yellow
try {
    $r = Invoke-WebRequest -Uri "http://127.0.0.1:8080/" -TimeoutSec 3 -UseBasicParsing
    Write-Host "   http://127.0.0.1:8080 => " -NoNewline
    Write-Host "$($r.StatusCode) OK" -ForegroundColor Green
} catch {
    Write-Host "   http://127.0.0.1:8080 => " -NoNewline
    Write-Host "FAILED" -ForegroundColor Red
}

# 6. Try network access
Write-Host "`n[6] Network access test:" -ForegroundColor Yellow
if ($wifiIp) {
    try {
        $r = Invoke-WebRequest -Uri "http://${wifiIp}:8080/" -TimeoutSec 3 -UseBasicParsing
        Write-Host "   http://${wifiIp}:8080 => " -NoNewline
        Write-Host "$($r.StatusCode) OK" -ForegroundColor Green
    } catch {
        Write-Host "   http://${wifiIp}:8080 => " -NoNewline
        Write-Host "FAILED - $_" -ForegroundColor Red
    }

    try {
        # Ignore SSL errors for self-signed cert
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = {$true}
        $r = Invoke-WebRequest -Uri "https://${wifiIp}:8443/" -TimeoutSec 3 -UseBasicParsing -SkipCertificateCheck
        Write-Host "   https://${wifiIp}:8443 => " -NoNewline
        Write-Host "$($r.StatusCode) OK" -ForegroundColor Green
    } catch {
        Write-Host "   https://${wifiIp}:8443 => " -NoNewline
        Write-Host "FAILED - $_" -ForegroundColor Red
    }
}

# 7. Check for blocking rules
Write-Host "`n[7] Checking for conflicting block rules on ports 8080/8443..." -ForegroundColor Yellow
$allRules = netsh advfirewall firewall show rule name=all dir=in verbose
$blockRules = $allRules | Select-String "Action:\s+Block" -Context 12,0
if ($blockRules) {
    $portBlock = $blockRules | Select-String "8080|8443"
    if ($portBlock) {
        Write-Host "   WARNING: Found BLOCK rules affecting ports 8080/8443!" -ForegroundColor Red
        $portBlock | ForEach-Object { Write-Host "   $($_.Line)" }
    } else {
        Write-Host "   No specific block rules for 8080/8443" -ForegroundColor Green
    }
} else {
    Write-Host "   No block rules found" -ForegroundColor Green
}

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "   Diagnostics Complete" -ForegroundColor Cyan  
Write-Host "========================================`n" -ForegroundColor Cyan

Write-Host "RECOMMENDATION:" -ForegroundColor Yellow
$publicNets = Get-NetConnectionProfile | Where-Object { $_.NetworkCategory -eq "Public" -and $_.InterfaceAlias -like "Wi-Fi*" }
if ($publicNets) {
    Write-Host "  Your Wi-Fi is set to PUBLIC. Changing to PRIVATE may fix the issue." -ForegroundColor Yellow
    Write-Host "  Run this command (as admin) to fix:" -ForegroundColor Yellow
    Write-Host "  Set-NetConnectionProfile -InterfaceAlias 'Wi-Fi' -NetworkCategory Private" -ForegroundColor Cyan
}
Write-Host ""
Read-Host "Press Enter to exit"
