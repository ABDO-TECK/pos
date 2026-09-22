import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createHash, generateKeyPairSync } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const validator = path.join(repoRoot, 'scripts', 'validate-release-source.mjs');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function runValidator(args) {
  return spawnSync(process.execPath, [validator, ...args], {
    cwd: repoRoot,
    encoding: 'utf8',
  });
}

function jobBlock(workflow, jobName, nextJobName) {
  const nextBoundary = nextJobName
    ? `(?=\\r?\\n  ${nextJobName}:)`
    : '(?=\\r?\\n[^ \\r\\n]|$)';
  return workflow.match(new RegExp(`\\r?\\n  ${jobName}:[\\s\\S]*?${nextBoundary}`, 'u'))?.[0] || '';
}

const phpBinary = process.env.POS_PHP || (process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : 'php');
const builder = path.join(repoRoot, 'scripts', 'build-release-package.php');
const packageVerifier = path.join(repoRoot, 'scripts', 'verify-release-package.php');
const sourceFixture = path.join(repoRoot, 'scratch', 'final-release-integration-20260922', 'source-v0.0.4');
const currentVersion = JSON.parse(read('version.json')).version;
const currentTag = `v${currentVersion}`;

function runPhp(script, args, cwd = repoRoot, env = process.env) {
  const resolvedCwd = path.resolve(cwd);
  const childEnv = resolvedCwd === path.resolve(sourceFixture)
    ? {
        ...env,
        // The checked-in scratch fixture may be owned by the Codex sandbox
        // identity. Trust only that exact disposable path for this child
        // process; never change the user's global Git configuration.
        GIT_CONFIG_COUNT: '1',
        GIT_CONFIG_KEY_0: 'safe.directory',
        GIT_CONFIG_VALUE_0: resolvedCwd.replaceAll('\\', '/'),
      }
    : env;

  return spawnSync(phpBinary, [script, ...args], {
    cwd: resolvedCwd,
    encoding: 'utf8',
    env: childEnv,
  });
}

function sha256File(filePath) {
  return createHash('sha256').update(fs.readFileSync(filePath)).digest('hex').toUpperCase();
}

function makeEphemeralBuilderFixture() {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-ephemeral-'));
  execFileSync('git', ['clone', '--quiet', '--no-local', repoRoot, fixture], { stdio: 'pipe' });
  execFileSync('git', ['checkout', '--quiet', '--detach', 'fcf180073a76f52743599d167bb64d953fc1e02e'], {
    cwd: fixture,
    stdio: 'pipe',
  });
  fs.copyFileSync(builder, path.join(fixture, 'scripts', 'build-release-package.php'));
  fs.copyFileSync(packageVerifier, path.join(fixture, 'scripts', 'verify-release-package.php'));

  const { privateKey, publicKey } = generateKeyPairSync('rsa', {
    modulusLength: 2048,
    privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
    publicKeyEncoding: { type: 'spki', format: 'pem' },
  });
  const privateKeyPath = path.join(fixture, 'ephemeral-test-key.pem');
  fs.writeFileSync(privateKeyPath, privateKey, { encoding: 'utf8', mode: 0o600 });
  fs.writeFileSync(path.join(fixture, 'backend', 'certs', 'update_public_key.pem'), publicKey, 'utf8');

  fs.mkdirSync(path.join(fixture, 'backend', 'storage'), { recursive: true });
  fs.symlinkSync(path.join(repoRoot, 'backend', 'vendor'), path.join(fixture, 'backend', 'vendor'), 'junction');
  execFileSync('git', ['config', 'user.email', 'release-test@example.invalid'], { cwd: fixture, stdio: 'pipe' });
  execFileSync('git', ['config', 'user.name', 'Release Test'], { cwd: fixture, stdio: 'pipe' });

  return { fixture, privateKeyPath, publicKey };
}

function resetEphemeralBuilderFixture(fixture, publicKey) {
  execFileSync('git', ['reset', '--hard', '--quiet', 'fcf180073a76f52743599d167bb64d953fc1e02e'], {
    cwd: fixture,
    stdio: 'pipe',
  });
  fs.copyFileSync(builder, path.join(fixture, 'scripts', 'build-release-package.php'));
  fs.copyFileSync(packageVerifier, path.join(fixture, 'scripts', 'verify-release-package.php'));
  fs.writeFileSync(path.join(fixture, 'backend', 'certs', 'update_public_key.pem'), publicKey, 'utf8');
}

