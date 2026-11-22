const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

exports.createBundle = ({ makeAppx, packages, bundlePath, version }) => {
  const mappingPath = path.join(path.dirname(bundlePath), 'client', 'bundle-files.txt');
  const files = packages.map(file => `"${file}" "${path.basename(file)}"`);
  // The mapping includes Unicode filenames and only the newly built packages.
  fs.writeFileSync(mappingPath, `\uFEFF[Files]\r\n${files.join('\r\n')}\r\n`, 'utf16le');
  const result = spawnSync(makeAppx, ['bundle', '/f', mappingPath, '/p', bundlePath, '/bv', version, '/o'], {
    encoding: 'utf8',
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(result.stdout + result.stderr);
};
