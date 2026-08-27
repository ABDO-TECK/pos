Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$scriptPath = Join-Path $PSScriptRoot 'release.ps1'
$guiScriptPath = Join-Path $PSScriptRoot 'release-gui.ps1'
$configPath = Join-Path $PSScriptRoot 'release-manager.config.json'

function Invoke-Release {
    param([string[]] $ReleaseArgs)

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & powershell -NoProfile -ExecutionPolicy Bypass -File $scriptPath @ReleaseArgs 2>&1
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    [PSCustomObject]@{
        ExitCode = $LASTEXITCODE
        Output = ($output | Out-String)
    }
}

function Assert-True {
    param(
        [bool] $Condition,
        [string] $Message
    )
    if (-not $Condition) {
        throw $Message
    }
}

function Assert-Matches {
    param(
        [string] $Text,
        [string] $Pattern,
        [string] $Message
    )
    if ($Text -notmatch $Pattern) {
        throw "$Message`nExpected pattern: $Pattern`nActual output:`n$Text"
    }
}

function Assert-Equals {
    param(
        [object] $Actual,
        [object] $Expected,
        [string] $Message
    )
    if ($Actual -ne $Expected) {
        throw "$Message`nExpected: $Expected`nActual: $Actual"
    }
}

function Assert-ArrayEquals {
    param(
        [string[]] $Actual,
        [string[]] $Expected,
        [string] $Message
    )

    $actualText = @($Actual) -join '|'
    $expectedText = @($Expected) -join '|'
    if ($actualText -ne $expectedText) {
        throw "$Message`nExpected: $expectedText`nActual: $actualText"
    }
}

function Assert-ThrowsMatching {
    param(
        [scriptblock] $ScriptBlock,
        [string] $Pattern,
        [string] $Message
    )

    try {
        & $ScriptBlock
    } catch {
        if ($_.Exception.Message -match $Pattern) {
            return
        }
        throw "$Message`nExpected pattern: $Pattern`nActual error: $($_.Exception.Message)"
    }

    throw "$Message`nExpected an exception matching: $Pattern"
}

if (-not (Test-Path $scriptPath)) {
    throw "Missing scripts/release.ps1"
}
if (-not (Test-Path $guiScriptPath)) {
    throw "Missing scripts/release-gui.ps1"
}
if (-not (Test-Path $configPath)) {
    throw "Missing scripts/release-manager.config.json"
}

$content = Get-Content $scriptPath -Raw
$guiContent = Get-Content $guiScriptPath -Raw
$configContent = Get-Content $configPath -Raw

if (($content + $guiContent + $configContent) -match '/update/apply') {
    throw 'release tooling must not call backend /update/apply'
}

if (($content + $guiContent + $configContent) -match '\bmigrate\b') {
    throw 'release tooling must not run migrations'
}

$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($guiScriptPath, [ref] $null, [ref] $parseErrors) | Out-Null
Assert-True ($parseErrors.Count -eq 0) "release-gui.ps1 should parse without errors: $($parseErrors | Out-String)"
Assert-True ($guiContent -notmatch '\$cleanDist\s*=') 'release-gui.ps1 must not assign to $cleanDist because release.ps1 defines a [switch] $CleanDist when dot-sourced'
Assert-Matches $guiContent 'Application\]::Run\(\$form\)' 'release-gui.ps1 must use Application.Run($form)'
Assert-Matches $guiContent '\[switch\]\s+\$DebugGui' 'release-gui.ps1 should expose -DebugGui for startup diagnostics'
Assert-Matches $guiContent "TabPage\]::new\('Rollback'\)" 'release-gui.ps1 should expose a Rollback tab'
Assert-Matches $content "'rollback-revert'" 'release.ps1 should expose rollback-revert mode'
Assert-Matches $content "'rollback-abort'" 'release.ps1 should expose rollback-abort mode'
Assert-Matches $content "'rollback-continue'" 'release.ps1 should expose rollback-continue mode'
Assert-True (($content + $guiContent) -notmatch 'reset\s+--hard') 'rollback tooling must not use git reset --hard'
Assert-True (($content + $guiContent) -notmatch 'push\s+--force|push\s+-f') 'rollback tooling must not use force push'

$env:RELEASE_MANAGER_TEST_IMPORT = '1'
. $scriptPath
Remove-Item Env:\RELEASE_MANAGER_TEST_IMPORT

