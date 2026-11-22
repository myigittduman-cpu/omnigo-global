// Only the chosen login identifier is remembered, never a password or session token.
const identityKey = 'omnigo.remembered-login.v2';
const legacyKey = 'omnigo.remembered-email.v1';

function validIdentity(value) {
  return value && ['email', 'username', 'auto'].includes(value.mode) &&
    typeof value.value === 'string' && value.value.trim().length > 0 &&
    value.value.length <= (value.mode === 'email' ? 200 : 1024);
}

export function readRememberedLogin(storage) {
  try {
    storage ??= globalThis.localStorage;
    const raw = storage.getItem(identityKey);
    if (raw) {
      const identity = JSON.parse(raw);
      return validIdentity(identity) ? {mode: identity.mode, value: identity.value.trim()} : null;
    }
    const email = storage.getItem(legacyKey);
    return validIdentity({mode: 'email', value: email}) ? {mode: 'email', value: email.trim()} : null;
  } catch { return null; }
}

export function clearRememberedLogin(storage) {
  try { storage ??= globalThis.localStorage; storage.removeItem(identityKey); storage.removeItem(legacyKey); } catch {}
}

export function writeRememberedLogin(identity, storage) {
  if (!validIdentity(identity)) { clearRememberedLogin(storage); return; }
  try {
    storage ??= globalThis.localStorage;
    storage.setItem(identityKey, JSON.stringify({mode: identity.mode, value: identity.value.trim()}));
    storage.removeItem(legacyKey);
  } catch {}
}
