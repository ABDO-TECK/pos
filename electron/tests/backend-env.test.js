const assert = require('node:assert/strict');
const test = require('node:test');

const {
  resolveBackendRuntimeConfig,
} = require('../services/php-server');

test('packaged desktop backend processes use production configuration', () => {
  const result = resolveBackendRuntimeConfig({
    packaged: true,
    isLanDeployment: false,
    environment: {},
  });

  assert.deepEqual(result, {
    ENABLE_AUTO_UPDATE: 'true',
    APP_ENV: 'production',
    APP_DEBUG: 'false',
    DEPLOYMENT_MODE: 'desktop',
  });
});

test('unpackaged desktop backend processes retain development configuration', () => {
  const result = resolveBackendRuntimeConfig({
    packaged: false,
    isLanDeployment: false,
    environment: {},
  });

  assert.deepEqual(result, {
    ENABLE_AUTO_UPDATE: 'false',
    APP_ENV: 'development',
    APP_DEBUG: 'true',
    DEPLOYMENT_MODE: 'desktop',
  });
});

test('LAN deployment keeps explicit environment while remaining production-safe', () => {
  const result = resolveBackendRuntimeConfig({
    packaged: false,
    isLanDeployment: true,
    environment: { APP_ENV: 'staging', APP_DEBUG: 'false', ENABLE_AUTO_UPDATE: 'true' },
  });

  assert.deepEqual(result, {
    ENABLE_AUTO_UPDATE: 'true',
    APP_ENV: 'staging',
    APP_DEBUG: 'false',
    DEPLOYMENT_MODE: 'lan',
  });
});
