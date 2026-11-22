import fs from 'node:fs/promises';
const source=JSON.parse(await fs.readFile('verification/localization-20261025/static-missing.json','utf8'));
const translated=JSON.parse(await fs.readFile('tools/i18n/static-translations.json','utf8'));
if(translated.length!==source.length||translated.some(([id],index)=>id!==index))throw new Error('Static translation inventory mismatch');
await fs.writeFile('tools/i18n/static-pages.json',JSON.stringify(translated.map(([id,en,de])=>[source[id].text,en,de]),null,1));
