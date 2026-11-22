import test from 'node:test';
import assert from 'node:assert/strict';
import {SitClient, listItems} from './client.mjs';
import {createFixture, createListedFixture} from './fixture.mjs';

const credentials = {merchantId: '12345678-1234-1234-1234-123456789012', username: 'test', serviceKey: 'fake', userAgent: 'test_dev'};
test('All reads and creation are pinned to SIT, without redirect or production fallback', async () => {
  const calls = [];
  const client = new SitClient(credentials, async (url, options) => { calls.push({url, options}); return {status: 200, body: '[]'}; });
  for (const kind of ['open', 'packages', 'cancelled', 'shipped', 'delivered']) await client.list(kind);
  await client.create(createFixture(credentials.merchantId, '9912345678'));
  assert.equal(calls.length, 6);
  assert.ok(calls.slice(0, 5).every(c => new URL(c.url).hostname === 'oms-external-sit.hepsiburada.com' && c.options.method === 'GET'));
  assert.equal(new URL(calls[5].url).hostname, 'oms-stub-external-sit.hepsiburada.com');
  await assert.rejects(client.list('https://example.test'));
  await assert.rejects(client.list('open', -1));
  await assert.rejects(client.create(createFixture(credentials.merchantId, '9912345678')));
  assert.equal(calls.length, 6);
});
test('Reads respect bounded retry while authorization and writes are not retried', async () => {
  let calls = 0; const waits = [];
  const client = new SitClient(credentials, async () => ({status: ++calls === 1 ? 429 : 200, headers: {'retry-after': '3'}, body: '[]'}), async delay => waits.push(delay));
  assert.equal((await client.list('open')).status, 200);
  assert.deepEqual(waits, [3000]);
  for (const status of [401, 403, 302]) {
    let attempts = 0;
    const reader = new SitClient(credentials, async () => { attempts++; return {status, body: '{}'}; });
    assert.equal((await reader.list('open')).status, status); assert.equal(attempts, 1);
  }
  let writes = 0;
  const writer = new SitClient(credentials, async () => { writes++; return {status: 503, body: '{}'}; });
  await writer.create(createFixture(credentials.merchantId, '9912345678')); assert.equal(writes, 1);
});
test('Unsafe identity, non-synthetic fixture and unknown response contracts are rejected', async () => {
  assert.throws(() => new SitClient({...credentials, merchantId: '../production'}));
  assert.throws(() => new SitClient({...credentials, userAgent: 'test\r\nHeader: injected'}));
  const client = new SitClient(credentials, () => { throw new Error('Network must not run'); });
  await assert.rejects(client.create({...createFixture(credentials.merchantId, '9912345678'), Customer: {Name: 'Real customer'}}));
  assert.throws(() => listItems({unexpected: []}));
  assert.throws(() => listItems({totalCount: 1, items: null}));
  assert.deepEqual(listItems({totalCount: 0, items: null}), []);
  assert.deepEqual(listItems({items: []}), []);
  assert.throws(() => createListedFixture(credentials.merchantId, '9912345678', {isSalable: false}));
});

test('Lifecycle mutations reject unrelated orders and packages before any request', async () => {
  const calls = [];
  const client = new SitClient(credentials, async (url, options) => { calls.push({url, options}); return {status: 200, body: '{}'}; });
  const line = {id: '87654321-1234-1234-1234-123456789012', merchantId: credentials.merchantId,
    orderNumber: '9912345678', customerName: 'OMNIGO SIT TEST', quantity: 2, canCreatePackage: true, isCancellable: true};
  await assert.rejects(client.packageOwned({...line, orderNumber: 'other'}, line.orderNumber));
  await assert.rejects(client.cancelOwned({...line, merchantId: 'other'}, line.orderNumber));
  await assert.rejects(client.cancelOwned({...line, customerName: 'Real Customer'}, line.orderNumber));
  await assert.rejects(client.unpackOwned({packageNumber: '1234', recipientName: 'OMNIGO SIT TEST', items: [{orderNumber: 'other'}]}, line.orderNumber));
  assert.equal(calls.length, 0);
  await client.packageOwned(line, line.orderNumber);
  await client.cancelOwned(line, line.orderNumber);
  assert.equal(calls.length, 2);
  assert.ok(calls.every(call => new URL(call.url).hostname === 'oms-external-sit.hepsiburada.com' && call.options.method === 'POST'));
});
