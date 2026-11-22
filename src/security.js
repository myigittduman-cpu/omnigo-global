import { promisify } from 'node:util';
import { randomBytes, scrypt, timingSafeEqual, createHash } from 'node:crypto';
const deriveKey=promisify(scrypt);
export const randomToken=()=>randomBytes(32).toString('hex');
export const digest=value=>createHash('sha256').update(value).digest('hex');
export async function hashPassword(password){const salt=randomToken();return salt+':'+(await deriveKey(password,salt,64)).toString('hex');}
export async function verifyPassword(password,stored){const [salt,hash]=stored.split(':');const actual=await deriveKey(password,salt,64);const expected=Buffer.from(hash,'hex');return expected.length===actual.length&&timingSafeEqual(actual,expected);}
export function publicUser(user){return {id:user.id,name:user.name,email:user.email,role:user.role,companyId:user.company_id};}
export function fail(status,message){const error=new Error(message);error.status=status;throw error;}
export function sessionUser(db,req){
 const match=(req.headers.cookie||'').match(/(?:^|;\s*)og_session=([a-f0-9]{64})(?:;|$)/);
 if(!match)fail(401,'Oturum açmanız gerekiyor.');
 const user=db.prepare('SELECT users.*,sessions.csrf,sessions.token FROM sessions JOIN users ON users.id=sessions.user_id WHERE token=? AND expires>?').get(digest(match[1]),Date.now());
 if(!user)fail(401,'Oturumunuz sona erdi.');return user;
}
export function requireAdmin(user){if(user.role!=='admin')fail(403,'Bu işlem için yönetici yetkisi gerekiyor.');}
export function companyScope(db,user,id){
 const target=Number(id||user.company_id);
 if(!Number.isSafeInteger(target)||target<=0)fail(400,'Firma seçin.');
 if(user.role!=='admin'&&target!==user.company_id)fail(403,'Bu firmaya erişiminiz yok.');
 if(!db.prepare('SELECT id FROM companies WHERE id=?').get(target))fail(404,'Firma bulunamadı.');
 return target;
}
export function loginLimit(db,key){const row=db.prepare('SELECT * FROM login_attempts WHERE key=?').get(key);if(row&&row.reset_at>Date.now()&&row.count>=10)fail(429,'Çok fazla giriş denemesi. 15 dakika sonra yeniden deneyin.');}
export function failedLogin(db,key){db.prepare('INSERT INTO login_attempts VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=CASE WHEN reset_at<? THEN 1 ELSE count+1 END,reset_at=CASE WHEN reset_at<? THEN excluded.reset_at ELSE reset_at END').run(key,Date.now()+900000,Date.now(),Date.now());}
