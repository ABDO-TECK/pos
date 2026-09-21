const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const LOCK_TTL_MS = 300_000;
const ACTIVE_UPDATE_STATES = new Set([
  'backing_up',
  'downloading',
  'verifying',
  'applying',
  'migrating',
  'desktop_handoff_pending',
  'full_ready_to_install',
  'installing',
]);

function getCoordinationPaths(storageDir) {
  return {
    lockPath: path.join(storageDir, 'update-operation.lock'),
    statePath: path.join(storageDir, 'update-state.json'),
  };
}

function readJson(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return null;
  }
}

function ownerAgeMs(owner) {
  if (Number.isFinite(Number(owner?.time))) {
    return Math.max(0, Date.now() - (Number(owner.time) * 1000));
  }
  const updatedAt = Date.parse(owner?.updated_at || '');
  return Number.isFinite(updatedAt) ? Math.max(0, Date.now() - updatedAt) : LOCK_TTL_MS + 1;
}

function safeOwner(owner) {
  return {
    owner_id: String(owner?.owner_id || ''),
    operation: String(owner?.operation || 'unknown'),
    pid: Number.isFinite(Number(owner?.pid)) ? Number(owner.pid) : null,
    phase: owner?.phase ? String(owner.phase) : null,
    time: Number.isFinite(Number(owner?.time)) ? Number(owner.time) : null,
    updated_at: owner?.updated_at ? String(owner.updated_at) : null,
    context: owner?.context && typeof owner.context === 'object' ? owner.context : {},
    age_seconds: Math.floor(ownerAgeMs(owner) / 1000),
  };
}

function acquireUpdateLock(storageDir, operation, context = {}, options = {}) {
  const ttlMs = Number.isFinite(options.ttlMs) ? Math.max(1, options.ttlMs) : LOCK_TTL_MS;
  const { lockPath } = getCoordinationPaths(storageDir);
  fs.mkdirSync(storageDir, { recursive: true });
  const ownerId = `${process.pid}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const payload = {
    owner_id: ownerId,
    operation,
    pid: process.pid,
    time: Math.floor(Date.now() / 1000),
    started_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    context,
  };
  let recoveredFrom = null;

  for (let attempt = 0; attempt < 2; attempt += 1) {
    let fd;
    try {
      fd = fs.openSync(lockPath, 'wx');
      fs.writeFileSync(fd, `${JSON.stringify(payload)}\n`, 'utf8');
      fs.closeSync(fd);
      const lease = { acquired: true, owner_id: ownerId, operation, lock_path: lockPath };
      if (recoveredFrom) lease.recovered_from = safeOwner(recoveredFrom);
      return lease;
    } catch (error) {
      if (fd !== undefined) {
        try { fs.closeSync(fd); } catch { /* best effort */ }
      }
      if (error.code !== 'EEXIST') {
        return {
          acquired: false,
          reason_code: 'update_lock_unavailable',
          message: 'The shared update coordination lock could not be created.',
          owner: null,
        };
      }
    }

    const owner = readJson(lockPath);
    if (owner && ownerAgeMs(owner) <= ttlMs) {
      return {
        acquired: false,
        reason_code: 'update_in_progress',
        message: 'Another update or sale operation is in progress.',
        owner: safeOwner(owner),
      };
    }
    if (!owner && fs.existsSync(lockPath)) {
      const ageMs = Date.now() - (fs.statSync(lockPath).mtimeMs || 0);
      if (ageMs <= ttlMs) {
        return {
          acquired: false,
          reason_code: 'update_lock_unavailable',
          message: 'The shared update coordination lock is unreadable.',
          owner: null,
        };
      }
    }

    const stalePath = `${lockPath}.stale-${process.pid}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    try {
      fs.renameSync(lockPath, stalePath);
      try { fs.unlinkSync(stalePath); } catch { /* best effort cleanup */ }
      recoveredFrom = owner;
    } catch (error) {
      // Another process may have claimed or quarantined the stale file after
      // our read. Retry the atomic create before reporting a failure, never
      // unlinking a newer owner.
      if (error.code === 'ENOENT') continue;
      return {
        acquired: false,
        reason_code: 'update_lock_unavailable',
        message: 'The stale shared update coordination lock could not be recovered.',
        owner: owner ? safeOwner(owner) : null,
      };
    }
  }

  return {
    acquired: false,
    reason_code: 'update_lock_unavailable',
    message: 'The shared update coordination lock could not be acquired.',
    owner: null,
  };
}

function releaseUpdateLock(lease) {
  if (!lease?.acquired || !lease.owner_id || !lease.lock_path) return false;
  const owner = readJson(lease.lock_path);
  if (!owner || owner.owner_id !== lease.owner_id) return false;
  try {
    fs.unlinkSync(lease.lock_path);
    return true;
  } catch {
    return false;
  }
}

function heartbeatUpdateLock(lease, phase, context = {}) {
  if (!lease?.acquired || !lease.owner_id || !lease.lock_path) return false;
  const owner = readJson(lease.lock_path);
  if (!owner || owner.owner_id !== lease.owner_id) return false;
  const payload = {
    ...owner,
    phase,
    ...context,
    time: Math.floor(Date.now() / 1000),
    updated_at: new Date().toISOString(),
  };
  try {
    fs.writeFileSync(lease.lock_path, `${JSON.stringify(payload)}\n`, 'utf8');
    return true;
  } catch {
    return false;
  }
}

function readUpdateState(storageDir) {
  const { statePath } = getCoordinationPaths(storageDir);
  const state = readJson(statePath);
  return state && typeof state === 'object' ? state : null;
}

function writeUpdateState(storageDir, state, context = {}) {
  const { statePath } = getCoordinationPaths(storageDir);
  fs.mkdirSync(storageDir, { recursive: true });
  const current = readUpdateState(storageDir) || {};
  const payload = {
    ...current,
    ...context,
    state,
    started_at: current.started_at || new Date().toISOString(),
    updated_at: new Date().toISOString(),
  };
  const temporary = `${statePath}.${process.pid}.${Date.now()}.tmp`;
  fs.writeFileSync(temporary, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  try {
    fs.renameSync(temporary, statePath);
  } catch (error) {
    if (error.code !== 'EEXIST' && error.code !== 'EPERM') throw error;
    try { fs.unlinkSync(statePath); } catch { /* destination may not exist */ }
    fs.renameSync(temporary, statePath);
  }
  return payload;
}

function isActiveUpdateState(state, now = Date.now(), ttlMs = LOCK_TTL_MS) {
  if (!state || !ACTIVE_UPDATE_STATES.has(state.state)) return false;
  const updatedAt = Date.parse(state.updated_at || '');
  if (!Number.isFinite(updatedAt)) return true;
  return Math.max(0, now - updatedAt) <= ttlMs;
}

module.exports = {
  ACTIVE_UPDATE_STATES,
  LOCK_TTL_MS,
  acquireUpdateLock,
  getCoordinationPaths,
  heartbeatUpdateLock,
  isActiveUpdateState,
  readUpdateState,
  releaseUpdateLock,
  writeUpdateState,
};
