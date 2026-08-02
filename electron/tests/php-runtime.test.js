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
