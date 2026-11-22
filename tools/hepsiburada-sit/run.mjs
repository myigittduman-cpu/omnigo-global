import fs from 'node:fs/promises';
import {randomInt, createHash} from 'node:crypto';
import {SitClient, listItems} from './client.mjs';
import {createFixture, createListedFixture} from './fixture.mjs';
import {runOwnedAction} from './actions.mjs';

const folder = 'verification/hepsiburada-sit-2026-10-16';
const mode = process.argv[2] ?? 'inspect';
if (!['inspect', 'create', 'create-listed', 'package', 'unpack', 'cancel'].includes(mode)) throw new Error('Geçersiz kontrol modu.');
const scenario = process.argv[3] ?? 'default';
if (!['default', 'listed'].includes(scenario)) throw new Error('Geçersiz test senaryosu.');
const stateFile = folder + '/scenario-' + scenario + '.json';

async function secretInput() {
  if (!process.stdin.isTTY) throw new Error('Gizli giriş için etkileşimli terminal gerekiyor.');
  console.log('SIT API bilgileri gizli olarak bekleniyor.');
  process.stdin.setRawMode(true); process.stdin.setEncoding('utf8'); process.stdin.resume();
  return new Promise((resolve, reject) => {
    let input = '';
    const timer = setTimeout(() => finish(new Error('Giriş süresi doldu.')), 120000);
    function finish(error) {
      clearTimeout(timer); process.stdin.removeListener('data', onData);
      process.stdin.setRawMode(false); process.stdin.pause();
      try { if (error) reject(error); else resolve(JSON.parse(input.trim())); } catch { reject(new Error('Girdi geçersiz.')); }
      input = '';
    }
    function onData(chunk) {
      input += chunk;
      if (input.includes('\u0003') || input.length > 10000) finish(new Error('Giriş iptal edildi.'));
      else if (/[\r\n]/.test(input)) finish();
    }
    process.stdin.on('data', onData);
  });
}

const report = {environment: 'SIT', productionChanged: false, databaseConnected: false, checkedAt: new Date().toISOString(), checks: []};
try {
  const credentials = await secretInput();
  const client = new SitClient(credentials);
  const fingerprint = createHash('sha256').update(credentials.merchantId).digest('hex');
  await fs.mkdir(folder, {recursive: true});
  let state;
  try { state = JSON.parse(await fs.readFile(stateFile, 'utf8')); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  if (state && state.merchantFingerprint !== fingerprint) throw new Error('Test hesabı uyuşmuyor.');
  if (mode.startsWith('create')) {
    if (state) throw new Error('Sipariş oluşturma daha önce denendi; yalnız inceleme kullanılmalı.');
    state = {merchantFingerprint: fingerprint, orderNumber: '99' + String(randomInt(10000000, 99999999)), attemptedAt: new Date().toISOString()};
    // Durable intent before POST; restarting never sends a second create for an uncertain result.
    await fs.writeFile(stateFile, JSON.stringify(state, null, 2), {flag: 'wx'});
    let fixture = createFixture(credentials.merchantId, state.orderNumber);
    if (mode === 'create-listed') {
      const listings = await client.list('listings');
      if (listings.status !== 200) throw new Error('Test ürün listesi okunamadı.');
      fixture = createListedFixture(credentials.merchantId, state.orderNumber,
        listItems(listings.data).find(item => item.isSalable && item.availableStock >= 2 && item.price > 0));
    }
    const result = await client.create(fixture);
    report.checks.push({operation: 'create_synthetic_order', httpStatus: result.status});
    state.createHttpStatus = result.status;
    await fs.writeFile(stateFile, JSON.stringify(state, null, 2));
    // Only diagnostic text, with credentials removed, may be persisted for this synthetic request.
    const safe = JSON.stringify(result.data).replaceAll(credentials.serviceKey, '[redacted]').replaceAll(credentials.username, '[merchant]').replaceAll(credentials.userAgent, '[agent]');
    await fs.writeFile(folder + '/create-' + scenario + '-response.json', safe);
  }
  if (['package', 'unpack', 'cancel'].includes(mode)) report.checks.push(await runOwnedAction(client, state, mode, folder));
  for (const kind of ['open', 'packages', 'cancelled', 'shipped', 'delivered']) {
    const result = await client.list(kind);
    const check = {operation: kind, httpStatus: result.status};
    if (result.status === 200) {
      check.totalCount = result.data?.totalCount ?? null;
      check.itemsType = result.data?.items === null ? 'null' : typeof result.data?.items;
      let items;
      try { items = listItems(result.data); }
      catch {
        check.contract = result.data === null ? 'null' : typeof result.data;
        check.fields = result.data && typeof result.data === 'object' ? Object.keys(result.data) : [];
        check.contractValidated = false;
        report.checks.push(check);
        continue;
      }
      check.sampleCount = items.length;
      check.totalCount = result.data?.totalCount ?? null;
      if (kind === 'listings') {
        const fields = ['hepsiburadaSku', 'merchantSku', 'listingId', 'price', 'availableStock', 'isSalable', 'cargoCompany1'];
        check.firstItemFields = items[0] ? Object.keys(items[0]) : [];
        await fs.writeFile(folder + '/test-listings.json', JSON.stringify(items.map(item => Object.fromEntries(fields.filter(key => Object.hasOwn(item, key)).map(key => [key, item[key]]))), null, 2));
      }
      const own = state ? items.filter(item => String(item.orderNumber) === state.orderNumber
        || item.items?.some(line => String(line.orderNumber) === state.orderNumber)) : [];
      check.ownedTestRecords = own.length;
      if (own.length) await fs.writeFile(folder + '/owned-' + kind + '.json', JSON.stringify(own, null, 2));
    }
    report.checks.push(check);
  }
} catch (error) {
  report.error = error.status ?? 'local_check_failed';
  process.exitCode = 1;
}
await fs.mkdir(folder, {recursive: true});
await fs.writeFile(folder + '/' + mode + '-report.json', JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
