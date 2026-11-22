import fs from 'node:fs/promises';
import {listItems} from './client.mjs';

export async function runOwnedAction(client, state, operation, folder) {
  if (!state || state.createHttpStatus !== 201 || !['package', 'unpack', 'cancel'].includes(operation)) throw new Error('Doğrulanmış test siparişi gerekiyor.');
  const kind = operation === 'unpack' ? 'packages' : 'open';
  const response = await client.list(kind);
  if (response.status !== 200) throw new Error('Test siparişi doğrulanamadı.');
  const items = listItems(response.data);
  const owned = operation === 'unpack'
    ? items.find(pack => pack.items?.length && pack.items.every(line => String(line.orderNumber) === state.orderNumber))
    : items.find(line => String(line.orderNumber) === state.orderNumber);
  if (!owned) throw new Error('İlgili test siparişi henüz bu listede yok.');
  const journal = folder + '/' + operation + '-' + state.orderNumber + '.json';
  await fs.writeFile(journal, JSON.stringify({operation, attemptedAt: new Date().toISOString(), status: 'pending'}), {flag: 'wx'});
  const result = operation === 'package' ? await client.packageOwned(owned, state.orderNumber)
    : operation === 'unpack' ? await client.unpackOwned(owned, state.orderNumber)
      : await client.cancelOwned(owned, state.orderNumber);
  const summary = {operation, httpStatus: result.status, finishedAt: new Date().toISOString(),
    // Never persist raw error text or credentials from a mutation response.
    responseFields: result.data && typeof result.data === 'object' ? Object.keys(result.data) : []};
  await fs.writeFile(journal, JSON.stringify(summary, null, 2));
  return summary;
}
