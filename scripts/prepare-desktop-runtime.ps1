[CmdletBinding()]
param(
    [switch] $Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$ManifestPath = Join-Path $RepoRoot 'scripts/desktop-runtime.json'
$PortableDir = Join-Path $RepoRoot 'portable'
$BuildToolsDir = Join-Path $RepoRoot 'build-tools'
$Manifest = Get-Content -LiteralPath $ManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json

function Assert-ContainedPath {
    param([string] $Path, [string] $Parent)

    $resolvedParent = [System.IO.Path]::GetFullPath($Parent).TrimEnd('\') + '\'
    $resolvedPath = [System.IO.Path]::GetFullPath($Path)
    if (-not $resolvedPath.StartsWith($resolvedParent, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to use a path outside the build tools directory: $resolvedPath"
    }
}

function Get-RequiredFiles {
    @($Manifest.php.requiredFiles) + @($Manifest.mysql.requiredFiles)
}

function Test-PortableRuntime {
    $required = Get-RequiredFiles
    foreach ($relativePath in $required) {
        if (-not (Test-Path -LiteralPath (Join-Path $PortableDir $relativePath) -PathType Leaf)) {
            return $false
        }
    }
    return $true
}

function Test-PreparedManifest {
    $installedManifestPath = Join-Path $PortableDir 'runtime-manifest.json'
    if (-not (Test-Path -LiteralPath $installedManifestPath -PathType Leaf)) {
        return $false
    }

    try {
        $installed = Get-Content -LiteralPath $installedManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
        return [string] $installed.php.version -eq [string] $Manifest.php.version -and
            [string] $installed.mysql.version -eq [string] $Manifest.mysql.version -and
            (Test-PortableRuntime)
    } catch {
        return $false
    }
}

function Get-VerifiedArchive {
    param(
        [object] $Runtime,
        [string] $DownloadDirectory
    )

    $archivePath = Join-Path $DownloadDirectory $Runtime.archiveName
    if (-not (Test-Path -LiteralPath $archivePath -PathType Leaf)) {
        Write-Host "Downloading $($Runtime.distribution) $($Runtime.version)..."
        Invoke-WebRequest -UseBasicParsing -Uri $Runtime.url -OutFile $archivePath
    }

    $actualHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne ([string] $Runtime.sha256).ToLowerInvariant()) {
        throw "SHA-256 mismatch for $($Runtime.archiveName). Expected $($Runtime.sha256), got $actualHash."
    }
    return $archivePath
}

function Copy-DirectoryContents {
    param([string] $Source, [string] $Destination)

    if (-not (Test-Path -LiteralPath $Destination -PathType Container)) {
        # New-Item in Windows PowerShell 5.1 does not expose -LiteralPath.
        # These paths are generated inside the repository's verified runtime
        # directories, so -Path is safe and keeps the script CI-compatible.
        New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    }
    Get-ChildItem -LiteralPath $Source -Force | Copy-Item -Destination $Destination -Recurse -Force
}

function Configure-PortablePhp {
    $phpDir = Join-Path $PortableDir 'php'
    $iniPath = Join-Path $phpDir 'php.ini'
    if (-not (Test-Path -LiteralPath $iniPath -PathType Leaf)) {
        Copy-Item -LiteralPath (Join-Path $phpDir 'php.ini-production') -Destination $iniPath
    }

    $ini = Get-Content -LiteralPath $iniPath -Raw -Encoding UTF8
    if ($ini -notmatch '(?m)^\s*extension_dir\s*=') {
        $ini = 'extension_dir="ext"' + "`r`n" + $ini
    } else {
        $ini = [regex]::Replace($ini, '(?m)^\s*;?\s*extension_dir\s*=.*$', 'extension_dir="ext"', 1)
    }

    foreach ($extension in @('curl', 'fileinfo', 'gd', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'sodium', 'zip')) {
        $pattern = "(?m)^\s*;\s*extension\s*=\s*(?:php_)?$([regex]::Escape($extension))(?:\.dll)?\s*$"
        if ($ini -match $pattern) {
            $ini = [regex]::Replace($ini, $pattern, "extension=$extension", 1)
        }
    }
    [System.IO.File]::WriteAllText($iniPath, $ini, [System.Text.UTF8Encoding]::new($false))
}

if (-not $Force -and (Test-PreparedManifest)) {
    Write-Host 'Verified portable PHP/MariaDB runtime is already prepared.'
    exit 0
}

New-Item -ItemType Directory -Path $BuildToolsDir -Force | Out-Null
New-Item -ItemType Directory -Path $PortableDir -Force | Out-Null
$downloadDirectory = Join-Path $BuildToolsDir 'desktop-runtime-downloads'
$workDirectory = Join-Path $BuildToolsDir ("desktop-runtime-work-" + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $downloadDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $workDirectory -Force | Out-Null
Assert-ContainedPath $workDirectory $BuildToolsDir

try {
    $phpArchive = Get-VerifiedArchive $Manifest.php $downloadDirectory
    $mysqlArchive = Get-VerifiedArchive $Manifest.mysql $downloadDirectory

    $phpExtract = Join-Path $workDirectory 'php'
    $mysqlExtract = Join-Path $workDirectory 'mysql'
    Expand-Archive -LiteralPath $phpArchive -DestinationPath $phpExtract -Force
    Expand-Archive -LiteralPath $mysqlArchive -DestinationPath $mysqlExtract -Force

    Copy-DirectoryContents $phpExtract (Join-Path $PortableDir 'php')
    $mysqlRoot = Get-ChildItem -LiteralPath $mysqlExtract -Directory | Select-Object -First 1
    if (-not $mysqlRoot) {
        throw 'The MariaDB archive did not contain a top-level runtime directory.'
    }
    Copy-DirectoryContents $mysqlRoot.FullName (Join-Path $PortableDir 'mysql')
    Configure-PortablePhp

    if (-not (Test-PortableRuntime)) {
        throw 'The prepared portable runtime is missing one or more required files.'
    }

    $installedManifest = [ordered]@{
        schemaVersion = $Manifest.schemaVersion
        preparedAt = (Get-Date).ToUniversalTime().ToString('o')
        php = $Manifest.php
        mysql = $Manifest.mysql
    }
    $installedManifestJson = $installedManifest | ConvertTo-Json -Depth 10
    [System.IO.File]::WriteAllText(
        (Join-Path $PortableDir 'runtime-manifest.json'),
        $installedManifestJson + [Environment]::NewLine,
        [System.Text.UTF8Encoding]::new($false)
    )
    Write-Host 'Portable PHP/MariaDB runtime prepared and verified.'
} finally {
    if (Test-Path -LiteralPath $workDirectory) {
        Remove-Item -LiteralPath $workDirectory -Recurse -Force
    }
}
