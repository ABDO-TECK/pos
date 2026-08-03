const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const mainSource = fs.readFileSync(
  path.join(__dirname, '..', 'main.js'),
  'utf8',
);

test('second application launch wakes a hidden tray window', () => {
  const handler = mainSource.match(
    /app\.on\('second-instance'[\s\S]*?\n\}\);/,
  );

  assert.ok(handler, 'second-instance handler is missing');
  assert.match(handler[0], /mainWindow\.isDestroyed\(\)/);
  assert.match(handler[0], /mainWindow\.isVisible\(\)/);
  assert.match(handler[0], /mainWindow\.show\(\)/);
  assert.match(handler[0], /mainWindow\.focus\(\)/);
});
