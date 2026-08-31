import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const isWindows = process.platform === 'win32'
const bundledNpmCli = path.join(path.dirname(process.execPath), 'node_modules/npm/bin/npm-cli.js')
const npmCli = isWindows
  ? (process.env.npm_execpath && existsSync(process.env.npm_execpath)
      ? process.env.npm_execpath
      : bundledNpmCli)
  : null
const npmCommand = isWindows ? process.execPath : 'npm'
const npmArgs = (args) => isWindows ? [npmCli, ...args] : args
const npmDisplayCommand = isWindows ? 'npm.cmd' : 'npm'
const dryRun = process.argv.includes('--dry-run')
const skipInstall = process.argv.includes('--skip-install')
const requireMySql = process.argv.includes('--require-mysql')

export const qualitySteps = [
  { label: 'Install root dependencies from lockfile', command: npmCommand, args: npmArgs(['ci']), displayCommand: npmDisplayCommand, displayArgs: ['ci'] },
  { label: 'Validate root dependency tree', command: npmCommand, args: npmArgs(['ls', '--depth=0']), displayCommand: npmDisplayCommand, displayArgs: ['ls', '--depth=0'] },
  { label: 'Install frontend dependencies from lockfile', command: npmCommand, args: npmArgs(['--prefix', 'frontend', 'ci']), displayCommand: npmDisplayCommand, displayArgs: ['--prefix', 'frontend', 'ci'] },
  { label: 'Validate frontend dependency tree', command: npmCommand, args: npmArgs(['--prefix', 'frontend', 'ls', '--depth=0']), displayCommand: npmDisplayCommand, displayArgs: ['--prefix', 'frontend', 'ls', '--depth=0'] },
  { label: 'Run quality-runner regression tests', command: process.execPath, args: ['--test', 'scripts/quality.test.mjs'] },
  { label: 'Lint frontend', command: npmCommand, args: npmArgs(['--prefix', 'frontend', 'run', 'lint']), displayCommand: npmDisplayCommand, displayArgs: ['--prefix', 'frontend', 'run', 'lint'] },
  { label: 'Type-check frontend', command: npmCommand, args: npmArgs(['--prefix', 'frontend', 'run', 'typecheck']), displayCommand: npmDisplayCommand, displayArgs: ['--prefix', 'frontend', 'run', 'typecheck'] },
  { label: 'Run frontend unit tests', command: npmCommand, args: npmArgs(['--prefix', 'frontend', 'test']), displayCommand: npmDisplayCommand, displayArgs: ['--prefix', 'frontend', 'test'] },
  { label: 'Build frontend', command: npmCommand, args: npmArgs(['--prefix', 'frontend', 'run', 'build']), displayCommand: npmDisplayCommand, displayArgs: ['--prefix', 'frontend', 'run', 'build'] },
]

function collectTypeScriptFiles(root) {
  const sourceRoot = path.join(root, 'frontend/src')
  if (!existsSync(sourceRoot)) return []

  const visit = (directory) => readdirSync(directory, { withFileTypes: true })
    .flatMap((entry) => {
      const absolutePath = path.join(directory, entry.name)
      if (entry.isDirectory()) return visit(absolutePath)
      if (!entry.isFile() || !/\.tsx?$/u.test(entry.name)) return []
      return [path.relative(root, absolutePath).split(path.sep).join('/')]
    })

  return visit(sourceRoot).sort()
}

// Every frontend TypeScript source is suppression-free. Keep that property as
// a repository-wide invariant so new files cannot silently opt out of checks.
export const enforcedTypeScriptFiles = collectTypeScriptFiles(repoRoot)

export function findForbiddenTypeSuppressions(root = repoRoot, files = enforcedTypeScriptFiles) {
  return files.flatMap((relativePath) => {
    const absolutePath = path.join(root, relativePath)
    if (!existsSync(absolutePath)) return [`${relativePath}: file is missing`]

    const source = readFileSync(absolutePath, 'utf8')
    return source.split(/\r?\n/u)
      .map((line, index) => /^\s*\/\/\s*@ts-(?:nocheck|ignore)\b/u.test(line)
        ? `${relativePath}:${index + 1}: ${line.trim()}`
        : null)
      .filter((match) => match !== null)
  })
}

function resolvePhpCommand() {
  if (process.env.PHP_BINARY) {
    return process.env.PHP_BINARY
  }

  const candidates = isWindows
    ? ['php.exe', 'C:\\xampp\\php\\php.exe']
    : ['php']

  for (const candidate of candidates) {
    if (path.isAbsolute(candidate) && !existsSync(candidate)) {
      continue
    }
    const probe = spawnSync(candidate, ['--version'], {
      cwd: repoRoot,
      encoding: 'utf8',
      windowsHide: true,
    })
    if (!probe.error && probe.status === 0) {
      return candidate
    }
  }

  throw new Error(
    'PHP was not found. Install PHP 8.1+ or set PHP_BINARY to the PHP executable; see docs/quality-gates.md.',
  )
}

