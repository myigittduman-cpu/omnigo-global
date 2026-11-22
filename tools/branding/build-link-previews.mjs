import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium} from '../../verification/shared-design-20261031/qa-runtime/package/index.mjs';
import {previewCard,linkPreview} from './link-preview.mjs';
const root=path.resolve(import.meta.dirname,'../..'),browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{
 const page=await browser.newPage({viewport:{width:1200,height:630},deviceScaleFactor:1});
 for(const role of ['admin','client']){
  const icon='data:image/png;base64,'+(await fs.readFile(path.join(root,'desktop/icons',role,'icon.png'))).toString('base64');
  await page.setContent(previewCard(role,icon));await page.evaluate(()=>document.fonts.ready);await page.locator('img').evaluate(image=>image.decode());
  const file=path.join(root,'public',linkPreview(role).image);await page.screenshot({path:file});console.log(file);
 }
}finally{await browser.close();}
