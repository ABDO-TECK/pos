const assert = require('node:assert/strict');
const test = require('node:test');

const { buildProxyHeaders, resolveListenHost } = require('../services/https-proxy');

test('proxy overwrites client-supplied forwarding headers', () => {
  const headers = buildProxyHeaders({
    host: '192.168.1.10:8443',
    forwarded: 'for=203.0.113.2;proto=http',
    'x-forwarded-for': '203.0.113.3',
    'x-real-ip': '203.0.113.4',
  }, '192.168.1.25', 8443);

  assert.equal(headers.host, '192.168.1.10:8443');
  assert.equal(headers['x-forwarded-for'], '192.168.1.25');
  assert.equal(headers['x-real-ip'], '192.168.1.25');
  assert.equal(headers['x-forwarded-proto'], 'https');
  assert.equal(headers['x-forwarded-port'], '8443');
  assert.equal(headers.forwarded, undefined);
});

test('proxy fails closed when socket address is invalid', () => {
  const headers = buildProxyHeaders({
    'x-forwarded-for': '203.0.113.3',
  }, 'not-an-ip', 8443);

  assert.equal(headers['x-forwarded-for'], 'unknown');
  assert.equal(headers['x-real-ip'], 'unknown');
});

test('proxy stays loopback-only unless LAN access is explicitly enabled', () => {
  const previous = process.env.POS_LAN_ENABLED;
  try {
    delete process.env.POS_LAN_ENABLED;
    assert.equal(resolveListenHost(), '127.0.0.1');
    assert.equal(resolveListenHost('127.0.0.1'), '127.0.0.1');

    process.env.POS_LAN_ENABLED = 'true';
    assert.equal(resolveListenHost(), '0.0.0.0');
    assert.equal(resolveListenHost('0.0.0.0'), '0.0.0.0');
  } finally {
    if (previous === undefined) delete process.env.POS_LAN_ENABLED;
    else process.env.POS_LAN_ENABLED = previous;
  }
});
