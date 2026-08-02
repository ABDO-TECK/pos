import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import os from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import {
  classifyMySqlProbe,
  enforcedTypeScriptFiles,
  findForbiddenTypeSuppressions,
} from './quality.mjs'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

test('quality gate uses clean installs and validates both dependency trees', () => {
  const output = execFileSync(
    process.execPath,
    ['scripts/quality.mjs', '--dry-run'],
    { cwd: repoRoot, encoding: 'utf8' },
  )

  assert.match(output, /> npm(?:\.cmd)? ci/)
  assert.match(output, /> npm(?:\.cmd)? ls --depth=0/)
  assert.match(output, /> npm(?:\.cmd)? --prefix frontend ci/)
  assert.match(output, /> npm(?:\.cmd)? --prefix frontend ls --depth=0/)
  assert.match(output, /run lint/)
  assert.match(output, /run typecheck/)
  assert.match(output, /frontend test/)
  assert.match(output, /run build/)
  assert.match(output, /Check backend dependencies/)
  assert.match(output, /backend\/vendor\/bin\/phpunit/)
  assert.match(output, /--exclude-group mysql/)
  assert.match(output, /mysql_probe\.php/)
  assert.match(output, /--group mysql/)
})

test('MySQL probe outcomes distinguish unavailable service from gate failures', () => {
  assert.equal(classifyMySqlProbe(0), 'available')
  assert.equal(classifyMySqlProbe(2), 'unavailable')
  assert.equal(classifyMySqlProbe(1), 'failed')
  assert.equal(classifyMySqlProbe(null), 'failed')
})

test('all frontend TypeScript sources reject file-level suppressions', () => {
  assert.deepEqual(findForbiddenTypeSuppressions(repoRoot), [])
  assert.ok(enforcedTypeScriptFiles.length > 0)
  for (const relativePath of enforcedTypeScriptFiles) {
    assert.match(readFileSync(path.join(repoRoot, relativePath), 'utf8'), /\S/u)
  }
})

test('suppression detector reports a newly introduced file-level suppression', () => {
  const temporaryRoot = mkdtempSync(path.join(os.tmpdir(), 'pos-quality-'))
  try {
    mkdirSync(path.join(temporaryRoot, 'frontend/src'), { recursive: true })
    writeFileSync(
      path.join(temporaryRoot, 'frontend/src/Enforced.tsx'),
      '// @ts-nocheck\nexport const value = 1\n',
    )
    assert.deepEqual(
      findForbiddenTypeSuppressions(temporaryRoot, ['frontend/src/Enforced.tsx']),
      ['frontend/src/Enforced.tsx:1: // @ts-nocheck'],
    )
  } finally {
    rmSync(temporaryRoot, { recursive: true, force: true })
  }
})

test('root package lock is not ignored', () => {
  const gitignore = readFileSync(path.join(repoRoot, '.gitignore'), 'utf8')
  const activeRules = gitignore
    .split(/\r?\n/u)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'))

  assert.equal(activeRules.includes('package-lock.json'), false)
})

test('CI installs the locked backend dependencies before skipping local installs', () => {
  const workflow = readFileSync(path.join(repoRoot, '.github/workflows/quality.yml'), 'utf8')

  assert.match(
    workflow,
    /composer install --working-dir=backend --no-interaction --prefer-dist --no-progress/u,
  )
  assert.match(workflow, /node scripts\/quality\.mjs --skip-install --require-mysql/u)
  assert.match(workflow, /extensions: pdo_mysql, gd, mbstring, dom, xml, xmlwriter, zip/u)
})

test('backend PSR-4 source paths use Linux-correct casing', () => {
  const expectedSourceDirectories = [
    'Config',
    'Contracts',
    'Controllers',
    'Core',
    'Helpers',
    'Middleware',
    'Models',
    'Repositories',
    'Requests',
    'Services',
  ]
  const trackedPaths = execFileSync(
    'git',
    ['ls-files', '--cached', '--others', '--exclude-standard', '-z', 'backend'],
    { cwd: repoRoot, encoding: 'utf8' },
  )
    .split('\0')
    .filter(Boolean)
    .sort()

  const trackedSourceDirectories = [...new Set(
    trackedPaths
      .map((trackedPath) => /^backend\/([^/]+)\//u.exec(trackedPath)?.[1])
      .filter((directory) => directory && expectedSourceDirectories
        .some((expected) => expected.toLowerCase() === directory.toLowerCase())),
  )].sort()

  assert.deepEqual(trackedSourceDirectories, expectedSourceDirectories)
  assert.deepEqual(trackedPaths.filter((trackedPath) => trackedPath.startsWith('backend/Config/')), [
    'backend/Config/Database.php',
    'backend/Config/DeploymentSecurity.php',
    'backend/Config/bindings.php',
    'backend/Config/config.php',
  ])
  assert.match(
    readFileSync(path.join(repoRoot, 'backend/Config/config.php'), 'utf8'),
    /namespace App\\Config;/u,
  )
  assert.match(
    readFileSync(path.join(repoRoot, 'backend/cli/bootstrap-admin.php'), 'utf8'),
    /require dirname\(__DIR__\) \. '\/Config\/config\.php';/u,
  )
  assert.doesNotMatch(
    trackedPaths
      .filter((trackedPath) => trackedPath.endsWith('.php'))
      .map((trackedPath) => readFileSync(path.join(repoRoot, trackedPath), 'utf8'))
      .join('\n'),
    /\/(?:config|controllers|core|helpers|middleware|models|requests|services)\//u,
  )
})

test('Electron manifest, lock, and hardened renderer APIs stay compatible', () => {
  const manifest = JSON.parse(readFileSync(path.join(repoRoot, 'package.json'), 'utf8'))
  const lock = JSON.parse(readFileSync(path.join(repoRoot, 'package-lock.json'), 'utf8'))
  const electronVersion = lock.packages['node_modules/electron']?.version
  const mainSource = readFileSync(path.join(repoRoot, 'electron/main.js'), 'utf8')

  assert.equal(manifest.scripts['build:phar'], 'node scripts/build-phar.mjs')
  assert.equal(manifest.devDependencies.electron, '^43.2.0')
  assert.match(electronVersion, /^43\./u)
  assert.ok(
    mainSource.indexOf('protocol.registerSchemesAsPrivileged')
      < mainSource.indexOf('app.whenReady()'),
    'custom schemes must be registered before app readiness',
  )
  assert.match(mainSource, /contextIsolation:\s*true/u)
  assert.match(mainSource, /nodeIntegration:\s*false/u)
  assert.match(mainSource, /sandbox:\s*true/u)

  for (const file of [
    'electron/main.js',
    'electron/preload.js',
    'electron/preload-splash.js',
    'electron/recovery-preload.js',
  ]) {
    execFileSync(process.execPath, ['--check', file], { cwd: repoRoot })
  }
})
