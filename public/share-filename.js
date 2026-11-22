// Normal downloads and mobile sharing preserve Turkish characters.
export function shareFilename(filename){
 return String(filename).normalize('NFC').replace(/[\/\\:*?"<>|\p{Cc}\p{Cf}]/gu,'-').replace(/\s+/g,' ').trim();
}
// WhatsApp for Windows misdecodes Unicode StorageItem names on some versions.
// Apply this only at the desktop sharing boundary, never to saved reports.
export function windowsShareFilename(filename){
 if(/^.+ – \d{2}\.\d{2}\.\d{4} .+\.(?:pdf|xlsx|jpeg|jpg)$/u.test(filename))return shareFilename(filename);
 return shareFilename(filename).replace(/ı/g,'i').normalize('NFKD')
  .replace(/\p{M}/gu,'').replace(/[^\x20-\x7E]/g,'-');
}
