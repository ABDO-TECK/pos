const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  getRuntimeSpawnOptions,
} = require('../utils/runtime-process');

test('runtime spawn options pin cwd and prepend the executable directory to PATH', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-runtime-process-'));
  const cwd = path.join(root, 'working');
  const executable = path.join(root, 'runtime', process.platform === 'win32' ? 'php.exe' : 'php');
  fs.mkdirSync(cwd, { recursive: true });
  fs.mkdirSync(path.dirname(executable), { recursive: true });
  fs.writeFileSync(executable, 'test');

  try {
    const options = getRuntimeSpawnOptions(executable, {
      cwd,
      env: { POS_RUNTIME_TEST: '1' },
      shell: true,
    });

    assert.equal(options.cwd, cwd);
    assert.equal(options.shell, false);
    assert.equal(options.env.POS_RUNTIME_TEST, '1');
    assert.equal(options.env.PATH.split(path.delimiter)[0], path.dirname(executable));
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('runtime spawn options reject missing executables and working directories with diagnostics', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-runtime-process-'));
  const executable = path.join(root, 'missing.exe');
  try {
    assert.throws(
      () => getRuntimeSpawnOptions(executable, { cwd: root }),
      (error) => error.code === 'RUNTIME_EXECUTABLE_MISSING' && error.details.executable === executable,
    );
    assert.throws(
      () => getRuntimeSpawnOptions(executable, { cwd: path.join(root, 'missing-cwd') }),
      (error) => error.code === 'RUNTIME_WORKING_DIRECTORY_MISSING' && error.details.cwd.endsWith('missing-cwd'),
    );
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
