const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { applyPendingDelta, setHandoffState } = require('../services/delta-update-handoff');

function hash(content) {
  return crypto.createHash('sha256').update(content).digest('hex');
}

test('desktop hand-off turns a simulated old deployment into the expected target tree', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-delta-'));
  try {
    const storage = path.join(root, 'data');
    const customerData = path.join(root, 'customer-data');
    const deploy = path.join(root, 'app.asar.unpacked');
    const staging = path.join(storage, 'update_staging', '1.2.0');
    fs.mkdirSync(path.join(deploy, 'backend'), { recursive: true });
    fs.mkdirSync(path.join(staging, 'backend'), { recursive: true });
    fs.mkdirSync(path.join(customerData, 'uploads'), { recursive: true });
    fs.mkdirSync(path.join(deploy, 'frontend', 'dist', 'assets'), { recursive: true });
    fs.mkdirSync(path.join(staging, 'frontend', 'dist', 'assets'), { recursive: true });
    fs.writeFileSync(path.join(deploy, 'backend', 'backend.phar'), 'old-phar');
    fs.writeFileSync(path.join(deploy, 'frontend', 'dist', 'assets', 'keep.js'), 'unchanged');
    fs.writeFileSync(path.join(deploy, 'frontend', 'dist', 'assets', 'old.js'), 'obsolete');
    fs.writeFileSync(path.join(deploy, 'version.json'), JSON.stringify({ version: '1.1.0' }));
    fs.writeFileSync(path.join(customerData, 'settings.json'), '{"store":"fixture"}');
    fs.writeFileSync(path.join(customerData, 'uploads', 'receipt.txt'), 'customer receipt');
    fs.writeFileSync(path.join(staging, 'backend', 'backend.phar'), 'new-phar');
    fs.writeFileSync(path.join(staging, 'frontend', 'dist', 'assets', 'app-new.js'), 'new-frontend');
    fs.writeFileSync(path.join(staging, 'desktop-handoff.json'), JSON.stringify({
      version: '1.2.0', root_dir: deploy, storage_dir: storage, staging_dir: staging,
      snapshot_path: path.join(root, 'snapshot'),
      manifest: {
        files: [
          { path: 'backend/backend.phar', sha256: hash('new-phar') },
          { path: 'frontend/dist/assets/app-new.js', sha256: hash('new-frontend') },
        ],
        deleted_files: ['frontend/dist/assets/old.js'],
      },
    }));

    const result = applyPendingDelta(storage, '1.2.0', deploy);
    assert.equal(result.ok, true);
    assert.equal(fs.readFileSync(path.join(deploy, 'backend', 'backend.phar'), 'utf8'), 'new-phar');
    assert.equal(fs.readFileSync(path.join(deploy, 'frontend', 'dist', 'assets', 'keep.js'), 'utf8'), 'unchanged');
    assert.equal(fs.readFileSync(path.join(deploy, 'frontend', 'dist', 'assets', 'app-new.js'), 'utf8'), 'new-frontend');
    assert.equal(fs.existsSync(path.join(deploy, 'frontend', 'dist', 'assets', 'old.js')), false);
    assert.equal(JSON.parse(fs.readFileSync(path.join(deploy, 'version.json'), 'utf8')).version, '1.2.0');
    assert.equal(fs.readFileSync(path.join(customerData, 'settings.json'), 'utf8'), '{"store":"fixture"}');
    assert.equal(fs.readFileSync(path.join(customerData, 'uploads', 'receipt.txt'), 'utf8'), 'customer receipt');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('desktop hand-off rejects a corrupt staged artifact before modifying deployment', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-delta-'));
  try {
    const storage = path.join(root, 'data');
    const deploy = path.join(root, 'app.asar.unpacked');
    const staging = path.join(storage, 'update_staging', '1.2.0');
    fs.mkdirSync(path.join(deploy, 'backend'), { recursive: true });
    fs.mkdirSync(path.join(staging, 'backend'), { recursive: true });
    fs.writeFileSync(path.join(deploy, 'backend', 'backend.phar'), 'old-phar');
    fs.writeFileSync(path.join(staging, 'backend', 'backend.phar'), 'tampered');
    fs.writeFileSync(path.join(staging, 'desktop-handoff.json'), JSON.stringify({
      version: '1.2.0', root_dir: deploy, storage_dir: storage, staging_dir: staging,
      snapshot_path: path.join(root, 'missing-snapshot'),
      manifest: { files: [{ path: 'backend/backend.phar', sha256: hash('expected') }], deleted_files: [] },
    }));

    const result = applyPendingDelta(storage, '1.2.0', deploy);
    assert.equal(result.ok, false);
    assert.equal(fs.readFileSync(path.join(deploy, 'backend', 'backend.phar'), 'utf8'), 'old-phar');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('desktop hand-off persists migration recovery context before migrations run', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-delta-state-'));
  try {
    const storage = path.join(root, 'customer-data');
    fs.mkdirSync(storage, { recursive: true });
    setHandoffState({ storage_dir: storage }, 'migrating', {
      backup_snapshot: path.join(storage, 'update-backups', 'patch_fixture'),
      db_recovery: { backup_path: path.join(storage, 'update-backups', 'migration-safety', 'fixture.sql'), recovery_id: 'fixture' },
      migration_started: true,
    });

    const state = JSON.parse(fs.readFileSync(path.join(storage, 'update-state.json'), 'utf8'));
    assert.equal(state.state, 'migrating');
    assert.equal(state.migration_started, true);
    assert.equal(state.db_recovery.recovery_id, 'fixture');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
