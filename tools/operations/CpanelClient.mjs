import fs from 'node:fs/promises';
import https from 'node:https';
import path from 'node:path';
import crypto from 'node:crypto';

export const projectRoot = path.resolve(import.meta.dirname, '../..');
export const accessRoot = path.join(projectRoot, 'data/deployment-access');
export const remoteRoot = process.env.OMNIGO_CPANEL_REMOTE_ROOT || '/home/cpanel-user/portal';
const origin = process.env.OMNIGO_CPANEL_ORIGIN || 'https://cpanel.example.com:2083';
const cpanelUser = process.env.OMNIGO_CPANEL_USER || 'cpanel_user';
const usingPlaceholderConfig = origin.includes('example.com') || cpanelUser === 'cpanel_user' || remoteRoot.includes('/cpanel-user/');

// This client never follows redirects or exposes response headers / credentials.
export class CpanelClient {
  #token;
  constructor(token) { this.#token = token; }
  static async connect() {
    if (usingPlaceholderConfig) {
      throw new Error('cPanel hedefi yapılandırılmamış. OMNIGO_CPANEL_ORIGIN, OMNIGO_CPANEL_USER ve OMNIGO_CPANEL_REMOTE_ROOT değişkenlerini gizli ortam ayarından tanımlayın.');
    }
    const token = (await fs.readFile(path.join(accessRoot, 'cpanel-token.txt'), 'utf8')).trim();
    if (token === 'Omnigo-Gecici-Kurulum') {
      throw new Error('Dosyada jetonun adı var; oluşturma ekranında bir kez gösterilen erişim kodu gerekiyor.');
    }
    if (!/^[A-Za-z0-9_-]{20,200}$/.test(token) || token.includes('BURAYA')) {
      throw new Error('Geçici erişim anahtarı henüz dosyaya kaydedilmemiş.');
    }
    return new CpanelClient(token);
  }
  async request(endpoint, params = {}, body = null, contentType = null, attempt = 0) {
    const url = new URL(endpoint, origin);
    if (url.origin !== origin || !['/execute/', '/json-api/cpanel'].some(p => url.pathname.startsWith(p))) {
      throw new Error('İzin verilmeyen sunucu adresi.');
    }
    for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value));
    const headers = { Authorization: `cpanel ${cpanelUser}:${this.#token}`, Accept: 'application/json' };
    if (body) { headers['Content-Type'] = contentType; headers['Content-Length'] = body.length; }
    return new Promise((resolve, reject) => {
      let finished = false;
      const fail = message => { if (!finished) { finished = true; reject(new Error(message)); } };
      const request = https.request(url, { method: body ? 'POST' : 'GET', headers, rejectUnauthorized: true }, response => {
        if (response.statusCode !== 200) { response.resume(); fail(`Sunucu HTTP ${response.statusCode} döndürdü.`); return; }
        const chunks = []; let bytes = 0;
        response.on('data', chunk => {
          bytes += chunk.length;
          if (bytes > 8 * 1024 * 1024) { request.destroy(); fail('Sunucu yanıtı boyut sınırını aştı.'); }
          else chunks.push(chunk);
        });
        response.on('end', () => {
          if (finished) return;
          try { const result = JSON.parse(Buffer.concat(chunks).toString('utf8')); finished = true; resolve(result); }
          catch { fail('Sunucu beklenen biçimde yanıt vermedi.'); }
        });
        response.on('error', () => fail('Sunucu yanıtı tamamlanamadı.'));
      });
      request.setTimeout(25000, () => { request.destroy(); fail('Sunucu bağlantısı zaman aşımına uğradı.'); });
      request.on('error', error => fail(`Sunucuya güvenli bağlantı kurulamadı (${error.code || 'NETWORK_ERROR'}).`));
      if (body) request.write(body);
      request.end();
    }).catch(async error => {
      // DNS resolution failures happen before any request can reach the server.
      if(attempt < 2 && /\((ENOTFOUND|EAI_AGAIN)\)/.test(error.message)){
        await new Promise(resolve=>setTimeout(resolve,1000*(attempt+1)));
        return this.request(endpoint,params,body,contentType,attempt+1);
      }
      throw error;
    });
  }
  async uapi(module, fn, params = {}) {
    if (!/^[A-Za-z_]+$/.test(module) || !/^[A-Za-z_]+$/.test(fn)) throw new Error('Geçersiz API işlemi.');
    const result = await this.request(`/execute/${module}/${fn}`, params);
    if (Number(result.status) !== 1) throw new Error(`cPanel ${module}/${fn} işlemini tamamlayamadı.`);
    return result.data;
  }
  async api2(module, fn, params = {}) {
    const result = await this.request('/json-api/cpanel', {
      cpanel_jsonapi_user: cpanelUser, cpanel_jsonapi_apiversion: 2,
      cpanel_jsonapi_module: module, cpanel_jsonapi_func: fn, ...params,
    });
    const response = result.cpanelresult;
    if (Number(response?.event?.result) !== 1 || response?.error || response?.data?.some?.(row => Number(row.status) === 0 || Number(row.result) === 0)) {
      throw new Error(`cPanel ${module}/${fn} işlemini tamamlayamadı.`);
    }
    return response.data;
  }
  async readPortalFile(relativePath) {
    // This reviewed class contains recovery logic, never credentials. Secret/config files remain blocked.
    const publicSource = ['src/PasswordRecovery.php','src/MobilePushConfig.php','src/PasswordChange.php','public/password-change.js','public/initial-password.js'].includes(relativePath);
    if (relativePath.includes('..') || path.posix.isAbsolute(relativePath) || (!publicSource && /(?:password|config|\.key|\.env)/i.test(relativePath))) {
      throw new Error('Bu dosya uzaktan okunmaya uygun değil.');
    }
    return this.uapi('Fileman', 'get_file_content', {
      dir: path.posix.join(remoteRoot, path.posix.dirname(relativePath)),
      file: path.posix.basename(relativePath), from_charset: 'UTF-8', to_charset: 'UTF-8', update_html_document_encoding: 0,
    });
  }
  async uploadHelper(filename, data) {
    if (!/^deployment-062-[a-z0-9.-]+\.(php|json)$/.test(filename)) throw new Error('Yükleme dosyası izinli değil.');
    const boundary = `Omnigo${crypto.randomBytes(16).toString('hex')}`;
    const body = Buffer.concat([
      Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="dir"\r\n\r\n${remoteRoot}\r\n`),
      Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="file-1"; filename="${filename}"\r\nContent-Type: application/octet-stream\r\n\r\n`),
      Buffer.from(data), Buffer.from(`\r\n--${boundary}--\r\n`),
    ]);
    const result = await this.request('/execute/Fileman/upload_files', {}, body, `multipart/form-data; boundary=${boundary}`);
    if (Number(result.status) !== 1 || (result.data?.uploads && result.data.uploads.some(row => row.status === 0))) throw new Error('Kurulum yardımcısı yüklenemedi.');
    return result.data;
  }
}
