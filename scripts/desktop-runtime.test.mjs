import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { readRuntimeManifest, validateRuntimeDirectory } from './verify-desktop-runtime.mjs'

const packageManifest = JSON.parse(readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'package.json'), 'utf8'))
const mainSource = readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'electron', 'main.js'), 'utf8')

test('strict desktop runtime verification accepts the pinned manifest and required files', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'pos-desktop-runtime-'))
  const manifest = readRuntimeManifest()
  try {
    for (const relativePath of [...manifest.php.requiredFiles, ...manifest.mysql.requiredFiles]) {
      const filePath = path.join(root, relativePath)
      mkdirSync(path.dirname(filePath), { recursive: true })
      writeFileSync(filePath, '')
    }
    writeFileSync(path.join(root, 'runtime-manifest.json'), JSON.stringify({
      schemaVersion: manifest.schemaVersion,
      php: { version: manifest.php.version, sha256: manifest.php.sha256 },
      mysql: { version: manifest.mysql.version, sha256: manifest.mysql.sha256 },
    }))

    const result = validateRuntimeDirectory(root, { strict: true, runProbes: false, manifest })
    assert.equal(result.ok, true, result.errors.join('; '))
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('desktop runtime verification reports a missing required executable', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'pos-desktop-runtime-'))
  const manifest = readRuntimeManifest()
  try {
    const result = validateRuntimeDirectory(root, { manifest, runProbes: false })
    assert.equal(result.ok, false)
    assert.ok(result.errors.some((error) => error.includes('php/php.exe')))
    assert.ok(result.errors.some((error) => error.includes('mysql/bin/mysqld.exe')))
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('electron-builder keeps the runtime manifest in extraResources, outside app runtime data', () => {
  const buildFiles = packageManifest.build.files || []
  const extraResources = packageManifest.build.extraResources || []
  assert.equal(buildFiles.some((entry) => String(entry).toLowerCase().includes('portable')), false)
  assert.ok(extraResources.some((entry) => entry.from === 'portable' && entry.to === 'portable'))
  assert.ok(extraResources.some((entry) => Array.isArray(entry.filter) && entry.filter.includes('**/*')))
})

test('main startup propagates the selected MariaDB port through PHP and reset flows', () => {
  assert.match(mainSource, /const mysqlInfo = await startMySQL\(mysqlPort, \{ maxStartupAttempts: 3 \}\)/)
  assert.match(mainSource, /applyMysqlStartupInfo\(mysqlInfo\)/)
  assert.match(mainSource, /startPHP\(phpPort, mysqlPort, dbCredentials\)/)
  assert.match(mainSource, /const resetInfo = await resetDatabase\(mysqlPort\)/)
  assert.match(mainSource, /applyMysqlStartupInfo\(resetInfo\)/)
  assert.match(mainSource, /await startCoreRuntime\(\{ maxAttempts: 2 \}\)/)
})
