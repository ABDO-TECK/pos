$ErrorActionPreference = "Stop"
$WorkingDir = "c:\xampp\htdocs\pos"
$ToolsDir = "$WorkingDir\build-tools"
$PortableDir = "$WorkingDir\portable"
$TrayDir = "$WorkingDir\tray"

Write-Host "Creating directories..."
if (-not (Test-Path $ToolsDir)) { New-Item -ItemType Directory -Path $ToolsDir | Out-Null }
if (-not (Test-Path "$PortableDir\java")) { New-Item -ItemType Directory -Path "$PortableDir\java" -Force | Out-Null }
if (-not (Test-Path "$PortableDir\qz-tray")) { New-Item -ItemType Directory -Path "$PortableDir\qz-tray" -Force | Out-Null }

$JdkUrl = "https://github.com/bell-sw/Liberica/releases/download/11.0.22+12/bellsoft-jdk11.0.22+12-windows-amd64.zip"
$JreUrl = "https://github.com/bell-sw/Liberica/releases/download/11.0.22+12/bellsoft-jre11.0.22+12-windows-amd64.zip"
$AntUrl = "https://dlcdn.apache.org//ant/binaries/apache-ant-1.10.14-bin.zip"

$JdkZip = "$ToolsDir\jdk.zip"
$JreZip = "$ToolsDir\jre.zip"
$AntZip = "$ToolsDir\ant.zip"

Write-Host "Downloading JDK..."
if (-not (Test-Path $JdkZip)) { Invoke-WebRequest -Uri $JdkUrl -OutFile $JdkZip }

Write-Host "Downloading JRE..."
if (-not (Test-Path $JreZip)) { Invoke-WebRequest -Uri $JreUrl -OutFile $JreZip }

Write-Host "Downloading Ant..."
if (-not (Test-Path $AntZip)) { Invoke-WebRequest -Uri $AntUrl -OutFile $AntZip }

Write-Host "Extracting tools..."
if (-not (Test-Path "$ToolsDir\jdk")) {
    Expand-Archive -Path $JdkZip -DestinationPath "$ToolsDir\jdk_temp" -Force
    $extractedJdk = Get-ChildItem "$ToolsDir\jdk_temp" | Select-Object -First 1
    Rename-Item $extractedJdk.FullName "$ToolsDir\jdk"
}
if (-not (Test-Path "$ToolsDir\ant")) {
    Expand-Archive -Path $AntZip -DestinationPath "$ToolsDir\ant_temp" -Force
    $extractedAnt = Get-ChildItem "$ToolsDir\ant_temp" | Select-Object -First 1
    Rename-Item $extractedAnt.FullName "$ToolsDir\ant"
}

Write-Host "Extracting JRE to portable..."
if (-not (Test-Path "$PortableDir\java\bin\java.exe")) {
    Expand-Archive -Path $JreZip -DestinationPath "$ToolsDir\jre_temp" -Force
    $extractedJre = Get-ChildItem "$ToolsDir\jre_temp" | Select-Object -First 1
    Copy-Item -Path "$($extractedJre.FullName)\*" -Destination "$PortableDir\java" -Recurse -Force
}

$env:JAVA_HOME = "$ToolsDir\jdk"
$env:ANT_HOME = "$ToolsDir\ant"
$env:Path = "$env:JAVA_HOME\bin;$env:ANT_HOME\bin;$env:Path"

Write-Host "Building QZ Tray..."
Set-Location $TrayDir
& ant distribute

if ($LASTEXITCODE -ne 0) {
    Write-Error "Ant build failed!"
    exit 1
}

Write-Host "Copying qz-tray files..."
Copy-Item -Path "$TrayDir\out\dist\*" -Destination "$PortableDir\qz-tray" -Recurse -Force

Write-Host "Done!"
