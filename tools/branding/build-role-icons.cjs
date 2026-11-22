// Convert the approved role artwork into platform assets without changing the logo.
const fs = require('node:fs/promises');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const cli = require(path.join(root, 'work/tauri-cli/js/package/main.js'));

async function copy(source, target) {
  await fs.mkdir(path.dirname(target), {recursive: true});
  await fs.copyFile(source, target);
}

async function buildRole(role) {
  // Both applications share the approved OMNIGO / GLOBAL artwork.
  const master = path.join(root, 'assets/app-icons', `${role}-windows.png`);
  await fs.access(master);
  const desktop = path.join(root, 'desktop/icons', role);
  await cli.run(['icon', master, '--output', desktop, '--ios-color', '#101c2c'], 'tauri');
}

(async () => { await require('./build-symbol-icons.cjs')();for (const role of ['client','admin']) await buildRole(role); if(!process.argv.includes('--desktop-only'))await require('./mobile-icons.cjs')(root); })()
  .catch(error => {console.error(error.message); process.exitCode = 1;});
