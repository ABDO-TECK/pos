const assert = require('node:assert/strict');
const test = require('node:test');

const {
  assessReleaseCompatibility,
} = require('../utils/release-version-policy');

test('v0 clients reject v1.1.48 and v1.2.0 releases', () => {
  for (const candidate of ['1.1.48', '1.2.0']) {
    const result = assessReleaseCompatibility('0.0.1', candidate);
    assert.equal(result.compatible, false);
    assert.equal(result.reasonCode, 'legacy_generation');
  }
});

test('v0 clients accept a newer v0 release', () => {
  const result = assessReleaseCompatibility('0.0.1', '0.0.2');

  assert.deepEqual(result, {
    compatible: true,
    reasonCode: null,
    reason: null,
    currentVersion: '0.0.1',
    candidateVersion: '0.0.2',
  });
});

test('invalid release metadata is rejected', () => {
  const result = assessReleaseCompatibility('0.0.1', 'not-semver');

  assert.equal(result.compatible, false);
  assert.equal(result.reasonCode, 'invalid_version');
});
