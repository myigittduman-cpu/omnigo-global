// Build both isolated update channels. Never copy signing keys into published output.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
const cli = require('../work/tauri-cli/js/package/main.js');
const config = JSON.parse(fs.readFileSync(path.join(__dirname, 'tauri.conf.json'), 'utf8').replace(/^\uFEFF/, ''));
const version = config.version;
const staging = path.join(root, 'deploy', `desktop-${version}`, 'public', 'updates');
const output = path.resolve(root, '..', 'outputs', 'Omnigo-PC-Kurulum');
async function build(channel, name, filename) {
  process.env.TAURI_SIGNING_PRIVATE_KEY = fs.readFileSync(path.join(root, 'data', 'desktop-signing', `${channel}.key`), 'utf8').trim();
  process.env.TAURI_SIGNING_PRIVATE_KEY_PASSWORD = '';
  const args = ['build', '--target', 'x86_64-pc-windows-msvc', '--ci'];
  if (channel === 'admin') args.push('--config', 'admin.conf.json');
  args.push('--','--offline','--locked');
  await cli.run(args, 'tauri');
  const source = path.join(__dirname, 'target', 'x86_64-pc-windows-msvc', 'release', 'bundle', 'nsis', `${name}_${version}_x64-setup.exe`);
  const signature = fs.readFileSync(`${source}.sig`, 'utf8').trim();
  const bytes = fs.readFileSync(source);
  const folder = path.join(staging, channel);
  fs.mkdirSync(folder, { recursive: true });
  fs.mkdirSync(output, { recursive: true });
  const releaseName = `${channel}-${version}-x64-setup.exe`;
  fs.copyFileSync(source, path.join(folder, releaseName));
  fs.copyFileSync(source, path.join(output, filename));
  fs.copyFileSync(`${source}.sig`, path.join(output, `${filename}.sig`));
  const hostname = channel === 'admin' ? 'yonetim' : 'portal';
  fs.writeFileSync(path.join(folder, 'latest.json'), JSON.stringify({
    version, notes: 'Kurulum ve güncellemede masaüstü kısayolu eklenir. Windows ile başlayan uygulama, kısayola veya bildirim alanındaki simgeye basınca açılır.',
    pub_date: new Date().toISOString(),
    platforms: { 'windows-x86_64': { signature, url: `https://${hostname}.omnigoglobal.com/updates/${channel}/${releaseName}` } }
  }, null, 2));
  fs.writeFileSync(path.join(output, `${filename}.sha256`), `${crypto.createHash('sha256').update(bytes).digest('hex')}  ${filename}\n`);
}
process.chdir(__dirname);
(async () => {
  if(!process.argv.includes('--admin-only'))await build('client', config.productName, 'OG İş Ortağım.exe');
  if(!process.argv.includes('--client-only'))await build('admin', JSON.parse(fs.readFileSync(path.join(__dirname,'admin.conf.json'),'utf8')).productName, 'OG Yönetici Portalı.exe');
  fs.writeFileSync(path.join(staging, '.htaccess'), '<Files "latest.json">\nHeader always set Cache-Control "no-store"\n</Files>\nOptions -Indexes\n');
  await require('./windows/setup/build.cjs')(process.argv.includes('--client-only')?['client']:process.argv.includes('--admin-only')?['admin']:['client','admin']);
  console.log(`Her iki güncelleme paketi hazır: ${staging}`);
})().catch(error => { console.error(error.message); process.exitCode = 1; });
