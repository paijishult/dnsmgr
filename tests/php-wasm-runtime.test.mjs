import assert from 'node:assert/strict';
import test from 'node:test';
import { loadCheckedPHPRuntime } from './php-wasm-runtime.mjs';

function fixture(actual) {
  const calls = [];
  let closed = false;
  class PHP {
    async run() { return { text: actual, exitCode: 0 }; }
    exit() { closed = true; }
  }
  return {
    options: { PHP, loadNodeRuntime: async series => { calls.push(series); return 1; } },
    calls,
    closed: () => closed,
  };
}

test('exact patch requests use a supported loader series and check the binary', async () => {
  const f = fixture('8.3.36');
  await loadCheckedPHPRuntime({ ...f.options, requested: '8.3.36' });
  assert.deepEqual(f.calls, ['8.3']);
  assert.equal(f.closed(), false);
});

test('older cached binaries are rejected and closed for exact and series requests', async () => {
  for (const requested of ['8.3.36', '8.3']) {
    const f = fixture('8.3.0');
    await assert.rejects(loadCheckedPHPRuntime({ ...f.options, requested }), /Update the @php-wasm packages/);
    assert.equal(f.closed(), true);
  }
});

test('an exact version cannot silently use a different patch', async () => {
  const f = fixture('8.3.37');
  await assert.rejects(loadCheckedPHPRuntime({ ...f.options, requested: '8.3.36' }), /installed WASM provides 8.3.37/);
  assert.equal(f.closed(), true);
});

test('series overrides remain available for compatibility checks', async () => {
  const f = fixture('8.4.27');
  await loadCheckedPHPRuntime({ ...f.options, requested: '8.4' });
  assert.deepEqual(f.calls, ['8.4']);
});
