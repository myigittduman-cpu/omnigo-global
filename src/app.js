import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { join, extname } from 'node:path';
import { randomToken,digest,hashPassword,verifyPassword,publicUser,fail,sessionUser,requireAdmin,companyScope,loginLimit,failedLogin } from './security.js';
import { transaction } from './database.js';
import { validateAccount,kinds } from './validation.js';
import { createCompany,dashboard,saveRecord } from './repository.js';

const types={'.json':'application/json; charset=utf-8','.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'text/javascript; charset=utf-8','.png':'image/png','.jpg':'image/jpeg','.webp':'image/webp','.svg':'image/svg+xml'};
async function body(req){
 if(!(req.headers['content-type']||'').startsWith('application/json'))fail(415,'JSON içeriği gerekiyor.');
 let value='';for await(const chunk of req){value+=chunk;if(Buffer.byteLength(value)>65536)fail(413,'Kayıt çok büyük.');}
 try{const data=JSON.parse(value);if(!data||typeof data!=='object'||Array.isArray(data))fail(400,'Geçersiz istek.');return data;}catch{fail(400,'Geçersiz istek.');}
}
export function createApplication({db,publicDir,demo=false,secureCookies=false}){
 const dummyPassword=hashPassword(randomToken());
 return createServer(async(req,res)=>{
  const json=(status,data)=>{res.writeHead(status,{'Content-Type':'application/json; charset=utf-8'});res.end(JSON.stringify(data));};
  res.setHeader('Cache-Control','no-store');res.setHeader('X-Content-Type-Options','nosniff');res.setHeader('Referrer-Policy','no-referrer');
  res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
  try{
   const url=new URL(req.url,'http://localhost');const path=url.pathname;
   if(req.headers.host&&!/^(127\.0\.0\.1|localhost)(:\d+)?$/.test(req.headers.host))fail(403,'Bu yerel kurulum yalnızca localhost üzerinden kullanılabilir.');
   if(['POST','PUT','DELETE'].includes(req.method)&&req.headers.origin&&req.headers.origin!==`http://${req.headers.host}`)fail(403,'İstek kaynağı doğrulanamadı.');
   if(path==='/api/status'&&req.method==='GET')return json(200,{needsSetup:!db.prepare('SELECT id FROM users LIMIT 1').get(),demo});
   if(path==='/api/setup'&&req.method==='POST'){
    const data=validateAccount(await body(req));const passwordHash=await hashPassword(data.password);
    transaction(db,()=>{if(db.prepare('SELECT id FROM users LIMIT 1').get())fail(409,'Kurulum zaten tamamlandı.');const company=createCompany(db,'Vizyona');db.prepare('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)').run(data.email,data.name,passwordHash,'admin',null);});
    return json(201,{message:'Yönetici hesabı oluşturuldu. Giriş yapabilirsiniz.'});
   }
   if(path==='/api/login'&&req.method==='POST'){
    const data=await body(req);if(typeof data.email!=='string'||typeof data.password!=='string'||data.password.length>128)fail(400,'Giriş bilgilerini kontrol edin.');
    const email=data.email.trim().toLowerCase();const key=digest(req.socket.remoteAddress+'|'+email);const ipKey=digest(req.socket.remoteAddress);loginLimit(db,key);loginLimit(db,ipKey);
    const user=db.prepare('SELECT * FROM users WHERE email=?').get(email);
    if(!await verifyPassword(data.password,user?.password||await dummyPassword)||!user){failedLogin(db,key);failedLogin(db,ipKey);fail(401,'E-posta veya şifre hatalı.');}
    const token=randomToken(),csrf=randomToken();db.prepare('DELETE FROM sessions WHERE expires<?').run(Date.now());
    db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(token),user.id,csrf,Date.now()+8*3600000);
    db.prepare('DELETE FROM login_attempts WHERE key=?').run(key);
    res.setHeader('Set-Cookie',`og_session=${token}; HttpOnly; SameSite=Strict; Path=/; Max-Age=28800${secureCookies?'; Secure':''}`);
    return json(200,{user:publicUser(user),csrf,demo});
   }
   if(path.startsWith('/api/')){
    const user=sessionUser(db,req);
    if(req.method!=='GET'&&req.headers['x-csrf-token']!==user.csrf)fail(403,'İşlem doğrulanamadı. Sayfayı yenileyin.');
    if(path==='/api/me'&&req.method==='GET')return json(200,{user:publicUser(user),csrf:user.csrf,demo});
    if(path==='/api/logout'&&req.method==='POST'){db.prepare('DELETE FROM sessions WHERE token=?').run(user.token);res.setHeader('Set-Cookie','og_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0');return json(200,{});}
    if(path==='/api/companies'&&req.method==='GET')return json(200,user.role==='admin'?db.prepare('SELECT * FROM companies ORDER BY id').all():db.prepare('SELECT * FROM companies WHERE id=?').all(user.company_id));
    if(path==='/api/companies'&&req.method==='POST'){requireAdmin(user);const data=await body(req);if(typeof data.name!=='string'||!data.name.trim()||data.name.length>150)fail(400,'Firma adını kontrol edin.');return json(201,{id:transaction(db,()=>createCompany(db,data.name.trim()))});}
    if(path==='/api/users'&&req.method==='POST'){requireAdmin(user);const data=await body(req);const account=validateAccount(data,{storeUser:true});const company=companyScope(db,user,data.companyId);if(db.prepare('SELECT id FROM users WHERE email=?').get(account.email))fail(409,'Bu e-posta zaten kayıtlı.');const id=db.prepare('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)').run(account.email,account.name,await hashPassword(account.password),'client',company).lastInsertRowid;return json(201,{id:Number(id)});}
    const company=companyScope(db,user,url.searchParams.get('company'));
    if(path==='/api/dashboard'&&req.method==='GET'){const month=url.searchParams.get('month');if(!/^\d{4}-(0[1-9]|1[0-2])$/.test(month||''))fail(400,'Dönem seçin.');return json(200,dashboard(db,user,company,month));}
    if(path==='/api/audit'&&req.method==='GET'){requireAdmin(user);return json(200,db.prepare('SELECT audit.id,action,record_id,created,users.name FROM audit LEFT JOIN users ON users.id=audit.user_id WHERE audit.company_id=? ORDER BY audit.id DESC LIMIT 100').all(company));}
    const recordMatch=path.match(/^\/api\/records\/(channels|sales|payments|tasks|reports)(?:\/(\d+))?$/);
    if(recordMatch&&['POST','PUT'].includes(req.method)){requireAdmin(user);const [,kind,recordId]=recordMatch;if(req.method==='PUT'&&!recordId||req.method==='POST'&&recordId)fail(400,'İşlem geçersiz.');return json(recordId?200:201,saveRecord(db,user,company,kind,await body(req),recordId?Number(recordId):null));}
    fail(404,'İşlem bulunamadı.');
   }
   if(req.method!=='GET'&&req.method!=='HEAD')fail(405,'İşlem desteklenmiyor.');
   let file=path==='/'?'index.html':decodeURIComponent(path.slice(1));
   if(!/^[a-zA-Z0-9_./-]+$/.test(file)||file.split('/').includes('..')||file.startsWith('.')||!types[extname(file)])fail(404,'Sayfa bulunamadı.');
   let content;try{content=await readFile(join(publicDir,file));}catch{fail(404,'Sayfa bulunamadı.');}
   res.writeHead(200,{'Content-Type':types[extname(file)]});res.end(req.method==='HEAD'?undefined:content);
  }catch(error){if(!error.status)console.error('İstek işlenemedi:',error.message);if(!res.headersSent)json(error.status||500,{error:error.status?error.message:'İşlem tamamlanamadı. Lütfen tekrar deneyin.'});else res.end();}
 });
}
