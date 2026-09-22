import assert from 'node:assert/strict'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe'

function writeFixtureFile(root, relativePath, content) {
  const filePath = path.join(root, relativePath)
  mkdirSync(path.dirname(filePath), { recursive: true })
  writeFileSync(filePath, content)
  return filePath
}

function createFixture({ withVendor }) {
  const root = mkdtempSync(path.join(tmpdir(), 'pos-phar-build-'))
  const vendorSource = path.join(root, 'composer-vendor')
  mkdirSync(path.join(root, 'backend'), { recursive: true })

  writeFileSync(path.join(root, 'build-phar.php'), readFileSync(path.join(repoRoot, 'build-phar.php')))
  writeFixtureFile(root, 'version.json', JSON.stringify({ version: '0.0.3' }))
  writeFixtureFile(root, 'backend/certs/cacert.pem', 'fixture-ca')
  writeFixtureFile(root, 'backend/router.php', "<?php echo 'ROUTER_OK';")
  writeFixtureFile(root, 'backend/index.php', "<?php echo 'INDEX_OK';")
  writeFixtureFile(root, 'backend/cli/migrate.php', `<?php
require_once __DIR__ . '/../vendor/autoload.php';
if (!class_exists('FixtureRuntimeDependency')) {
    fwrite(STDERR, 'Fixture runtime dependency was not autoloaded.');
    exit(2);
}
echo 'MIGRATE_ENTRY_OK';
`)
  writeFixtureFile(root, 'backend/cli/verify-database.php', "<?php echo 'VERIFY_DATABASE_OK';")
  writeFixtureFile(root, 'backend/cli/initialize-admin.php', "<?php echo 'INITIALIZE_ADMIN_OK';")
  writeFixtureFile(root, 'backend/composer.json', JSON.stringify({
    require: { 'fixture/runtime': '1.0.0' },
  }))

  if (withVendor) {
    writeFixtureFile(vendorSource, 'autoload.php', `<?php
class FixtureRuntimeDependency {}
`)
    writeFixtureFile(vendorSource, 'composer/autoload_real.php', '<?php')
    writeFixtureFile(vendorSource, 'fixture/runtime/src/RuntimeDependency.php', '<?php class FixtureRuntimeDependency {}')
    symlinkSync(vendorSource, path.join(root, 'backend/vendor'), 'junction')
  }

  return {
    root,
    pharPath: path.join(root, 'backend/backend.phar'),
  }
}

function runBuilder(root) {
  return spawnSync(phpBinary, ['-d', 'phar.readonly=0', 'build-phar.php'], {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
  })
}

function inspectArchive(pharPath) {
  const probePath = path.join(path.dirname(pharPath), 'inspect-phar.php')
  writeFileSync(probePath, `<?php
$archive = new Phar($argv[1]);
$required = ['vendor/autoload.php', 'vendor/composer/autoload_real.php', 'vendor/fixture/runtime/src/RuntimeDependency.php', 'cli/migrate.php', 'cli/verify-database.php', 'cli/initialize-admin.php', 'router.php', 'version.json', 'certs/cacert.pem'];
foreach ($required as $entry) {
    if (!isset($archive[$entry])) {
        fwrite(STDERR, "Missing PHAR entry: {$entry}\\n");
        exit(1);
    }
}
echo 'ARCHIVE_OK';
`)

  return spawnSync(phpBinary, [probePath, pharPath], {
    encoding: 'utf8',
    windowsHide: true,
  })
}

test('build-phar packages a Composer vendor junction and starts the CLI entry point', () => {
  const fixture = createFixture({ withVendor: true })
  try {
    const build = runBuilder(fixture.root)
    assert.equal(build.status, 0, build.stderr || build.stdout)
    assert.equal(existsSync(fixture.pharPath), true)

    const archive = inspectArchive(fixture.pharPath)
    assert.equal(archive.status, 0, archive.stderr || archive.stdout)

    const runtime = spawnSync(phpBinary, [fixture.pharPath, 'migrate'], {
      encoding: 'utf8',
      windowsHide: true,
    })
    assert.equal(runtime.status, 0, runtime.stderr || runtime.stdout)
    assert.match(runtime.stdout, /MIGRATE_ENTRY_OK/)
  } finally {
    rmSync(fixture.root, { recursive: true, force: true })
  }
})

test('build-phar refuses to emit an archive when Composer vendor is unavailable', () => {
  const fixture = createFixture({ withVendor: false })
  try {
    const build = runBuilder(fixture.root)
    assert.notEqual(build.status, 0, `${build.stdout}\n${build.stderr}`)
    assert.match(`${build.stdout}\n${build.stderr}`, /vendor|Composer|autoload/i)
    assert.equal(existsSync(fixture.pharPath), false)
  } finally {
    rmSync(fixture.root, { recursive: true, force: true })
  }
})