function readBackendEnvironment() {
  const envPath = path.join(repoRoot, 'backend/.env')
  if (!existsSync(envPath)) return {}

  return Object.fromEntries(
    readFileSync(envPath, 'utf8')
      .split(/\r?\n/u)
      .map((line) => line.trim())
      .filter((line) => line && !line.startsWith('#') && line.includes('='))
      .map((line) => {
        const separator = line.indexOf('=')
        return [line.slice(0, separator), line.slice(separator + 1).replace(/^['"]|['"]$/gu, '')]
      }),
  )
}

function mysqlEnvironment() {
  const backendEnvironment = readBackendEnvironment()
  return {
    DB_HOST: process.env.DB_HOST || backendEnvironment.DB_HOST || '127.0.0.1',
    DB_PORT: process.env.DB_PORT || backendEnvironment.DB_PORT || '3307',
    DB_NAME: process.env.DB_NAME
      || process.env.DB_DATABASE
      || backendEnvironment.DB_NAME
      || backendEnvironment.DB_DATABASE
      || 'pos',
    DB_USER: process.env.DB_USER
      || process.env.DB_USERNAME
      || backendEnvironment.DB_USER
      || backendEnvironment.DB_USERNAME
      || 'root',
    DB_PASS: process.env.DB_PASS
      ?? process.env.DB_PASSWORD
      ?? backendEnvironment.DB_PASS
      ?? backendEnvironment.DB_PASSWORD
      ?? '',
  }
}

function assertBackendPrerequisites() {
  const phpunitPath = path.join(repoRoot, 'backend/vendor/bin/phpunit')
  if (!existsSync(phpunitPath)) {
    throw new Error(
      'Backend dependencies are missing. Run Composer install from backend/composer.lock; see docs/quality-gates.md.',
    )
  }

}

export function classifyMySqlProbe(status) {
  if (status === 0) return 'available'
  if (status === 2) return 'unavailable'
  return 'failed'
}

function probeMySql(phpCommand, environment) {
  const result = spawnSync(phpCommand, ['backend/tests/Fixtures/mysql_probe.php'], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
    timeout: 7000,
    env: { ...process.env, ...environment },
  })
  const output = `${result.stdout || ''}${result.stderr || ''}`.trim()
  if (output) console.log(output)
  if (result.error) {
    throw new Error(`MySQL prerequisite probe could not run: ${result.error.message}`)
  }

  return classifyMySqlProbe(result.status)
}

export function runStep({ label, command, args, displayCommand = command, displayArgs = args, environment = {}, timeout = 300_000 }) {
  const printable = [displayCommand, ...displayArgs].join(' ')
  const startTime = Date.now()
  console.log(`\n[quality] START ${label} ${new Date().toISOString()}\n> ${printable}`)
  if (dryRun) {
    return
  }

  const result = spawnSync(command, args, {
    cwd: repoRoot,
    stdio: 'inherit',
    windowsHide: true,
    timeout,
    killSignal: 'SIGKILL',
    env: { ...process.env, ...environment },
  })
  const duration = ((Date.now() - startTime) / 1000).toFixed(2)
  if (result.error) {
    if (result.error.name === 'Error' && (result.error.code === 'ETIMEDOUT' || result.signal === 'SIGKILL')) {
      console.error(`[quality] FAIL ${label} (${duration}s) (timed out after ${timeout / 1000} seconds)`)
      throw new Error(`${label} timed out after ${timeout / 1000} seconds.`)
    }
    console.error(`[quality] FAIL ${label} (${duration}s) (could not start: ${result.error.message})`)
    throw new Error(`${label} could not start: ${result.error.message}`)
  }
  if (result.status !== 0) {
    console.error(`[quality] FAIL ${label} (${duration}s) (exit code ${result.status})`)
    throw new Error(`${label} failed with exit code ${result.status}.`)
  }
  console.log(`[quality] PASS ${label} (${duration}s)`)
}

async function main() {
  const forbiddenSuppressions = findForbiddenTypeSuppressions()
  if (forbiddenSuppressions.length > 0) {
    throw new Error(`Forbidden TypeScript suppression(s):\n${forbiddenSuppressions.join('\n')}`)
  }

  const steps = skipInstall
    ? qualitySteps.filter(({ label }) => !label.includes('Install') && !label.includes('dependency tree'))
    : qualitySteps
  for (const step of steps) {
    runStep(step)
  }

  console.log('\n[quality] Check backend dependencies')
  if (!dryRun) {
    assertBackendPrerequisites()
  }
  const phpCommand = dryRun ? (process.env.PHP_BINARY || 'php') : resolvePhpCommand()
  const mySqlEnvironment = mysqlEnvironment()
  runStep({
    label: 'Run backend PHPUnit suite without real-MySQL tests',
    command: phpCommand,
    args: [
      'backend/vendor/bin/phpunit',
      '--configuration',
      'backend/phpunit.xml',
      '--exclude-group',
      'mysql',
    ],
  })

  console.log('\n[quality] Probe real MySQL concurrency prerequisite')
  if (dryRun) {
    console.log(`> ${phpCommand} backend/tests/Fixtures/mysql_probe.php`)
    runStep({
      label: 'Run real-MySQL migration tests',
      command: phpCommand,
      args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlMigrationTest.php'],
    })
    runStep({
      label: 'Run real-MySQL partner isolation tests',
      command: phpCommand,
      args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/BusinessPartnerBranchIsolationTest.php'],
    })
    runStep({
      label: 'Run real-MySQL concurrency test: simultaneous sales',
      command: phpCommand,
      args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlConcurrencyTest.php', '--filter', 'testSimultaneousIdenticalSalesAreAppliedOnceAndChangedPayloadConflicts'],
    })
    runStep({
      label: 'Run real-MySQL concurrency test: sale replacement serialization',
      command: phpCommand,
      args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlConcurrencyTest.php', '--filter', 'testSaleReplacementSerializesBeforeDeletionAndDeletionUsesOneAffectedHeader'],
    })
    runStep({
      label: 'Run real-MySQL concurrency test: purchase replacement serialization',
      command: phpCommand,
      args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlConcurrencyTest.php', '--filter', 'testPurchaseReplacementSerializesBeforeDeletionAndDeletionUsesOneAffectedHeader'],
    })
    runStep({
      label: 'Run complete real-MySQL test suite verification',
      command: phpCommand,
      args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', '--group', 'mysql'],
    })
  } else {
    const probeOutcome = probeMySql(phpCommand, mySqlEnvironment)
    if (probeOutcome === 'unavailable') {
      const message = 'Real-MySQL concurrency gate unavailable; non-DB gates completed.'
      if (requireMySql) {
        throw new Error(`${message} MySQL is required for this run.`)
      }
      console.warn(`[quality] ${message} Skipping only the MySQL group.`)
    } else if (probeOutcome === 'failed') {
      throw new Error('MySQL prerequisite probe failed because its configuration or runtime is invalid.')
    } else {
      const mysqlEnv = { ...mySqlEnvironment, MYSQL_TEST_ENABLED: '1' }
      runStep({
        label: 'Run real-MySQL migration tests',
        command: phpCommand,
        args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlMigrationTest.php'],
        environment: mysqlEnv,
      })
      runStep({
        label: 'Run real-MySQL partner isolation tests',
        command: phpCommand,
        args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/BusinessPartnerBranchIsolationTest.php'],
        environment: mysqlEnv,
      })
      runStep({
        label: 'Run real-MySQL concurrency test: simultaneous sales',
        command: phpCommand,
        args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlConcurrencyTest.php', '--filter', 'testSimultaneousIdenticalSalesAreAppliedOnceAndChangedPayloadConflicts'],
        environment: mysqlEnv,
      })
      runStep({
        label: 'Run real-MySQL concurrency test: sale replacement serialization',
        command: phpCommand,
        args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlConcurrencyTest.php', '--filter', 'testSaleReplacementSerializesBeforeDeletionAndDeletionUsesOneAffectedHeader'],
        environment: mysqlEnv,
      })
      runStep({
        label: 'Run real-MySQL concurrency test: purchase replacement serialization',
        command: phpCommand,
        args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', 'backend/tests/Integration/MySqlConcurrencyTest.php', '--filter', 'testPurchaseReplacementSerializesBeforeDeletionAndDeletionUsesOneAffectedHeader'],
        environment: mysqlEnv,
      })
      runStep({
        label: 'Run complete real-MySQL test suite verification',
        command: phpCommand,
        args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', '--group', 'mysql'],
        environment: mysqlEnv,
      })
    }
  }
  console.log(dryRun
    ? '\n[quality] Dry run complete; no commands were executed.'
    : '\n[quality] All available quality gates passed.')
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    console.error(`\n[quality] ${error instanceof Error ? error.message : String(error)}`)
    process.exitCode = 1
  })
}
