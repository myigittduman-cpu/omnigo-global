import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {applyMenuAppearance,menuStyles} from '../public/menu-appearance.js';
import {applyPreferences} from '../public/user-preferences.js';

test('Kayıtlı tüm menü vurguları ortak DM Sans dosyasını bir kez yükler; tema korunur',()=>{
 const links=new Map(),events=[];
 const document={head:{append:link=>links.set(link.id,link)},getElementById:id=>links.get(id),createElement:()=>({addEventListener(){}}),dispatchEvent:event=>events.push(event.type)};
 const root={dataset:{},ownerDocument:document};
 for(const menuStyle of Object.keys(menuStyles)){
  for(const theme of ['classic-navy','light','burgundy','petrol-smoke','graphite-copper']){
   applyPreferences({theme,menuStyle},root);
   assert.equal(root.dataset.theme,theme);
   assert.equal(root.dataset.menuStyle,menuStyle);
  }
 }
 assert.deepEqual([...links.keys()],['menu-appearance','menu-font-dm-sans']);
 assert.equal(links.get('menu-font-dm-sans').href,'/menu-font-dm-sans.css');
 assert(events.includes('omnigo:menu-appearance'));
 applyMenuAppearance(undefined,root);
 assert.equal(root.dataset.menuStyle,'modern');
 assert.equal(links.size,2);
});

test('Yerel DM Sans yazı tipi Türkçe karakterlerin gerçek gliflerini içerir',()=>{
 const font=readFileSync(new URL('../public/assets/menu-font-dm-sans.ttf',import.meta.url));
 assert.equal(font.readUInt32BE(0),0x10000);
 const tableCount=font.readUInt16BE(4);
 let cmap;
 for(let i=0;i<tableCount;i++){
  const row=12+16*i;
  if(font.toString('ascii',row,row+4)==='cmap')cmap=font.readUInt32BE(row+8);
 }
 assert(cmap);
 const offsets=[];
 for(let i=0;i<font.readUInt16BE(cmap+2);i++)offsets.push(cmap+font.readUInt32BE(cmap+4+i*8+4));
 const maps=offsets.filter(offset=>font.readUInt16BE(offset)===4);
 assert(maps.length,'Unicode cmap bulunamadı');
 const hasGlyph=code=>maps.some(start=>{
  const n=font.readUInt16BE(start+6)/2,end=start+14,begin=end+2*n+2,delta=begin+2*n,range=delta+2*n;
  for(let i=0;i<n;i++){
   if(code<font.readUInt16BE(begin+2*i)||code>font.readUInt16BE(end+2*i))continue;
   const shift=font.readInt16BE(delta+2*i),distance=font.readUInt16BE(range+2*i);
   if(!distance)return ((code+shift)&65535)!==0;
   const index=range+2*i+distance+2*(code-font.readUInt16BE(begin+2*i));
   const glyph=font.readUInt16BE(index);return glyph!==0&&((glyph+shift)&65535)!==0;
  }
  return false;
 });
 for(const character of 'İıŞşĞğÜüÖöÇç')assert(hasGlyph(character.codePointAt(0)),character+' glifi eksik');
});
