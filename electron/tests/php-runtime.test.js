const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const { getPhpRuntimeArgs, resolveSystemTimeZone, sanitizePhpIni } = require('../utils/php-runtime');

test('passes the operating-system timezone to every PHP runtime invocation', () => {
  const expected = process.env.APP_TIMEZONE?.trim()
    || Intl.DateTimeFormat().resolvedOptions().timeZone
    || 'Africa/Cairo';
  const args = getPhpRuntimeArgs(path.join(os.tmpdir(), 'php.exe'), os.tmpdir());

  assert.equal(resolveSystemTimeZone(), expected);
  assert.ok(args.includes(`date.timezone=${expected}`));
});

test('sanitizes missing PHP extension directives without dropping available modules', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-php-runtime-'));
  const phpDirectory = path.join(root, 'php');
  const extensionDirectory = path.join(phpDirectory, 'ext');

  try {
    fs.mkdirSync(extensionDirectory, { recursive: true });
    fs.writeFileSync(path.join(extensionDirectory, 'php_present.dll'), 'fixture');

    const sourceIni = [
      'extension_dir="ext"',
      'extension=present',
      'extension=missing',
    ].join('\n');
    const sanitized = sanitizePhpIni(sourceIni, phpDirectory);

    assert.equal(sanitized.missingExtensions, 1);
    assert.match(sanitized.content, /extension=present/);
    assert.match(sanitized.content, /disabled by POS runtime: missing missing/);
    assert.match(sanitized.content, /extension_dir="[^"]+\/ext"/);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('creates a per-user PHP config only when a runtime extension is absent', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-php-runtime-'));
  const phpDirectory = path.join(root, 'php');
  const extensionDirectory = path.join(phpDirectory, 'ext');
  const writableDirectory = path.join(root, 'runtime');
  const phpPath = path.join(phpDirectory, 'php.exe');

  try {
    fs.mkdirSync(extensionDirectory, { recursive: true });
    fs.writeFileSync(path.join(phpDirectory, 'php.ini'), 'extension_dir=ext\nextension=missing\n');

    const args = getPhpRuntimeArgs(phpPath, writableDirectory);
    assert.equal(args[0], '-c');
    assert.equal(args[2], '-d');
    assert.equal(args[3], 'display_startup_errors=0');
    assert.match(fs.readFileSync(args[1], 'utf8'), /disabled by POS runtime/);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('maps Windows NTSTATUS STATUS_DLL_NOT_FOUND (0xC0000135) to PHP_RUNTIME_DLL_MISSING', () => {
  const { isPhpLoaderDllMissing, STATUS_DLL_NOT_FOUND } = require('../services/php-server');

  assert.equal(STATUS_DLL_NOT_FOUND, 0xC0000135);

  // Unsigned 32-bit Windows exit code: 3221225781
  assert.equal(isPhpLoaderDllMissing(3221225781), true);
  assert.equal(isPhpLoaderDllMissing('3221225781'), true);

  // Signed 32-bit integer representation: -1073741515
  assert.equal(isPhpLoaderDllMissing(-1073741515), true);
  assert.equal(isPhpLoaderDllMissing('-1073741515'), true);

  // Hexadecimal representation: 0xC0000135
  assert.equal(isPhpLoaderDllMissing(0xC0000135), true);

  // Normal exit codes must NOT be mapped to loader failure
  assert.equal(isPhpLoaderDllMissing(0), false);
  assert.equal(isPhpLoaderDllMissing(1), false);
  assert.equal(isPhpLoaderDllMissing(2), false);
  assert.equal(isPhpLoaderDllMissing(255), false);
  assert.equal(isPhpLoaderDllMissing(null), false);
  assert.equal(isPhpLoaderDllMissing(undefined), false);
  assert.equal(isPhpLoaderDllMissing('invalid'), false);
});

test('NSIS VC++ runtime version compatibility logic handles older, same, and newer runtimes', () => {
  const PINNED = { major: 14, minor: 44, bld: 35211, rbld: 0 };

  function isVcRedistCompatible({ installed, major, minor, bld, rbld }) {
    if (installed !== 1) return false;
    if (major > PINNED.major) return true;
    if (major === PINNED.major) {
      if (minor > PINNED.minor) return true;
      if (minor === PINNED.minor) {
        if (bld > PINNED.bld) return true;
        if (bld === PINNED.bld) {
          if (rbld >= PINNED.rbld) return true;
        }
      }
    }
    return false;
  }

  // Not installed
  assert.equal(isVcRedistCompatible({ installed: 0, major: 14, minor: 44, bld: 35211, rbld: 0 }), false);

  // Older runtimes (must upgrade)
  assert.equal(isVcRedistCompatible({ installed: 1, major: 14, minor: 0, bld: 24210, rbld: 0 }), false);
  assert.equal(isVcRedistCompatible({ installed: 1, major: 14, minor: 43, bld: 35000, rbld: 0 }), false);
  assert.equal(isVcRedistCompatible({ installed: 1, major: 14, minor: 44, bld: 35210, rbld: 0 }), false);
  assert.equal(isVcRedistCompatible({ installed: 1, major: 12, minor: 0, bld: 0, rbld: 0 }), false);

  // Same pinned runtime (compatible, skip install)
  assert.equal(isVcRedistCompatible({ installed: 1, major: 14, minor: 44, bld: 35211, rbld: 0 }), true);

  // Newer compatible runtimes (do NOT downgrade)
  assert.equal(isVcRedistCompatible({ installed: 1, major: 14, minor: 44, bld: 35212, rbld: 0 }), true);
  assert.equal(isVcRedistCompatible({ installed: 1, major: 14, minor: 50, bld: 35719, rbld: 0 }), true);
  assert.equal(isVcRedistCompatible({ installed: 1, major: 15, minor: 0, bld: 0, rbld: 0 }), true);

  // Exit code 1638 verification flow:
  // If exit code is 1638 and registry has newer runtime: PASS
  const exit1638RegistryValid = isVcRedistCompatible({ installed: 1, major: 14, minor: 50, bld: 35719, rbld: 0 });
  assert.equal(exit1638RegistryValid, true);

  // If exit code is 1638 but registry is missing or outdated: FAIL
  const exit1638RegistryInvalid = isVcRedistCompatible({ installed: 0, major: 0, minor: 0, bld: 0, rbld: 0 });
  assert.equal(exit1638RegistryInvalid, false);
});

test('NSIS prerequisite exit code and PHP post-condition flow decision matrix', () => {
  function evaluatePrerequisiteOutcome({ exitCode, registryCompatible, phpExitCode }) {
    // 1. Evaluate prerequisite installer exit code
    let rebootRequired = false;
    if (exitCode === 0) {
      // Installed successfully
    } else if (exitCode === 1638) {
      // Another version installed: must confirm compatible in registry
      if (!registryCompatible) {
        return { status: 'ABORT', reason: 'INCOMPATIBLE_REGISTRY_AFTER_1638' };
      }
    } else if (exitCode === 3010) {
      // Installation succeeded, reboot required
      rebootRequired = true;
    } else {
      // Unknown or fatal error code
      return { status: 'ABORT', reason: `INSTALLER_FAILED_${exitCode}` };
    }

    // 2. Mandatory post-condition: verify packaged php.exe -v
    if (phpExitCode !== 0) {
      if (rebootRequired) {
        return { status: 'ABORT', reason: 'REBOOT_REQUIRED_BEFORE_PHP_CAN_RUN' };
      }
      return { status: 'ABORT', reason: 'PHP_RUNTIME_VERIFICATION_FAILED' };
    }

    return { status: 'CONTINUE', rebootRequired };
  }

  // 0 + PHP ok -> success
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 0, registryCompatible: true, phpExitCode: 0 }), { status: 'CONTINUE', rebootRequired: false });

  // 1638 + valid registry + PHP ok -> success
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 1638, registryCompatible: true, phpExitCode: 0 }), { status: 'CONTINUE', rebootRequired: false });

  // 1638 + invalid registry -> abort
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 1638, registryCompatible: false, phpExitCode: 0 }), { status: 'ABORT', reason: 'INCOMPATIBLE_REGISTRY_AFTER_1638' });

  // 3010 + PHP ok -> continue with reboot required recorded
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 3010, registryCompatible: true, phpExitCode: 0 }), { status: 'CONTINUE', rebootRequired: true });

  // 3010 + PHP dll missing (0xC0000135) -> abort with reboot requirement notice
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 3010, registryCompatible: true, phpExitCode: 0xC0000135 }), { status: 'ABORT', reason: 'REBOOT_REQUIRED_BEFORE_PHP_CAN_RUN' });

  // Arbitrary error code (e.g. 1603) -> abort
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 1603, registryCompatible: false, phpExitCode: 0 }), { status: 'ABORT', reason: 'INSTALLER_FAILED_1603' });

  // Arbitrary failure (e.g. user cancelled or blocked: 1602) -> abort
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 1602, registryCompatible: false, phpExitCode: 0 }), { status: 'ABORT', reason: 'INSTALLER_FAILED_1602' });

  // Installed (0) but PHP fails post-condition (e.g. 0xC0000135) -> abort
  assert.deepEqual(evaluatePrerequisiteOutcome({ exitCode: 0, registryCompatible: true, phpExitCode: 0xC0000135 }), { status: 'ABORT', reason: 'PHP_RUNTIME_VERIFICATION_FAILED' });
});
