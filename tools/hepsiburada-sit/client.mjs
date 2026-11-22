import https from 'node:https';

const origin = 'https://oms-external-sit.hepsiburada.com';
const stubOrigin = 'https://oms-stub-external-sit.hepsiburada.com';
const routes = Object.freeze({
  listings: '/listings/merchantid/',
  open: '/orders/merchantid/', cancelled: '/orders/merchantid/',
  packages: '/packages/merchantid/', shipped: '/packages/merchantid/',
  delivered: '/packages/merchantid/',
});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

export class SitFailure extends Error {
  constructor(status, retryAfter = 0) {
    super(`SIT kontrolü başarısız (${status}).`);
    this.status = status;
    this.retryAfter = retryAfter;
  }
}

function request(url, options, body) {
  return new Promise((resolve, reject) => {
    // No redirects, no arbitrary hosts, default certificate verification remains enabled.
    const req = https.request(url, options, res => {
      let size = 0;
      const chunks = [];
      res.on('data', chunk => {
        size += chunk.length;
        if (size > 4 * 1024 * 1024) res.destroy(new SitFailure(502));
        else chunks.push(chunk);
      });
      res.on('error', () => reject(new SitFailure(503)));
      res.on('end', () => resolve({status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks).toString('utf8')}));
    });
    req.setTimeout(20000, () => req.destroy(new SitFailure(503)));
    req.on('error', () => reject(new SitFailure(503)));
    req.end(body);
  });
}

export class SitClient {
  #credentials;
  #transport;
  #sleep;
  #createAttempted = false;
  constructor(credentials, transport = request, sleep = pause) {
    const {merchantId, username, serviceKey, userAgent} = credentials;
    if (!/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(merchantId ?? '')
      || [username, serviceKey, userAgent].some(v => typeof v !== 'string' || !/^[\x21-\x7e]{1,2048}$/.test(v))
      || username.includes(':')) throw new SitFailure('invalid_credentials');
    this.#credentials = {merchantId, username, serviceKey, userAgent};
    this.#transport = transport;
    this.#sleep = sleep;
  }
  async list(kind, offset = 0) {
    if (!Object.hasOwn(routes, kind) || !Number.isSafeInteger(offset) || offset < 0 || offset > 1000) throw new SitFailure('invalid_route');
    const suffix = ['cancelled', 'shipped', 'delivered'].includes(kind) ? '/' + kind : '';
    const host = kind === 'listings' ? 'https://listing-external-sit.hepsiburada.com' : origin;
    return this.#send('GET', host + routes[kind] + this.#credentials.merchantId + suffix + `?offset=${offset}&limit=10`);
  }
  async create(fixture) {
    // An uncertain POST is never retried: reconcile with the recorded order number instead.
    if (this.#createAttempted) throw new SitFailure('duplicate_create_attempt');
    this.#createAttempted = true;
    if (!/^99\d{8}$/.test(fixture.OrderNumber ?? '') || fixture.Customer?.Name !== 'OMNIGO SIT TEST'
      || fixture.DeliveryAddress?.Email !== 'sit-test@example.invalid'
      || fixture.LineItems?.some(v => v.MerchantId !== this.#credentials.merchantId)) throw new SitFailure('invalid_fixture');
    return this.#send('POST', stubOrigin + '/orders/merchantId/' + this.#credentials.merchantId, fixture);
  }
  async packageOwned(line, expectedNumber) {
    this.#verifyOwnedLine(line, expectedNumber);
    if (line.canCreatePackage !== true) throw new SitFailure('not_packable');
    return this.#send('POST', origin + '/packages/merchantid/' + this.#credentials.merchantId,
      {lineItemRequests: [{id: line.id, quantity: line.quantity}]});
  }
  async cancelOwned(line, expectedNumber) {
    this.#verifyOwnedLine(line, expectedNumber);
    if (line.isCancellable !== true) throw new SitFailure('not_cancellable');
    return this.#send('POST', origin + '/lineitems/merchantid/' + this.#credentials.merchantId + '/id/' + line.id + '/cancelbymerchant', {reasonId: 0});
  }
  async unpackOwned(pack, expectedNumber) {
    if (!/^99\d{8}$/.test(expectedNumber) || !/^\d{1,30}$/.test(String(pack.packageNumber))
      || !Array.isArray(pack.items) || !pack.items.length
      || pack.items.some(line => String(line.orderNumber) !== expectedNumber)
      || !String(pack.recipientName ?? pack.customerName ?? '').includes('OMNIGO SIT TEST')) throw new SitFailure('foreign_package');
    return this.#send('POST', origin + '/packages/merchantid/' + this.#credentials.merchantId + '/packagenumber/' + pack.packageNumber + '/unpack', {});
  }
  #verifyOwnedLine(line, expectedNumber) {
    if (!/^99\d{8}$/.test(expectedNumber) || String(line.orderNumber) !== expectedNumber
      || line.merchantId !== this.#credentials.merchantId || !String(line.customerName).includes('OMNIGO SIT TEST')
      || !/^[a-f0-9-]{36}$/i.test(line.id) || !Number.isInteger(line.quantity) || line.quantity < 1) throw new SitFailure('foreign_order');
  }
  async #send(method, url, data) {
    const {username, serviceKey, userAgent} = this.#credentials;
    const headers = {Accept: 'application/json', 'User-Agent': userAgent, Authorization: 'Basic ' + Buffer.from(username + ':' + serviceKey).toString('base64')};
    const body = data ? JSON.stringify(data) : undefined;
    if (body) { headers['Content-Type'] = 'application/json'; headers['Content-Length'] = Buffer.byteLength(body); }
    for (let attempt = 0; attempt < 2; attempt++) {
      const response = await this.#transport(url, {method, headers}, body);
      const value = response.headers?.['retry-after'];
      const retryAfter = value && /^\d+$/.test(value) ? Number(value) : value ? Math.max(0, Math.ceil((Date.parse(value) - Date.now()) / 1000)) : 2;
      if (method === 'GET' && attempt === 0 && [429, 502, 503, 504].includes(response.status) && Number.isFinite(retryAfter) && retryAfter <= 5) {
        await this.#sleep(Math.max(1, retryAfter) * 1000);
        continue;
      }
      let parsed = null;
      try { parsed = response.body ? JSON.parse(response.body) : null; } catch { if (response.status < 300) throw new SitFailure(502); }
      return {status: response.status, retryAfter, data: parsed};
    }
    throw new SitFailure(503);
  }
}

export function listItems(data) {
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.items)) return data.items;
  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data?.listings)) return data.listings;
  // Observed SIT cancellation/shipping endpoints use null for an explicitly empty page.
  if (data?.totalCount === 0 && data?.items === null) return [];
  throw new SitFailure('unknown_response_contract');
}
