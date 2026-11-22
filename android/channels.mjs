export const channels = Object.freeze({
  client: {
    applicationId: 'com.omnigoglobal.client',
    host: 'portal.omnigoglobal.com',
    title: 'OG İş Ortağım',
    launcherName: 'OG İş Ortağım',
    filenamePrefix: 'OG-Is-Ortagim',
    version: '1.0.0', versionCode: 1,
  },
  admin: {
    applicationId: 'com.omnigoglobal.admin',
    host: 'yonetim.omnigoglobal.com',
    title: 'OG Yönetici Portalı',
    launcherName: 'OG Yönetici Portalı',
    filenamePrefix: 'OG-Yonetici-Portali',
    version: '1.0.0', versionCode: 1,
  },
});

export function getChannel(name = 'client') {
  if (!Object.hasOwn(channels, name)) throw new Error('Bilinmeyen Android uygulaması: ' + name);
  return {name, ...channels[name], origin: 'https://' + channels[name].host};
}
