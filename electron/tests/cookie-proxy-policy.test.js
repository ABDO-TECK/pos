const assert = require('node:assert/strict');
const test = require('node:test');

const {
  isTrustedInitiator,
  isTrustedBackendRequest,
  getCookieHeader,
} = require('../utils/cookie-proxy-policy');

test('cookie proxy trusts only the exact POS app origin', () => {
  assert.equal(isTrustedInitiator('app://pos-app'), true);
  assert.equal(isTrustedInitiator('app://pos-app/'), true);
  assert.equal(isTrustedInitiator('app://pos-app/index.html'), true);
  assert.equal(isTrustedInitiator('app://evil'), false);
  assert.equal(isTrustedInitiator('app://pos-app.evil/index.html'), false);
  assert.equal(isTrustedInitiator('app://pos-app:443/index.html'), false);
  assert.equal(isTrustedInitiator(''), false);
  assert.equal(isTrustedInitiator(undefined), false);
});

test('cookie proxy requires the exact local PHP backend URL and trusted origin', () => {
  const trustedRequest = {
    url: 'http://127.0.0.1:8080/api/v1/products',
    phpPort: 8080,
    initiator: 'app://pos-app',
    webContentsId: 42,
    trustedWebContentsId: 42,
    frameUrl: 'app://pos-app/index.html',
  };

  assert.equal(isTrustedBackendRequest(trustedRequest), true);
  assert.equal(isTrustedBackendRequest({
    ...trustedRequest,
    initiator: 'app://evil',
  }), false);
  assert.equal(isTrustedBackendRequest({
    ...trustedRequest,
    url: 'http://127.0.0.1:8081/api/v1/products',
  }), false);
  assert.equal(isTrustedBackendRequest({
    ...trustedRequest,
    url: 'https://127.0.0.1:8080/api/v1/products',
  }), false);
  assert.equal(isTrustedBackendRequest({
    ...trustedRequest,
    url: 'http://example.test:8080/api/v1/products',
  }), false);
  assert.equal(isTrustedBackendRequest({
    ...trustedRequest,
    webContentsId: 43,
  }), false);
});

test('cookie proxy accepts a missing initiator only for the trusted app webContents', () => {
  const request = {
    url: 'http://127.0.0.1:8080/api/v1/login',
    phpPort: 8080,
    initiator: '',
    webContentsId: 42,
    trustedWebContentsId: 42,
    frameUrl: 'app://pos-app/index.html',
  };

  assert.equal(isTrustedBackendRequest(request), true);
  assert.equal(isTrustedBackendRequest({ ...request, webContentsId: 43 }), false);
  assert.equal(isTrustedBackendRequest({ ...request, initiator: 'app://evil' }), false);
  assert.equal(isTrustedBackendRequest({ ...request, trustedWebContentsId: null }), false);
});

test('matching webContentsId does not authorize an opaque or foreign subframe', () => {
  const request = {
    url: 'http://127.0.0.1:8080/api/v1/products',
    phpPort: 8080,
    initiator: '',
    webContentsId: 42,
    trustedWebContentsId: 42,
    frameUrl: 'app://pos-app/index.html',
  };

  assert.equal(isTrustedBackendRequest({
    ...request,
    frameUrl: 'data:text/html,<img>',
  }), false);
  assert.equal(isTrustedBackendRequest({
    ...request,
    frameUrl: 'http://attacker.test/frame',
  }), false);
  assert.equal(isTrustedBackendRequest({
    ...request,
    frameUrl: null,
  }), false);
});

test('cookie proxy respects the refresh cookie path', () => {
  const cookies = {
    pos_token: 'access-token',
    pos_refresh_token: 'refresh-token',
    'XSRF-TOKEN': 'csrf-nonce',
  };

  assert.equal(
    getCookieHeader(cookies, '/api/v1/products'),
    'pos_token=access-token; XSRF-TOKEN=csrf-nonce'
  );
  assert.equal(
    getCookieHeader(cookies, '/api/v1/refresh'),
    'pos_token=access-token; pos_refresh_token=refresh-token; XSRF-TOKEN=csrf-nonce'
  );
  assert.equal(
    getCookieHeader(cookies, '/api/refresh'),
    'pos_token=access-token; XSRF-TOKEN=csrf-nonce'
  );
});
