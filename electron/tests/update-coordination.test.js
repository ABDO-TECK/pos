const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  acquireUpdateLock,
  heartbeatUpdateLock,
  isActiveUpdateState,
  readUpdateState,
  releaseUpdateLock,
  writeUpdateState,
} = require('../utils/update-coordination');

test('shared coordination lock rejects an Electron download during backend apply', () => {
  const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-update-coordination-'));
  try {
    const backendLock = acquireUpdateLock(storage, 'backend_delta_apply', {
      target_version: '0.0.2',
    });
    assert.equal(backendLock.acquired, true);
    assert.equal(heartbeatUpdateLock(backendLock, 'downloading', { target_version: '0.0.2' }), true);

    const electronLock = acquireUpdateLock(storage, 'electron_full_download', {
      target_version: '0.0.2',
    });
    assert.equal(electronLock.acquired, false);
    assert.equal(electronLock.reason_code, 'update_in_progress');
    assert.equal(electronLock.owner.operation, 'backend_delta_apply');

    assert.equal(releaseUpdateLock(backendLock), true);
  } finally {
    fs.rmSync(storage, { recursive: true, force: true });
  }
});

test('Electron honors a current PHP-shaped shared lock owner', () => {
  const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-update-coordination-php-'));
  try {
    fs.writeFileSync(path.join(storage, 'update-operation.lock'), JSON.stringify({
      owner_id: 'php-owner',
      operation: 'backend_delta_apply',
      pid: 4321,
      time: Math.floor(Date.now() / 1000),
      started_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      context: { target_version: '0.0.2' },
    }));

    const electronLock = acquireUpdateLock(storage, 'electron_full_download');
    assert.equal(electronLock.acquired, false);
    assert.equal(electronLock.reason_code, 'update_in_progress');
    assert.equal(electronLock.owner.owner_id, 'php-owner');
    assert.equal(electronLock.owner.operation, 'backend_delta_apply');
  } finally {
    fs.rmSync(storage, { recursive: true, force: true });
  }
});

test('shared coordination recovers a stale owner and keeps the previous owner diagnostic', () => {
  const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-update-coordination-stale-'));
  try {
    fs.writeFileSync(path.join(storage, 'update-operation.lock'), JSON.stringify({
      owner_id: 'stale-owner',
      operation: 'backend_delta_apply',
      pid: 1234,
      time: Math.floor(Date.now() / 1000) - 301,
      updated_at: new Date(Date.now() - 301_000).toISOString(),
    }));

    const recovered = acquireUpdateLock(storage, 'electron_delta_install');
    assert.equal(recovered.acquired, true);
    assert.equal(recovered.recovered_from.owner_id, 'stale-owner');
    assert.equal(releaseUpdateLock(recovered), true);
  } finally {
    fs.rmSync(storage, { recursive: true, force: true });
  }
});

test('shared state marks active update operations and preserves recovery context', () => {
  const storage = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-update-state-'));
  try {
    writeUpdateState(storage, 'applying', {
      operation: 'backend_delta_apply',
      owner_id: 'owner-1',
      to_version: '0.0.2',
      backup_snapshot: 'snapshot-fixture',
    });

    const state = readUpdateState(storage);
    assert.equal(state.state, 'applying');
    assert.equal(state.operation, 'backend_delta_apply');
    assert.equal(state.backup_snapshot, 'snapshot-fixture');
    assert.equal(isActiveUpdateState(state), true);

    writeUpdateState(storage, 'rolled_back', { error: 'fixture failure' });
    assert.equal(isActiveUpdateState(readUpdateState(storage)), false);
  } finally {
    fs.rmSync(storage, { recursive: true, force: true });
  }
});