function commitEphemeralFixtureChange(fixture, relativePath, contents) {
  const target = path.join(fixture, relativePath);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, contents, 'utf8');
  execFileSync('git', ['add', '--', relativePath], { cwd: fixture, stdio: 'pipe' });
  execFileSync('git', ['commit', '--quiet', '-m', `test: change ${relativePath}`], { cwd: fixture, stdio: 'pipe' });
}

function makeGitFixture({ targetPackageVersion = '0.0.1', targetFrontendVersion = '0.0.1' } = {}) {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-source-'));
  fs.mkdirSync(path.join(fixture, 'frontend'), { recursive: true });

  const writeJson = (relativePath, value) => {
    const target = path.join(fixture, relativePath);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, `${JSON.stringify(value, null, 2)}\n`);
  };

  writeJson('version.json', {
    version: '0.0.1',
    application_version: '0.0.1',
    update_engine_version: '1.0.0',
    minimum_supported_version: '0.0.1',
    release_series: 'v0',
  });
  writeJson('package.json', { name: 'pos-desktop', version: '0.0.1' });
  writeJson('frontend/package.json', { name: 'frontend', version: '0.0.1' });

  const git = (...args) => execFileSync('git', args, { cwd: fixture, stdio: 'pipe' }).toString().trim();
  git('init', '--quiet');
  git('config', 'user.email', 'release-test@example.invalid');
  git('config', 'user.name', 'Release Test');
  git('add', '.');
  git('commit', '--quiet', '-m', 'baseline');
  const baselineRef = git('rev-parse', 'HEAD');
  git('tag', 'v0.0.1', baselineRef);

  writeJson('version.json', {
    version: '0.0.4',
    application_version: '0.0.4',
    update_engine_version: '1.0.0',
    minimum_supported_version: '0.0.1',
    release_series: 'v0',
  });
  writeJson('package.json', { name: 'pos-desktop', version: targetPackageVersion });
  writeJson('frontend/package.json', { name: 'frontend', version: targetFrontendVersion });
  git('add', '.');
  git('commit', '--quiet', '-m', 'target');
  git('tag', 'v0.0.4');

  return { fixture, baselineRef };
}

function makeBuilderFixture() {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-'));
  fs.mkdirSync(path.join(fixture, 'backend', 'vendor'), { recursive: true });
  fs.mkdirSync(path.join(fixture, 'scripts'), { recursive: true });
  fs.writeFileSync(path.join(fixture, 'backend', 'vendor', 'autoload.php'), "<?php\n");
  fs.copyFileSync(builder, path.join(fixture, 'scripts', 'build-release-package.php'));
  fs.writeFileSync(path.join(fixture, 'version.json'), `${JSON.stringify({
    version: '0.0.4',
    application_version: '0.0.4',
    update_engine_version: '1.0.0',
    minimum_supported_version: '0.0.1',
  }, null, 2)}\n`);
  return fixture;
}

