import {spawnSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

export function selectSimulator(inventory) {
  const candidates = Object.entries(inventory.devices || {}).flatMap(([runtime, devices]) => {
    const version = runtime.match(/\.iOS-(\d+)-(\d+)(?:-(\d+))?$/);
    if (!version || Number(version[1]) < 26 || !Array.isArray(devices)) return [];
    return devices.filter(device => device.isAvailable === true && /^iPhone\b/.test(device.name)
      && /^[0-9A-F-]{36}$/i.test(device.udid || '')).map(device => ({
        ...device, runtime, version: Number(version[1]) * 10000 + Number(version[2]) * 100 + Number(version[3] || 0)
      }));
  }).sort((left, right) => right.version - left.version || left.name.localeCompare(right.name));
  if (!candidates.length) throw new Error('Kullanılabilir iOS 26 veya üzeri iPhone simülatörü bulunamadı.');
  return candidates[0];
}

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {stdio: 'inherit', timeout: 30 * 60 * 1000, ...options});
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`${command} işlemi başarısız oldu (kod: ${result.status}).`);
  return result;
}

function main() {
  const role = process.argv[2];
  if (!['client', 'admin'].includes(role)) throw new Error('Kullanım: node ios/cloud-verify.mjs client|admin');
  if (process.platform !== 'darwin') throw new Error('Bu doğrulama macOS ve Xcode üzerinde çalıştırılmalıdır.');
  const inventory = run('xcrun', ['simctl', 'list', 'devices', 'available', '--json'], {encoding: 'utf8', stdio: 'pipe'});
  const device = selectSimulator(JSON.parse(inventory.stdout));
  console.log(`Doğrulama: ${role} / ${device.name} / ${device.runtime}`);
  run('xcrun', ['simctl', 'bootstatus', device.udid, '-b'], {timeout: 10 * 60 * 1000});
  run(process.execPath, [path.join(import.meta.dirname, 'build.mjs'), role, 'verify'], {
    env: {...process.env, OMNIGO_IOS_SIMULATOR_ID: device.udid}
  });
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { main(); } catch (error) { console.error(error.message); process.exitCode = 1; }
}
