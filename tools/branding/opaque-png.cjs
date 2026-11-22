// App Store packaging requires RGB PNG data, even when every alpha byte is 255.
// The default conversion is lossless. An explicit background composites alpha for iOS only.
const assert = require('node:assert/strict');
const path = require('node:path');
const library = require.resolve('@pdf-lib/upng', {paths: [process.env.OMNIGO_NODE_MODULES || path.join(path.dirname(process.execPath), '../node_modules')]});
const png = require(library).default;
module.exports = function opaquePng(bytes, {background = null} = {}) {
  const source = png.decode(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength));
  const rgba = new Uint8Array(png.toRGBA8(source)[0]);
  if (background) {
    assert.equal(background.length, 3);
    for (let i = 0; i < rgba.length; i += 4) {
      const alpha = rgba[i + 3] / 255;
      for (let channel = 0; channel < 3; channel++) rgba[i + channel] = Math.round(rgba[i + channel] * alpha + background[channel] * (1 - alpha));
      rgba[i + 3] = 255;
    }
  }
  const rgb = new Uint8Array(source.width * source.height * 3);
  for (let i = 0, j = 0; i < rgba.length; i += 4, j += 3) {
    assert.equal(rgba[i + 3], 255, 'iOS ikonu saydam piksel içeriyor.');
    rgb.set(rgba.subarray(i, i + 3), j);
  }
  // The bundled UPNG encoder probes this optional global compression backend.
  const previousBackend = globalThis.UZIP;
  globalThis.UZIP = null;
  let result;
  try { result = Buffer.from(png.encodeLL([rgb.buffer], source.width, source.height, 3, 0, 8, [0])); }
  finally { if (previousBackend === undefined) delete globalThis.UZIP; else globalThis.UZIP = previousBackend; }
  const decoded = png.decode(result.buffer.slice(result.byteOffset, result.byteOffset + result.byteLength));
  assert.equal(decoded.ctype, 2);
  assert.deepEqual(new Uint8Array(png.toRGBA8(decoded)[0]), rgba);
  return result;
};
