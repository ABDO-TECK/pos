import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const isWindows = process.platform === 'win32'
const npmCommand = isWindows ? 'npm.cmd' : 'npm'
const dryRun = process.argv.includes('--dry-run')
const skipInstall = process.argv.includes('--skip-install')
const requireMySql = process.argv.includes('--require-mysql')

export const qualitySteps = [
  { label: 'Install root dependencies from lockfile', command: npmCommand, args: ['ci'] },
  { label: 'Validate root dependency tree', command: npmCommand, args: ['ls', '--depth=0'] },
  { label: 'Install frontend dependencies from lockfile', command: npmCommand, args: ['--prefix', 'frontend', 'ci'] },
  { label: 'Validate frontend dependency tree', command: npmCommand, args: ['--prefix', 'frontend', 'ls', '--depth=0'] },
  { label: 'Run quality-runner regression tests', command: process.execPath, args: ['--test', 'scripts/quality.test.mjs'] },
  { label: 'Lint frontend', command: npmCommand, args: ['--prefix', 'frontend', 'run', 'lint'] },
  { label: 'Type-check frontend', command: npmCommand, args: ['--prefix', 'frontend', 'run', 'typecheck'] },
  { label: 'Run frontend unit tests', command: npmCommand, args: ['--prefix', 'frontend', 'test'] },
  { label: 'Build frontend', command: npmCommand, args: ['--prefix', 'frontend', 'run', 'build'] },
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

function runStep({ label, command, args, environment = {} }) {
  const printable = [command, ...args].join(' ')
  console.log(`\n[quality] ${label}\n> ${printable}`)
  if (dryRun) {
    return
  }

  const result = spawnSync(command, args, {
    cwd: repoRoot,
    stdio: 'inherit',
    windowsHide: true,
    env: { ...process.env, ...environment },
  })
  if (result.error) {
    throw new Error(`${label} could not start: ${result.error.message}`)
  }
  if (result.status !== 0) {
    throw new Error(`${label} failed with exit code ${result.status}.`)
  }
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
      label: 'Run real-MySQL migrations and concurrency tests when available',
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
      runStep({
        label: 'Run real-MySQL migrations and concurrency tests',
        command: phpCommand,
        args: ['backend/vendor/bin/phpunit', '--configuration', 'backend/phpunit.xml', '--group', 'mysql'],
        environment: { ...mySqlEnvironment, MYSQL_TEST_ENABLED: '1' },
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
