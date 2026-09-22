import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
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

const phpBinary = process.env.POS_PHP || (process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : 'php');
const builder = path.join(repoRoot, 'scripts', 'build-release-package.php');
const packageVerifier = path.join(repoRoot, 'scripts', 'verify-release-package.php');
const sourceFixture = path.join(repoRoot, 'scratch', 'final-release-integration-20260922', 'source-v0.0.4');

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

test('working-tree validator fails closed on the current remote-main version mismatch', () => {
  const result = runValidator(['--mode', 'working-tree', '--root', repoRoot]);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /package\.json|frontend\/package\.json|versions differ/i);
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
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /baseline-ref|baseline-version/i);

    const wrongVersion = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.2',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(wrongVersion.status, 0);
    assert.match(wrongVersion.stderr, /baseline/i);

    const missingRef = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', 'does-not-exist', '--baseline-version', '0.0.1',
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

test('v0 stable publication is rejected and tag mismatch is rejected', () => {
  const { fixture, baselineRef } = makeGitFixture();
  try {
    const stable = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--release-channel', 'stable',
    ]);
    assert.notEqual(stable.status, 0);
    assert.match(stable.stderr, /prerelease|stable/i);

    const mismatch = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.3',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(mismatch.status, 0);
    assert.match(mismatch.stderr, /tag|version/i);

    const bootstrap = runValidator([
      '--mode', 'delta', '--root', fixture, '--tag', 'v0.0.4-bootstrap',
      '--baseline-ref', baselineRef, '--baseline-version', '0.0.1',
      '--release-channel', 'prerelease',
    ]);
    assert.notEqual(bootstrap.status, 0);
    assert.match(bootstrap.stderr, /bootstrap|full/i);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }
});

test('Delta builder rejects an omitted baseline instead of guessing a previous tag', {
  skip: !fs.existsSync(path.join(repoRoot, 'backend', 'vendor', 'autoload.php')),
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
});

test('pull-request verification runs automation tests without legacy source validation', () => {
  const workflow = read('.github/workflows/release.yml');
  const automationJob = workflow.match(/  automation-verification:[\s\S]*?(?=\n  verify-release-build:)/u)?.[0] || '';
  assert.match(automationJob, /npm run test:release-workflow/u);
  assert.doesNotMatch(automationJob, /validate-release-source\.mjs/u);
  assert.doesNotMatch(automationJob, /working-tree source consistency/u);
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
  assert.doesNotMatch(updatePublisher, /--private-key/);
  assert.match(updatePublisher, /environment:\s*\n?\s+name:\s*github-release-approval|environment:\s+github-release-approval/);
  assert.match(updatePublisher, /contents:\s+write/);
  assert.match(updatePublisher, /gh\s+release\s+create/);
  assert.match(updatePublisher, /404 Not Found/);
  assert.match(updatePublisher, /verify-tag/);
  assert.doesNotMatch(updatePublisher, /--clobber|softprops\/action-gh-release/);

  assert.match(desktopPublisher, /workflow_dispatch:/);
  assert.match(desktopPublisher, /confirm_publish/);
  assert.match(desktopPublisher, /github-release-approval/);
  assert.match(desktopPublisher, /validate-release-source\.mjs\s+--mode full/);
  assert.match(desktopPublisher, /existing desktop asset conflicts|existing.*asset|asset.*conflict/i);
  assert.doesNotMatch(desktopPublisher, /--clobber/);
  assert.doesNotMatch(desktopPublisher, /-RequireAuthenticode|require-production-signing/);
});

test('no workflow can publish from an automatic tag trigger', () => {
  for (const name of fs.readdirSync(path.join(repoRoot, '.github', 'workflows'))) {
    const workflow = read(path.join('.github', 'workflows', name));
    if (/gh\s+release|softprops\/action-gh-release/.test(workflow)) {
      assert.doesNotMatch(workflow, /push:\s*\n\s+tags:/, `${name} must not publish on tag push`);
    }
  }
});
