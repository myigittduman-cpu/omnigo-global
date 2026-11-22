import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {git,check,assertRemote} from './check.mjs';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const cfg=JSON.parse(fs.readFileSync(path.join(root,'tools/github/project.json'),'utf8'));
try{
 const args=process.argv.slice(2),separator=args.indexOf('--');
 const message=separator>0?args.slice(0,separator).join(' ').trim():'';
 const files=separator>=0?args.slice(separator+1):[];
 if(!message||!files.length||files.some(f=>f==='.'||f==='*'||f.startsWith('-')||f.includes(':')||path.isAbsolute(f)||f.split(/[\\/]/).includes('..')))throw Error('Kullanım: node tools/github/save.mjs "Türkçe açıklama" -- dosya1 dosya2');
 assertRemote();
 if(git(['branch','--show-current']).trim()!=='main')throw Error('Bu yardımcı yalnız main dalında çalışır.');
 if(git(['status','--porcelain','--untracked-files=no']).split('\n').some(l=>/^(?:U.|.U|AA|DD)/.test(l)))throw Error('Önce birleştirme çakışmalarını çözün.');
 if(git(['diff','--cached','--name-only']).trim())throw Error('Önceden hazırlanmış değişiklikler var; bunları ayrı inceleyin.');
 git(['fetch','origin','main']);
 if(git(['rev-parse','HEAD']).trim()!==git(['rev-parse','origin/main']).trim())throw Error('Yerel ve uzak main farklı. Önce farkı inceleyin; otomatik merge veya force yapılmadı.');
 git(['--literal-pathspecs','add','--',...files]);
 const changed=check();
 if(!git(['diff','--cached','--name-only']).trim())throw Error('Kaydedilecek değişiklik yok.');
 console.log('Commit kapsamı:');console.log(git(['diff','--cached','--stat']));
 git(['commit','-m',message],{stdio:'inherit'});
 git(['push','origin','main:refs/heads/main'],{stdio:'inherit'});
 const local=git(['rev-parse','HEAD']).trim();
 const remote=git(['ls-remote','origin','refs/heads/main']).trim().split(/\s+/)[0];
 if(local!==remote)throw Error('Uzak commit doğrulanamadı.');
 console.log(`GitHub kaydı doğrulandı: ${cfg.project} ${local}`);
}catch(e){console.error(e.message);process.exitCode=1;}