$originalRepoRoot = $RepoRoot
$originalConfigPath = $ReleaseManagerConfigPath
$encodingTestRoot = Join-Path $env:TEMP 'pos-release-encoding-test'
if (Test-Path $encodingTestRoot) {
    Remove-Item -LiteralPath $encodingTestRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $encodingTestRoot | Out-Null
try {
    $arabicText = [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('2KfZhNmF2YXZitiy2KfYqjog2KrYrdiv2YrYqyDZhdmE2YEgUkVBRE1FINio2KXYudiv2KfYryDZhdiq2LrZitix2KfYqiDYp9mE2KjZitim2Kk='))
    $readmePath = Join-Path $encodingTestRoot 'README.md'
    $configTestPath = Join-Path $encodingTestRoot 'release-manager.config.json'
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($readmePath, "# Test`n![Version](https://img.shields.io/badge/version-1.2.3-blue.svg)`n$arabicText`n", $utf8NoBom)
    [System.IO.File]::WriteAllText($configTestPath, @'
{
  "blockedCommitPatterns": [],
  "warningCommitPatterns": [],
  "safeCommitPatterns": [],
  "versionTextFiles": [
    {
      "path": "README.md",
      "patterns": [
        {
          "name": "README version badge",
          "regex": "version-\\d+\\.\\d+\\.\\d+-blue\\.svg",
          "replacement": "version-{version}-blue.svg"
        }
      ]
    }
  ]
}
'@, $utf8NoBom)

    $RepoRoot = $encodingTestRoot
    $ReleaseManagerConfigPath = $configTestPath
    $updatedTextFiles = @(Update-ConfiguredTextVersionFiles '9.9.9')
    $updatedReadme = [System.IO.File]::ReadAllText($readmePath, [System.Text.UTF8Encoding]::new($false, $true))
    Assert-ArrayEquals $updatedTextFiles @('README.md') 'configured text version update should report README.md'
    Assert-Matches $updatedReadme 'version-9\.9\.9-blue\.svg' 'configured text version update should change the badge version'
    Assert-Matches $updatedReadme $arabicText 'configured text version update must preserve Arabic text'
    $mojibakeMarkerPattern = "[$([char]0x00D8)$([char]0x00D9)]"
    Assert-True ($updatedReadme -notmatch $mojibakeMarkerPattern) 'configured text version update must not mojibake Arabic text'
} finally {
    $RepoRoot = $originalRepoRoot
    $ReleaseManagerConfigPath = $originalConfigPath
    if (Test-Path $encodingTestRoot) {
        Remove-Item -LiteralPath $encodingTestRoot -Recurse -Force
    }
}

Assert-Equals (Get-InteractiveActionName '1') 'status' 'interactive choice 1 should resolve to status'
Assert-Equals (Get-InteractiveActionName ' 7 ') 'commit' 'interactive choice 7 should resolve to commit'
Assert-ThrowsMatching { Get-InteractiveActionName '99' } 'Invalid menu choice: 99' 'invalid interactive choice should explain the problem'

Assert-Equals (Get-CommitFileClassification '.env') 'blocked' '.env must be blocked'
Assert-Equals (Get-CommitFileClassification 'dist-electron/latest.yml') 'blocked' 'dist-electron files must be blocked'
Assert-Equals (Get-CommitFileClassification 'tmp/debug.log') 'blocked' 'root tmp files must be blocked'
Assert-Equals (Get-CommitFileClassification 'scratch/test.txt') 'blocked' 'scratch files must be blocked'
Assert-Equals (Get-CommitFileClassification 'backend/.phpunit.result.cache') 'blocked' 'phpunit cache must be blocked'
Assert-Equals (Get-CommitFileClassification 'backend/storage/app.db') 'blocked' 'backend storage files must be blocked'
Assert-Equals (Get-CommitFileClassification 'frontend/src/main.tsx') 'safe' 'normal frontend source should be safe'
Assert-Equals (Get-CommitFileClassification 'frontend/vite.config.ts') 'safe' 'frontend config should be safe'
Assert-Equals (Get-CommitFileClassification 'electron/main.js') 'safe' 'normal electron source should be safe'
Assert-Equals (Get-CommitFileClassification 'backend/services/UpdateService.php') 'safe' 'normal backend service source should be safe'
Assert-Equals (Get-CommitFileClassification 'backend/config/config.php') 'safe' 'backend config source should be safe'
Assert-Equals (Get-CommitFileClassification 'backend/middleware/CsrfMiddleware.php') 'safe' 'backend middleware source should be safe'
Assert-Equals (Get-CommitFileClassification 'backend/cli/websocket-server.php') 'safe' 'backend CLI source should be safe'
Assert-Equals (Get-CommitFileClassification 'backend/WebSocket/Server.php') 'safe' 'backend WebSocket source should be safe'
Assert-Equals (Get-CommitFileClassification 'build-phar.php') 'safe' 'build-phar.php should be safe'
Assert-Equals (Get-CommitFileClassification 'README.md') 'safe' 'README.md should be safe'
Assert-Equals (Get-CommitFileClassification 'package.json') 'warning' 'package.json should be warning/review first'
Assert-Equals (Get-CommitFileClassification 'backend/backend.phar') 'warning' 'backend PHAR should be warning/review first'

$safeFiles = @('package.json', 'version.json', 'scripts/release.ps1', 'docs/release.md')
Assert-ArrayEquals (Resolve-CommitFileSelection '1,3,4' $safeFiles) @('package.json', 'scripts/release.ps1', 'docs/release.md') 'numbered commit selection should resolve selected files'
Assert-ArrayEquals (Resolve-CommitFileSelection 'all' $safeFiles) $safeFiles 'all commit selection should include every safe file'
Assert-ThrowsMatching { Resolve-CommitFileSelection '1,5' $safeFiles } 'Invalid file number: 5' 'invalid commit selection number should be rejected'

$filtered = Get-SafeChangedFiles @(
    ' M backend/.phpunit.result.cache',
    ' M dist-electron/latest.yml',
    ' M node_modules/example/index.js',
    ' M scripts/release.ps1',
    '?? docs/release.md'
)
Assert-ArrayEquals $filtered.Exclude @('backend/.phpunit.result.cache', 'dist-electron/latest.yml', 'node_modules/example/index.js') 'default excluded commit files should be filtered out'
Assert-ArrayEquals $filtered.Include @('scripts/release.ps1', 'docs/release.md') 'normal source files should remain selectable'

$candidates = Get-CommitCandidateFiles -CandidateFiles @('frontend/src/main.tsx', '.env', 'dist-electron/latest.yml', 'package.json')
Assert-ArrayEquals $candidates.Exclude @('.env', 'dist-electron/latest.yml') 'blocked files must not become commit candidates'
Assert-ArrayEquals $candidates.Include @('frontend/src/main.tsx', 'package.json') 'safe and warning files may become commit candidates'
Assert-ArrayEquals $candidates.Warning @('package.json') 'warning files should be reported for review'

$noneChanged = Get-SafeChangedFiles @()
Assert-True ($noneChanged.Include.Count -eq 0) 'no changed files should produce no safe commit candidates'

Assert-ThrowsMatching { Resolve-CommitFileSelection '' $safeFiles } 'File selection is required' 'empty commit file selection should be rejected'

$invalidVersion = Invoke-Release @('-Version', '1.1', '-Mode', 'status', '-NonInteractive')
Assert-True ($invalidVersion.ExitCode -ne 0) 'invalid version should be rejected'
Assert-Matches $invalidVersion.Output 'does not[\s\S]*match' 'invalid version should fail validation'

$status = Invoke-Release @('-Mode', 'status', '-NonInteractive')
Assert-True ($status.ExitCode -eq 0) 'status mode should succeed'
Assert-Matches $status.Output 'package.json' 'status should print package.json version'
Assert-Matches $status.Output 'GH_TOKEN' 'status should print GH_TOKEN presence'

$buildDryRun = Invoke-Release @('-Mode', 'build', '-DryRun', '-NonInteractive')
Assert-True ($buildDryRun.ExitCode -eq 0) 'build dry-run should parse and succeed without executing build commands'
Assert-Matches $buildDryRun.Output 'DRY RUN' 'build dry-run should describe planned work'

$syncVersionDryRun = Invoke-Release @('-Mode', 'sync-version', '-Version', '9.9.9', '-DryRun', '-NonInteractive')
Assert-True ($syncVersionDryRun.ExitCode -eq 0) 'sync-version dry-run should succeed'
Assert-Matches $syncVersionDryRun.Output 'README.md' 'sync-version should include configured text version files such as README.md'

$cleanKeepPharDryRun = Invoke-Release @('-Mode', 'clean', '-KeepPhar', '-DryRun', '-NonInteractive')
Assert-True ($cleanKeepPharDryRun.ExitCode -eq 0) 'clean dry-run with KeepPhar should parse and succeed'
Assert-True ($cleanKeepPharDryRun.Output -notmatch 'backend/backend\.phar') 'GUI clean path must not remove backend/backend.phar'

$rollbackList = Invoke-Release @('-Mode', 'rollback-list', '-Limit', '3', '-NonInteractive')
Assert-True ($rollbackList.ExitCode -eq 0) 'rollback-list should parse and print recent commits'
Assert-Matches $rollbackList.Output 'Recent commits' 'rollback-list should describe recent commits'

$rollbackMissingCommit = Invoke-Release @('-Mode', 'rollback-revert', '-DryRun', '-NonInteractive')
Assert-True ($rollbackMissingCommit.ExitCode -ne 0) 'rollback-revert should require a commit'
Assert-Matches $rollbackMissingCommit.Output 'Commit is required' 'rollback-revert missing commit should explain the problem'

$rollbackAbortDryRun = Invoke-Release @('-Mode', 'rollback-abort', '-DryRun', '-NonInteractive')
Assert-True ($rollbackAbortDryRun.ExitCode -eq 0) 'rollback-abort dry-run should parse'
Assert-Matches $rollbackAbortDryRun.Output 'git revert --abort' 'rollback-abort dry-run should use git revert --abort'

$rollbackContinueDryRun = Invoke-Release @('-Mode', 'rollback-continue', '-DryRun', '-NonInteractive')
Assert-True ($rollbackContinueDryRun.ExitCode -eq 0) 'rollback-continue dry-run should parse'
Assert-Matches $rollbackContinueDryRun.Output 'git revert --continue' 'rollback-continue dry-run should use git revert --continue'

$packageBefore = Get-Content (Join-Path $repoRoot 'package.json') -Raw
$versionBefore = Get-Content (Join-Path $repoRoot 'version.json') -Raw
$dryRunAll = Invoke-Release @('-Version', '9.9.9', '-Mode', 'all', '-DryRun', '-Yes', '-NonInteractive')
Assert-True ($dryRunAll.ExitCode -eq 0) 'dry-run all should succeed without executing release commands'
Assert-Matches $dryRunAll.Output 'DRY RUN' 'dry-run all should describe planned work'
Assert-True ((Get-Content (Join-Path $repoRoot 'package.json') -Raw) -eq $packageBefore) 'dry-run all must not change package.json'
Assert-True ((Get-Content (Join-Path $repoRoot 'version.json') -Raw) -eq $versionBefore) 'dry-run all must not change version.json'

$electronWithoutToken = Invoke-Release @('-Version', '9.9.9', '-Mode', 'electron', '-NonInteractive')
Assert-True ($electronWithoutToken.ExitCode -ne 0) 'electron mode without GH_TOKEN should fail'
Assert-Matches $electronWithoutToken.Output 'GH_TOKEN' 'electron mode without token should fail before changing files'
Assert-True ((Get-Content (Join-Path $repoRoot 'package.json') -Raw) -eq $packageBefore) 'electron without token must not change package.json'
Assert-True ((Get-Content (Join-Path $repoRoot 'version.json') -Raw) -eq $versionBefore) 'electron without token must not change version.json'

$existingTag = (& git -C $repoRoot tag | Select-Object -First 1)
if ($existingTag) {
    $duplicateVersion = $existingTag.TrimStart('v')
    $duplicateTag = Invoke-Release @('-Version', $duplicateVersion, '-Mode', 'tag', '-NonInteractive')
    Assert-True ($duplicateTag.ExitCode -ne 0) 'duplicate tag should be rejected'
    Assert-Matches $duplicateTag.Output 'already exists' 'duplicate tag output should explain existing tag'
}

$commitDryRun = Invoke-Release @('-Mode', 'commit', '-CommitMessage', 'Release test', '-Files', 'backend/.phpunit.result.cache,scripts/release.ps1', '-DryRun', '-NonInteractive')
Assert-True ($commitDryRun.ExitCode -eq 0) 'commit dry-run should succeed'
Assert-Matches $commitDryRun.Output 'Blocked from commit.*backend/.phpunit.result.cache' 'commit should block phpunit cache by default'

$blockedCommitDryRun = Invoke-Release @('-Mode', 'commit', '-CommitMessage', 'Release test', '-Files', '.env,frontend/src/main.tsx', '-DryRun', '-NonInteractive')
Assert-True ($blockedCommitDryRun.ExitCode -eq 0) 'commit dry-run with blocked and safe file should still run for safe file'
Assert-Matches $blockedCommitDryRun.Output 'Blocked from commit.*\.env' 'commit should report blocked explicit files'
Assert-Matches $blockedCommitDryRun.Output 'Files: frontend/src/main.tsx' 'blocked explicit files must not be passed to git add'

$emptyCommitMessage = Invoke-Release @('-Mode', 'commit', '-Files', 'scripts/release.ps1', '-DryRun', '-NonInteractive')
Assert-True ($emptyCommitMessage.ExitCode -ne 0) 'empty commit message should be rejected'
Assert-Matches $emptyCommitMessage.Output 'CommitMessage is required|Commit message cannot be empty' 'empty commit message output should explain the problem'

$missingLatest = Invoke-Release @('-Version', '9.9.9', '-Mode', 'verify-artifacts', '-NonInteractive')
Assert-True ($missingLatest.ExitCode -ne 0) 'artifact verification should fail for missing or mismatched latest.yml'
Assert-Matches $missingLatest.Output 'latest.yml|version does not match' 'artifact verification should report latest.yml problems'

Write-Host 'release.ps1 behavioral checks passed'