test('working-tree validator accepts a complete full-release source fixture', () => {
  const { fixture } = makeGitFixture({ targetPackageVersion: '0.0.4', targetFrontendVersion: '0.0.4' });
  try {
    const result = runValidator(['--mode', 'working-tree', '--root', fixture]);
    assert.equal(result.status, 0, result.stderr || result.stdout);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('working-tree validator accepts the reconciled source version contract', () => {
  const versionData = JSON.parse(read('version.json'));
  const rootPackage = JSON.parse(read('package.json'));
  const result = runValidator(['--mode', 'working-tree', '--root', repoRoot]);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.match(
    result.stdout,
    new RegExp(`update=${versionData.version}, desktop-runtime=${rootPackage.version}`),
  );
});

test('valid v0.0.4 Delta source uses an immutable baseline commit', () => {
  const { fixture, baselineRef } = makeGitFixture();
  try {
    const result = runValidator([
      '--mode', 'delta',
      '--root', fixture,
      '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef,
      '--baseline-version', '0.0.1',
      '--delta-scope', 'backend',
      '--release-channel', 'prerelease',
    ]);
    assert.equal(result.status, 0, result.stderr || result.stdout);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('backend-only, frontend-only, and mixed Deltas keep runtime package versions at the baseline', () => {
  for (const scope of ['backend', 'frontend', 'mixed']) {
    const { fixture, baselineRef } = makeGitFixture();
    try {
      const result = runValidator([
        '--mode', 'delta',
        '--root', fixture,
        '--tag', 'v0.0.4',
        '--baseline-ref', baselineRef,
        '--baseline-version', '0.0.1',
        '--delta-scope', scope,
        '--release-channel', 'prerelease',
      ]);
      assert.equal(result.status, 0, `${scope}: ${result.stderr || result.stdout}`);
    } finally {
      fs.rmSync(fixture, { recursive: true, force: true });
    }
  }
});

test('full releases require target package versions, while Deltas reject target package versions', () => {
  const fullFixture = makeGitFixture({ targetPackageVersion: '0.0.4', targetFrontendVersion: '0.0.4' });
  const deltaFixture = makeGitFixture({ targetPackageVersion: '0.0.4', targetFrontendVersion: '0.0.4' });
  try {
    const full = runValidator([
      '--mode', 'full', '--root', fullFixture.fixture, '--tag', 'v0.0.4',
      '--release-channel', 'prerelease',
    ]);
    assert.equal(full.status, 0, full.stderr || full.stdout);

    const delta = runValidator([
      '--mode', 'delta', '--root', deltaFixture.fixture, '--tag', 'v0.0.4',
      '--baseline-ref', deltaFixture.baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend', '--release-channel', 'prerelease',
    ]);
    assert.notEqual(delta.status, 0);
    assert.match(delta.stderr, /baseline|package version/i);
  } finally {
    fs.rmSync(fullFixture.fixture, { recursive: true, force: true });
    fs.rmSync(deltaFixture.fixture, { recursive: true, force: true });
  }
});

test('full-installer validation rejects inconsistent target version metadata', () => {
  const { fixture } = makeGitFixture({ targetPackageVersion: '0.0.4', targetFrontendVersion: '0.0.1' });
  try {
    const result = runValidator([
      '--mode', 'full', '--root', fixture, '--tag', 'v0.0.4',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /versions differ|package version/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('Delta validation rejects an unknown scope and a mutable baseline ref', () => {
  const { fixture, baselineRef } = makeGitFixture();
  try {
    const unknownScope = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'desktop', '--release-channel', 'prerelease',
    ]);
    assert.notEqual(unknownScope.status, 0);
    assert.match(unknownScope.stderr, /scope/i);

    const mutableRef = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', 'v0.0.1', '--baseline-version', '0.0.1',
      '--delta-scope', 'backend', '--release-channel', 'prerelease',
    ]);
    assert.notEqual(mutableRef.status, 0);
    assert.match(mutableRef.stderr, /immutable|commit|baseline/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('Delta validation rejects an omitted scope instead of defaulting silently', () => {
  const { fixture, baselineRef } = makeGitFixture();
  try {
    const omittedScope = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(omittedScope.status, 0);
    assert.match(omittedScope.stderr, /delta-scope.*required|scope/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('manifest validation accepts a path relative to the working directory', () => {
  const { fixture, baselineRef } = makeGitFixture();
  const manifestPath = path.join(fixture, 'manifest.json');
  fs.writeFileSync(manifestPath, `${JSON.stringify({
    version: '0.0.4',
    minimum_version: '0.0.1',
    type: 'delta',
    update_engine_version: '1.0.0',
  })}\n`);
  try {
    const result = runValidator([
      '--mode', 'delta',
      '--root', fixture,
      '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef,
      '--baseline-version', '0.0.1',
      '--delta-scope', 'backend',
      '--release-channel', 'prerelease',
      '--manifest', path.relative(repoRoot, manifestPath),
    ]);
    assert.equal(result.status, 0, result.stderr || result.stdout);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('Delta validation rejects missing or unverifiable baselines', () => {
  const { fixture, baselineRef } = makeGitFixture();
  try {
    const missing = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--delta-scope', 'backend',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /baseline-ref|baseline-version/i);

    const wrongVersion = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.2',
      '--delta-scope', 'backend',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(wrongVersion.status, 0);
    assert.match(wrongVersion.stderr, /baseline/i);

    const missingRef = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', 'does-not-exist', '--baseline-version', '0.0.1',
      '--delta-scope', 'backend',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(missingRef.status, 0);
    assert.match(missingRef.stderr, /baseline ref|baseline-ref|resolve|immutable/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('release builder refuses missing signing credentials before packaging', () => {
  const fixture = makeBuilderFixture();
  const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-output-'));
  try {
    const result = runPhp(
      path.join(fixture, 'scripts', 'build-release-package.php'),
      [
        '--tag=v0.0.4',
        '--from-ref=45dea24b2905f04cdc920c536e2bb920649a2208',
        '--from-version=0.0.1',
        '--delta-scope=backend',
        '--release-channel=prerelease',
        `--output-dir=${outputDir}`,
      ],
      fixture,
      { ...process.env, UPDATE_PRIVATE_KEY: '' },
    );
    assert.notEqual(result.status, 0);
    assert.match(`${result.stdout}\n${result.stderr}`, /UPDATE_PRIVATE_KEY|private key.*missing/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
    fs.rmSync(outputDir, { recursive: true, force: true });
  }
});

test('Delta builder requires an explicit supported scope before credentials', () => {
  const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-scope-'));
  const commonArgs = [
    `--tag=${currentTag}`,
    '--from-ref=45dea24b2905f04cdc920c536e2bb920649a2208',
    '--from-version=0.0.1',
    '--release-channel=prerelease',
    `--output-dir=${outputDir}`,
  ];
  try {
    const missingChannel = runPhp(builder, [
      `--tag=${currentTag}`,
      '--from-ref=45dea24b2905f04cdc920c536e2bb920649a2208',
      '--from-version=0.0.1',
      '--delta-scope=backend',
      `--output-dir=${outputDir}`,
    ], repoRoot, { ...process.env, UPDATE_PRIVATE_KEY: '' });
    assert.notEqual(missingChannel.status, 0);
    assert.match(`${missingChannel.stdout}\n${missingChannel.stderr}`, /release.channel.*required|explicit.*release.channel/i);

    const omitted = runPhp(builder, commonArgs, repoRoot, { ...process.env, UPDATE_PRIVATE_KEY: '' });
    assert.notEqual(omitted.status, 0);
    assert.match(`${omitted.stdout}\n${omitted.stderr}`, /delta.scope.*required|explicit.*delta.scope/i);

    const unsupported = runPhp(builder, [...commonArgs, '--delta-scope=desktop'], repoRoot, { ...process.env, UPDATE_PRIVATE_KEY: '' });
    assert.notEqual(unsupported.status, 0);
    assert.match(`${unsupported.stdout}\n${unsupported.stderr}`, /delta.scope.*invalid|supported.*backend.*frontend.*mixed/i);
  } finally {
    fs.rmSync(outputDir, { recursive: true, force: true });
  }
});

test('backend-scoped builder accepts the v0.0.4 scripts-only package metadata diff', () => {
  const { fixture, privateKeyPath } = makeEphemeralBuilderFixture();
  const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-backend-'));
  try {
    const build = runPhp(path.join(fixture, 'scripts', 'build-release-package.php'), [
      '--tag=v0.0.4',
      '--from-ref=45dea24b2905f04cdc920c536e2bb920649a2208',
      '--from-version=0.0.1',
      '--delta-scope=backend',
      '--release-channel=prerelease',
      `--private-key=${privateKeyPath}`,
      `--output-dir=${outputDir}`,
    ], fixture);
    assert.equal(build.status, 0, `${build.stdout}\n${build.stderr}`);

    const verified = runPhp(path.join(fixture, 'scripts', 'verify-release-package.php'), [
      `--release-dir=${outputDir}`,
      '--target-version=0.0.4',
      '--minimum-version=0.0.1',
    ]);
    assert.equal(verified.status, 0, `${verified.stdout}\n${verified.stderr}`);
    console.log(`STAGING TEST FIXTURE — NOT FOR PRODUCTION; delta_sha256=${sha256File(path.join(outputDir, 'delta-0.0.1-to-0.0.4.zip'))}; manifest_sha256=${sha256File(path.join(outputDir, 'manifest.json'))}`);
    console.log(verified.stdout.trim());

    const manifest = JSON.parse(fs.readFileSync(path.join(outputDir, 'manifest.json'), 'utf8'));
    assert.equal(manifest.type, 'delta');
    assert.equal(manifest.version, '0.0.4');
    assert.equal(manifest.minimum_version, '0.0.1');
    assert.equal(manifest.channel, 'beta');
    assert.deepEqual(manifest.files.map((entry) => entry.path), ['version.json']);

    const tamperedZipDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-tampered-zip-'));
    fs.cpSync(outputDir, tamperedZipDir, { recursive: true });
    fs.appendFileSync(path.join(tamperedZipDir, 'delta-0.0.1-to-0.0.4.zip'), Buffer.from('tampered'));
    const tamperedZip = runPhp(path.join(fixture, 'scripts', 'verify-release-package.php'), [
      `--release-dir=${tamperedZipDir}`,
      '--target-version=0.0.4',
      '--minimum-version=0.0.1',
    ]);
    assert.notEqual(tamperedZip.status, 0);
    assert.match(`${tamperedZip.stdout}\n${tamperedZip.stderr}`, /ZIP|hash|integrity|archive/i);
    fs.rmSync(tamperedZipDir, { recursive: true, force: true });

    const tamperedSignatureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-tampered-signature-'));
    fs.cpSync(outputDir, tamperedSignatureDir, { recursive: true });
    const signaturePath = path.join(tamperedSignatureDir, 'manifest.sig');
    const invalidSignature = fs.readFileSync(signaturePath);
    invalidSignature[0] ^= 0xff;
    fs.writeFileSync(signaturePath, invalidSignature);
    const tamperedSignature = runPhp(path.join(fixture, 'scripts', 'verify-release-package.php'), [
      `--release-dir=${tamperedSignatureDir}`,
      '--target-version=0.0.4',
      '--minimum-version=0.0.1',
    ]);
    assert.notEqual(tamperedSignature.status, 0);
    assert.match(`${tamperedSignature.stdout}\n${tamperedSignature.stderr}`, /RSA|signature/i);
    fs.rmSync(tamperedSignatureDir, { recursive: true, force: true });
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
    fs.rmSync(outputDir, { recursive: true, force: true });
  }
});

test('Delta builder rejects out-of-scope, app.asar, runtime-package, and unsupported changes', () => {
  const { fixture, privateKeyPath, publicKey } = makeEphemeralBuilderFixture();
  const cases = [
    {
      path: 'frontend/src/delta-scope-test.js',
      contents: 'export const scopeTest = true;\n',
      scope: 'backend',
      expected: /outside the backend Delta scope/i,
    },
    {
      path: 'backend/Services/DeltaScopeTest.php',
      contents: '<?php\n',
      scope: 'frontend',
      expected: /outside the frontend Delta scope/i,
    },
    {
      path: 'electron/delta-scope-test.js',
      contents: 'module.exports = {};\n',
      scope: 'mixed',
      expected: /app\.asar|bootstrap/i,
    },
    {
      path: 'database/pos_schema.sql',
      contents: '-- unsupported Delta test change\n',
      scope: 'mixed',
      expected: /not a supported deployable Delta path|app\.asar/i,
    },
  ];

  try {
    for (const scenario of cases) {
      resetEphemeralBuilderFixture(fixture, publicKey);
      commitEphemeralFixtureChange(fixture, scenario.path, scenario.contents);
      const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-rejected-'));
      try {
        const result = runPhp(path.join(fixture, 'scripts', 'build-release-package.php'), [
          '--tag=v0.0.4',
          '--from-ref=45dea24b2905f04cdc920c536e2bb920649a2208',
          '--from-version=0.0.1',
          `--delta-scope=${scenario.scope}`,
          '--release-channel=prerelease',
          `--private-key=${privateKeyPath}`,
          `--output-dir=${outputDir}`,
        ], fixture);
        assert.notEqual(result.status, 0, scenario.path);
        assert.match(`${result.stdout}\n${result.stderr}`, scenario.expected, scenario.path);
      } finally {
        fs.rmSync(outputDir, { recursive: true, force: true });
      }
    }

    resetEphemeralBuilderFixture(fixture, publicKey);
    const packageJsonPath = path.join(fixture, 'package.json');
    const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
    packageJson.description = 'runtime metadata changed';
    commitEphemeralFixtureChange(fixture, 'package.json', `${JSON.stringify(packageJson, null, 2)}\n`);
    const packageOutputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-package-rejected-'));
    try {
      const result = runPhp(path.join(fixture, 'scripts', 'build-release-package.php'), [
        '--tag=v0.0.4',
        '--from-ref=45dea24b2905f04cdc920c536e2bb920649a2208',
        '--from-version=0.0.1',
        '--delta-scope=backend',
        '--release-channel=prerelease',
        `--private-key=${privateKeyPath}`,
        `--output-dir=${packageOutputDir}`,
      ], fixture);
      assert.notEqual(result.status, 0);
      assert.match(`${result.stdout}\n${result.stderr}`, /package\.json changes runtime|bootstrap/i);
    } finally {
      fs.rmSync(packageOutputDir, { recursive: true, force: true });
    }
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('v0 stable publication is rejected and tag mismatch is rejected', () => {
  const { fixture, baselineRef } = makeGitFixture();
  try {
    const stable = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend',
      '--release-channel', 'stable',
    ]);
    assert.notEqual(stable.status, 0);
    assert.match(stable.stderr, /prerelease|stable/i);

    const mismatch = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.3',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(mismatch.status, 0);
    assert.match(mismatch.stderr, /tag|version/i);

    const bootstrap = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4-bootstrap',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(bootstrap.status, 0);
    assert.match(bootstrap.stderr, /bootstrap|full/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('Delta builder rejects an omitted baseline instead of guessing a previous tag', {
  // The builder validates signing credentials before it reaches baseline
  // resolution. Keep this assertion unavailable when the local private key
  // is intentionally absent; the missing-credentials fail-closed test above
  // remains runnable without any key material.
  skip: !fs.existsSync(path.join(repoRoot, 'backend', 'vendor', 'autoload.php'))
    || !fs.existsSync(path.join(repoRoot, 'release', 'private_key.pem')),
}, () => {
  const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-'));
  try {
    const result = runPhp(builder, [
      '--tag=v0.0.1',
      '--private-key=release/private_key.pem',
      `--output-dir=${outputDir}`,
    ]);
    assert.notEqual(result.status, 0);
    assert.match(`${result.stdout}\n${result.stderr}`, /explicit baseline|from-ref|from-version/i);
  } finally {
    fs.rmSync(outputDir, { recursive: true, force: true });
  }
});

test('explicit baseline builds and package verification reject tampering', { skip: !fs.existsSync(sourceFixture) }, () => {
  const fixtureBuilder = path.join(sourceFixture, 'scripts', 'build-release-package.php');
  const baselineCommit = execFileSync('git', ['rev-parse', 'v0.0.1^{commit}'], {
    cwd: sourceFixture,
    encoding: 'utf8',
  }).trim();
  const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-builder-valid-'));
  try {
    const build = runPhp(fixtureBuilder, [
      '--tag=v0.0.4',
      `--from-ref=${baselineCommit}`,
      '--from-version=0.0.1',
      '--delta-scope=backend',
      '--release-channel=prerelease',
      `--private-key=${path.join(repoRoot, 'release', 'private_key.pem')}`,
      `--output-dir=${outputDir}`,
    ], sourceFixture);
    assert.equal(build.status, 0, `${build.stdout}\n${build.stderr}`);

    const verified = runPhp(packageVerifier, [
      `--release-dir=${outputDir}`,
      '--target-version=0.0.4',
      '--minimum-version=0.0.1',
    ]);
    assert.equal(verified.status, 0, `${verified.stdout}\n${verified.stderr}`);

    const missingAssetDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-missing-asset-'));
    fs.cpSync(outputDir, missingAssetDir, { recursive: true });
    fs.rmSync(path.join(missingAssetDir, 'release-notes.md'));
    const missingAsset = runPhp(packageVerifier, [
      `--release-dir=${missingAssetDir}`,
      '--target-version=0.0.4',
      '--minimum-version=0.0.1',
    ]);
    assert.notEqual(missingAsset.status, 0);
    assert.match(`${missingAsset.stdout}\n${missingAsset.stderr}`, /release notes|missing/i);
    fs.rmSync(missingAssetDir, { recursive: true, force: true });

    const zipPath = path.join(outputDir, 'delta-0.0.1-to-0.0.4.zip');
    fs.appendFileSync(zipPath, Buffer.from('tampered'));
    const tampered = runPhp(packageVerifier, [
      `--release-dir=${outputDir}`,
      '--target-version=0.0.4',
      '--minimum-version=0.0.1',
    ]);
    assert.notEqual(tampered.status, 0);
    assert.match(`${tampered.stdout}\n${tampered.stderr}`, /ZIP|hash|integrity|archive/i);

    const invalidSignatureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-signature-'));
    fs.cpSync(outputDir, invalidSignatureDir, { recursive: true });
    const signaturePath = path.join(invalidSignatureDir, 'manifest.sig');
    const invalidSignature = fs.readFileSync(signaturePath);
    invalidSignature[0] ^= 0xff;
    fs.writeFileSync(signaturePath, invalidSignature);
    const invalidSignatureResult = runPhp(packageVerifier, [
      `--release-dir=${invalidSignatureDir}`,
      '--target-version=0.0.4',
      '--minimum-version=0.0.1',
    ]);
    assert.notEqual(invalidSignatureResult.status, 0);
    assert.match(`${invalidSignatureResult.stdout}\n${invalidSignatureResult.stderr}`, /RSA|signature/i);
    fs.rmSync(invalidSignatureDir, { recursive: true, force: true });

    const missingSecret = runPhp(fixtureBuilder, [
      '--tag=v0.0.4',
      `--from-ref=${baselineCommit}`,
      '--from-version=0.0.1',
      '--delta-scope=backend',
      '--release-channel=prerelease',
      '--private-key=missing.pem',
      `--output-dir=${path.join(outputDir, 'missing-secret')}`,
    ], sourceFixture, { ...process.env, UPDATE_PRIVATE_KEY: '' });
    assert.notEqual(missingSecret.status, 0);
    assert.match(`${missingSecret.stdout}\n${missingSecret.stderr}`, /private key|UPDATE_PRIVATE_KEY/i);
  } finally {
    fs.rmSync(outputDir, { recursive: true, force: true });
  }
});

test('verification workflow is read-only and has no tag publication trigger', () => {
  const workflow = read('.github/workflows/release.yml');
  assert.match(workflow, /workflow_dispatch:/);
  assert.match(workflow, /permissions:\s*\n\s+contents:\s+read/);
  assert.doesNotMatch(workflow, /push:\s*\n\s+tags:/);
  assert.doesNotMatch(workflow, /contents:\s*write/);
  assert.doesNotMatch(workflow, /softprops\/action-gh-release|gh\s+release\s+(create|upload|delete)/);
  assert.match(workflow, /baseline_ref|from_ref/);
  assert.match(workflow, /baseline_version|from_version/);
  assert.match(workflow, /delta_scope/);
  assert.match(workflow, /UPDATE_PRIVATE_KEY/);
  assert.doesNotMatch(workflow, /--private-key/);
  assert.match(
    jobBlock(workflow, 'verify-release-build'),
    /php scripts\/build-release-package\.php[\s\S]*?--release-channel\s+"\$RELEASE_CHANNEL"/u,
  );
});

test('pull-request verification runs automation tests without legacy source validation', () => {
  const workflow = read('.github/workflows/release.yml');
  const automationJob = jobBlock(workflow, 'automation-verification', 'resolve-release-source');
  assert.match(automationJob, /npm run test:release-workflow/u);
  assert.doesNotMatch(automationJob, /validate-release-source\.mjs/u);
  assert.doesNotMatch(automationJob, /working-tree source consistency/u);
});

test('required automation verification runs for every pull-request file category', () => {
  const workflow = read('.github/workflows/release.yml');
  const pullRequestEvent = workflow.match(/\n  pull_request:([\s\S]*?)(?=\n  workflow_dispatch:)/u)?.[1] || '';
  const automationJob = jobBlock(workflow, 'automation-verification', 'resolve-release-source');

  for (const category of ['documentation-only', 'workflow-only', 'application-code']) {
    assert.equal(pullRequestEvent.trim(), '', `${category} PRs must not be filtered by event configuration`);
  }
  assert.doesNotMatch(pullRequestEvent, /paths|paths-ignore|branches|branches-ignore/u);
  assert.doesNotMatch(automationJob, /\n\s+if:/u);
  assert.doesNotMatch(automationJob, /\n\s+needs:/u);
  assert.doesNotMatch(automationJob, /continue-on-error/u);
});

test('all publication workflows are explicit, protected, and non-overwriting', () => {
  const updatePublisher = read('.github/workflows/publish-release.yml');
  const desktopPublisher = read('.github/workflows/release-desktop.yml');

  assert.match(updatePublisher, /workflow_dispatch:/);
  assert.doesNotMatch(updatePublisher, /\n\s+push:/);
  assert.match(updatePublisher, /confirm_publish/);
  assert.match(updatePublisher, /confirm_publish must be true|CONFIRM_PUBLISH.*true/);
  assert.match(updatePublisher, /release_channel/);
  assert.match(updatePublisher, /from_ref/);
  assert.match(updatePublisher, /from_version/);
  assert.match(updatePublisher, /delta_scope/);
  assert.match(updatePublisher, /--release-channel\s+"\$RELEASE_CHANNEL"/u);
  assert.match(updatePublisher, /--delta-scope\s+"\$DELTA_SCOPE"/u);
  assert.match(
    jobBlock(updatePublisher, 'build', 'publish'),
    /php scripts\/build-release-package\.php[\s\S]*?--release-channel\s+"\$RELEASE_CHANNEL"/u,
  );
  assert.doesNotMatch(updatePublisher, /--private-key/);
  assert.match(updatePublisher, /environment:\s*\n?\s+name:\s*github-release-approval|environment:\s+github-release-approval/);
  assert.match(updatePublisher, /contents:\s+write/);
  assert.match(updatePublisher, /gh\s+release\s+create/);
  assert.match(updatePublisher, /404 Not Found/);
  assert.match(updatePublisher, /verify-tag/);
  assert.doesNotMatch(updatePublisher, /--clobber|softprops\/action-gh-release/);
  assert.doesNotMatch(updatePublisher, /\n\s+pull_request:/u);

  assert.match(desktopPublisher, /workflow_dispatch:/);
  assert.match(desktopPublisher, /confirm_publish/);
  assert.match(desktopPublisher, /github-release-approval/);
  assert.match(desktopPublisher, /validate-release-source\.mjs\s+--mode full/);
  assert.match(desktopPublisher, /existing desktop asset conflicts|existing.*asset|asset.*conflict/i);
  assert.doesNotMatch(desktopPublisher, /--clobber/);
  assert.doesNotMatch(desktopPublisher, /-RequireAuthenticode|require-production-signing/);
  assert.doesNotMatch(desktopPublisher, /\n\s+pull_request:/u);
});

test('no workflow can publish from an automatic tag trigger', () => {
  for (const name of fs.readdirSync(path.join(repoRoot, '.github', 'workflows'))) {
    const workflow = read(path.join('.github', 'workflows', name));
    if (/gh\s+release|softprops\/action-gh-release/.test(workflow)) {
      assert.doesNotMatch(workflow, /push:\s*\n\s+tags:/, `${name} must not publish on tag push`);
    }
  }
});

test('signing credentials are scoped to protected jobs and never enter PR verification', () => {
  const publicationWorkflow = read('.github/workflows/publish-release.yml');
  const publicationBuild = jobBlock(publicationWorkflow, 'build', 'publish');
  assert.match(publicationBuild, /environment:\s*\n\s+name:\s*github-release-approval/u);
  assert.match(publicationBuild, /UPDATE_PRIVATE_KEY:\s*\$\{\{\s*secrets\.UPDATE_PRIVATE_KEY\s*\}\}/u);
  assert.match(publicationBuild, /UPDATE_PRIVATE_KEY is not configured; refusing to publish/u);
  assert.ok(
    publicationBuild.indexOf('verify-release-package.php') < publicationBuild.indexOf('actions/upload-artifact@v4'),
    'signed artifacts must be verified before they are uploaded for publication',
  );

  const verificationWorkflow = read('.github/workflows/release.yml');
  const pullRequestJob = jobBlock(verificationWorkflow, 'automation-verification', 'resolve-release-source');
  const manualBuild = jobBlock(verificationWorkflow, 'verify-release-build');
  assert.doesNotMatch(pullRequestJob, /UPDATE_PRIVATE_KEY|secrets\./u);
  assert.match(manualBuild, /environment:\s*\n\s+name:\s*github-release-approval/u);
  assert.match(manualBuild, /UPDATE_PRIVATE_KEY:\s*\$\{\{\s*secrets\.UPDATE_PRIVATE_KEY\s*\}\}/u);
});

test('release workflows resolve fully qualified tags and check out the immutable commit', () => {
  for (const name of ['publish-release.yml', 'release.yml', 'release-desktop.yml']) {
    const workflow = read(path.join('.github', 'workflows', name));
    assert.match(workflow, /refs\/tags\//u, `${name} must resolve a fully qualified tag ref`);
    assert.match(workflow, /release_sha/u, `${name} must expose the resolved release SHA`);
    assert.match(workflow, /--expected-commit\s+['"$]?[A-Z_.${}/-]+/u, `${name} must verify the checked-out SHA`);
  }

  const publicationWorkflow = read('.github/workflows/publish-release.yml');
  assert.match(publicationWorkflow, /ref:\s*\$\{\{\s*needs\.resolve-release-source\.outputs\.release_sha\s*\}\}/u);
  assert.match(publicationWorkflow, /release-provenance\.json/u);
  assert.match(publicationWorkflow, /release_commit/u);
  assert.match(publicationWorkflow, /source.*tag|tag.*source/u);
});

test('source validation accepts annotated tags only through their commit target', () => {
  const { fixture, baselineRef } = makeGitFixture();
  const git = (...args) => execFileSync('git', args, { cwd: fixture, stdio: 'pipe' }).toString().trim();
  git('tag', '-a', 'v0.0.4-annotated', '-m', 'annotated release tag');
  const targetCommit = git('rev-parse', 'HEAD');
  try {
    const result = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4-annotated',
      '--expected-commit', targetCommit,
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend', '--release-channel', 'prerelease',
    ]);
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('source validation rejects missing, changed, or unexpected release commits', () => {
  const { fixture, baselineRef } = makeGitFixture();
  const git = (...args) => execFileSync('git', args, { cwd: fixture, stdio: 'pipe' }).toString().trim();
  const targetCommit = git('rev-parse', 'HEAD');
  git('tag', 'v0.0.4-wrong-target', baselineRef);
  try {
    const missing = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4-missing',
      '--expected-commit', targetCommit,
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend', '--release-channel', 'prerelease',
    ]);
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /release tag|refs\/tags|resolve/i);

    const wrongCommit = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--expected-commit', baselineRef,
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend', '--release-channel', 'prerelease',
    ]);
    assert.notEqual(wrongCommit.status, 0);
    assert.match(wrongCommit.stderr, /expected|checked-out|commit/i);

    const changedTag = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4-wrong-target',
      '--expected-commit', targetCommit,
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--delta-scope', 'backend', '--release-channel', 'prerelease',
    ]);
    assert.notEqual(changedTag.status, 0);
    assert.match(changedTag.stderr, /checked-out|tag|commit/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});
