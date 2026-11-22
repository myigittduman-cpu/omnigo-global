// Isolated Store packaging: no deploy, no production database, no installer signing keys.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const { createManifest } = require('./manifest.cjs');
const { prepareIconResources } = require('./icon-resources.cjs');
const { createBundle } = require('./bundle.cjs');
const desktop = path.resolve(__dirname, '../..');
const root = path.dirname(desktop);
const cli = require(path.join(root, 'work/tauri-cli/js/package/main.js'));
const config = JSON.parse(fs.readFileSync(path.join(desktop, 'tauri.conf.json'), 'utf8'));
const identities = require('./identities.json');
const clientConfig = require('./client.conf.json');
// ring requires Clang on PATH when compiling the Windows ARM64 target.
const targets = {
  x86: { triple: 'i686-pc-windows-msvc', machine: 0x014c },
  x64: { triple: 'x86_64-pc-windows-msvc', machine: 0x8664 },
  arm64: { triple: 'aarch64-pc-windows-msvc', machine: 0xaa64 },
};
const privateAdmin = process.argv.includes('--private-admin');
const archiveAdmin = process.argv.includes('--archive-admin');
if(archiveAdmin&&(privateAdmin||process.argv[2]!=='admin'))throw new Error('Arşiv paketi yalnız eski yönetici kaydına hazırlanabilir.');
if(privateAdmin && process.argv[2]!=='admin')throw new Error('Özel mağaza paketi yalnız yönetici için hazırlanabilir.');
const outputRoot = path.resolve(root, '../outputs/Omnigo-Stores',archiveAdmin?'windows-msix-archive':privateAdmin?'windows-msix-private':'windows-msix');
const makeAppx = 'C:/Program Files (x86)/Windows Kits/10/bin/10.0.26100.0/x64/makeappx.exe';

function verifyExecutable(executable, architecture) {
  const bytes = fs.readFileSync(executable);
  const header = bytes.length >= 64 ? bytes.readUInt32LE(0x3c) : bytes.length;
  if (header > bytes.length - 6 || bytes.readUInt16LE(0) !== 0x5a4d ||
      bytes.readUInt32LE(header) !== 0x4550 || bytes.readUInt16LE(header + 4) !== targets[architecture].machine) {
    throw new Error(`Çalıştırılabilir dosyanın PE mimarisi ${architecture} ile eşleşmiyor.`);
  }
}

function writeChecksum(packagePath) {
  const hash = crypto.createHash('sha256').update(fs.readFileSync(packagePath)).digest('hex');
  fs.writeFileSync(`${packagePath}.sha256`, `${hash}  ${path.basename(packagePath)}\n`);
  return hash;
}

async function build(role, architecture, version) {
  const target = targets[architecture].triple;
  const output = path.join(outputRoot, version);
  const identity = privateAdmin ? require('./private-admin.json') : archiveAdmin?{...identities.admin,displayName:'Omnigo Global Yönetim Arşivi'}:identities[role];
  const args = ['build', '--target', target, '--no-bundle', '--ci', '--features', 'microsoft-store'];
  if (role === 'admin') args.push('--config', 'admin.conf.json');
  if (privateAdmin) {
    const adminConfig = require(path.join(desktop, 'admin.conf.json'));
    args.push('--config', JSON.stringify({
      productName: identity.launcherName,
      app: { windows: adminConfig.app.windows.map(window => ({ ...window, title: identity.launcherName })) },
    }));
  }
  args.push('--config', 'windows/store/store.conf.json');
  if (role === 'client') args.push('--config', 'windows/store/client.conf.json');
  args.push('--', '--offline', '--locked');
  process.chdir(desktop);
  await cli.run(args, 'tauri');
  const executable = path.join(desktop, 'target', target, 'release/omnigo-desktop.exe');
  verifyExecutable(executable, architecture);
  const stage = role === 'client' ? path.join(output, role, architecture, 'package') : path.join(output, role, 'package');
  fs.mkdirSync(path.join(stage, 'Assets'), { recursive: true });
  fs.copyFileSync(executable, path.join(stage, 'omnigo-desktop.exe'));
  fs.copyFileSync(path.join(desktop, 'icons/icon.png'), path.join(stage, 'notification-icon.png'));
  fs.copyFileSync(path.join(root, 'public/assets/notification-chime-v2.wav'), path.join(stage, 'notification-chime.wav'));
  fs.mkdirSync(path.join(stage, 'Simgeler'), { recursive: true });
  fs.copyFileSync(path.join(desktop, 'icons', role, 'icon.ico'), path.join(stage, 'Simgeler/Omnigo-Klasik.ico'));
  fs.copyFileSync(path.join(desktop, 'windows/icon-options.txt'), path.join(stage, 'Simgeler/Beni-Oku.txt'));
  for (const name of ['StoreLogo.png','Square150x150Logo.png','Square44x44Logo.png']) {
    fs.copyFileSync(path.join(desktop, 'icons', role, name), path.join(stage, 'Assets', name));
  }
  const manifest = createManifest(identity, identities.publisher, identities.publisherDisplayName, version, {
    architecture,
    windows10: role === 'client',
  });
  fs.writeFileSync(path.join(stage, 'AppxManifest.xml'), manifest);
  await prepareIconResources({root,stage,cli,role});
  const packagePath = path.join(output, `${identity.launcherName}-${version}-${architecture}.msix`);
  const result = spawnSync(makeAppx, ['pack', '/d', stage, '/p', packagePath, '/o'], { encoding: 'utf8', windowsHide: true });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(result.stdout + result.stderr);
  const hash = writeChecksum(packagePath);
  console.log(JSON.stringify({ role, architecture, version, storeId: identity.storeId, packagePath, sha256: hash, signed: false, signing: 'Microsoft Store imzalar.' }));
  return packagePath;
}
(async () => {
  for (const role of (process.argv[2] ? [process.argv[2]] : ['client', 'admin'])) {
    if (!['client', 'admin'].includes(role)) throw new Error('Uygulama türü geçersiz.');
    const version = `${role === 'client' ? clientConfig.version : config.version}.0`;
    const architectures = role === 'client' ? Object.keys(targets) : ['x64'];
    const packages = [];
    for (const architecture of architectures) {
      packages.push(await build(role, architecture, version));
    }
    if (role === 'client') {
      const bundlePath = path.join(outputRoot, version, `${identities.client.launcherName}-${version}.msixbundle`);
      createBundle({ makeAppx, packages, bundlePath, version });
      console.log(JSON.stringify({ bundlePath, version, architectures, sha256: writeChecksum(bundlePath) }));
    }
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
