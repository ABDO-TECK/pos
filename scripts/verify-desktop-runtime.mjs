import { existsSync, readFileSync, statSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const manifestPath = path.join(repoRoot, 'scripts', 'desktop-runtime.json')

export function readRuntimeManifest(filePath = manifestPath) {
  return JSON.parse(readFileSync(filePath, 'utf8'))
}

function isFile(filePath) {
  try {
    return statSync(filePath).isFile()
  } catch {
    return false
  }
}

function runProbe(executable, args, cwd) {
  const result = spawnSync(executable, args, {
    cwd,
    encoding: 'utf8',
    windowsHide: true,
    timeout: 10_000,
  })
  return {
    ok: !result.error && result.status === 0,
    output: `${result.stdout || ''}${result.stderr || ''}`.trim(),
    error: result.error?.message || null,
    status: result.status,
  }
}

export function validateRuntimeDirectory(runtimeRoot, { strict = false, runProbes = true, manifest = readRuntimeManifest() } = {}) {
  const errors = []
  const requiredFiles = [
    ...manifest.php.requiredFiles,
    ...manifest.mysql.requiredFiles,
  ]
  const files = requiredFiles.map((relativePath) => ({
    relativePath,
    path: path.join(runtimeRoot, relativePath),
    exists: isFile(path.join(runtimeRoot, relativePath)),
  }))
  for (const file of files) {
    if (!file.exists) errors.push(`missing runtime file: ${file.relativePath}`)
  }

  const installedManifestPath = path.join(runtimeRoot, 'runtime-manifest.json')
  let installedManifest = null
  if (existsSync(installedManifestPath)) {
    try {
      installedManifest = JSON.parse(readFileSync(installedManifestPath, 'utf8'))
    } catch (error) {
      errors.push(`invalid runtime-manifest.json: ${error.message}`)
    }
  } else if (strict) {
    errors.push('missing runtime-manifest.json')
  }

  if (strict && installedManifest) {
    if (String(installedManifest.php?.version) !== String(manifest.php.version)) {
      errors.push(`PHP runtime version is not pinned to ${manifest.php.version}`)
    }
    if (String(installedManifest.mysql?.version) !== String(manifest.mysql.version)) {
      errors.push(`MariaDB runtime version is not pinned to ${manifest.mysql.version}`)
    }
    if (String(installedManifest.php?.sha256).toLowerCase() !== String(manifest.php.sha256).toLowerCase()) {
      errors.push('PHP runtime manifest checksum does not match the pinned archive')
    }
    if (String(installedManifest.mysql?.sha256).toLowerCase() !== String(manifest.mysql.sha256).toLowerCase()) {
      errors.push('MariaDB runtime manifest checksum does not match the pinned archive')
    }
  }

  const probes = {}
  if (runProbes && errors.length === 0) {
    const phpPath = path.join(runtimeRoot, 'php', 'php.exe')
    const mysqlPath = path.join(runtimeRoot, 'mysql', 'bin', 'mysqld.exe')
    probes.php = runProbe(phpPath, ['--version'], path.dirname(phpPath))
    probes.mysql = runProbe(mysqlPath, ['--version'], path.dirname(mysqlPath))
    if (!probes.php.ok) errors.push(`PHP executable probe failed: ${probes.php.error || probes.php.output || probes.php.status}`)
    if (!probes.mysql.ok) errors.push(`MySQL/MariaDB executable probe failed: ${probes.mysql.error || probes.mysql.output || probes.mysql.status}`)
    if (strict && probes.php.ok && !new RegExp(`PHP ${manifest.php.version.replaceAll('.', '\\.')}(?:\\s|$)`, 'i').test(probes.php.output)) {
      errors.push(`PHP executable did not report version ${manifest.php.version}`)
    }
    if (strict && probes.mysql.ok && !probes.mysql.output.includes(manifest.mysql.version)) {
      errors.push(`MySQL/MariaDB executable did not report version ${manifest.mysql.version}`)
    }
  }

  return { ok: errors.length === 0, errors, files, probes, installedManifest }
}

function main() {
  const strict = process.argv.includes('--strict')
  const rootArgumentIndex = process.argv.indexOf('--root')
  const runtimeRoot = rootArgumentIndex >= 0
    ? path.resolve(process.argv[rootArgumentIndex + 1])
    : path.join(repoRoot, 'portable')
  const result = validateRuntimeDirectory(runtimeRoot, { strict })
  if (!result.ok) {
    console.error(`[desktop-runtime] Invalid runtime at ${runtimeRoot}`)
    for (const error of result.errors) console.error(`- ${error}`)
    process.exitCode = 1
    return
  }
  console.log(`[desktop-runtime] Verified PHP ${result.probes.php.output.split(/\r?\n/u)[0]} and MariaDB ${result.probes.mysql.output.split(/\r?\n/u)[0]}`)
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main()
