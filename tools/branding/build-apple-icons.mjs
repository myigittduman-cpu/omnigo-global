import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium} from '../../verification/shared-design-20261031/qa-runtime/package/index.mjs';
const root=path.resolve(import.meta.dirname,'../..'),browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{
 const page=await browser.newPage({viewport:{width:1024,height:1024},deviceScaleFactor:1});
 for(const role of ['admin','client']){
  // Apple reduces this image to very small sizes on paired Watch notifications.
  // Use the original unlabelled globe; Windows/Android role artwork stays separate.
  const bytes=await fs.readFile(path.join(root,'public/assets/omnigo-favicon.png'));
  await page.setContent('<style>html,body{margin:0;width:1024px;height:1024px;background:#101c2c}img{display:block;width:1024px;height:1024px;object-fit:contain}</style><img src="data:image/png;base64,'+bytes.toString('base64')+'">');
  await page.locator('img').evaluate(image=>image.decode());
  await page.screenshot({path:path.join(root,'public/assets',`app-${role}-apple-1024-v2.png`)});
 }
}finally{await browser.close();}
