// Local-only, disposable preview. Uses the isolated synthetic SQLite fixture.
import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import {spawn} from 'node:child_process';
const root=path.resolve(import.meta.dirname,'../..'),runtime=path.join(root,'work/localization-preview-20261025');
const php=path.resolve(root,'../work/php-runtime/php-cgi.exe');
const mime={'.js':'text/javascript','.css':'text/css','.html':'text/html','.json':'application/json','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.webp':'image/webp','.woff2':'font/woff2','.wav':'audio/wav'};
for(const port of [8904,8905])http.createServer(async(req,res)=>{
 try{
  const uri=new URL(req.url,'http://127.0.0.1'),pathname=decodeURIComponent(uri.pathname);
  if(pathname.startsWith('/api/')){
   const buffers=[];for await(const chunk of req)buffers.push(chunk);const body=Buffer.concat(buffers);
   const env={...process.env,REDIRECT_STATUS:'200',REQUEST_METHOD:req.method,REQUEST_URI:req.url,QUERY_STRING:uri.search.slice(1),SCRIPT_FILENAME:runtime+'/router.php',SCRIPT_NAME:'/router.php',SERVER_PROTOCOL:'HTTP/1.1',SERVER_NAME:'127.0.0.1',SERVER_PORT:String(port),REMOTE_ADDR:'127.0.0.1',CONTENT_LENGTH:String(body.length),CONTENT_TYPE:req.headers['content-type']||''};
   for(const [name,value]of Object.entries(req.headers))env['HTTP_'+name.toUpperCase().replaceAll('-','_')]=String(value);
   const child=spawn(php,['-d','extension_dir='+path.dirname(php)+'/ext','-d','extension=fileinfo'],{cwd:runtime,env,windowsHide:true});
   const chunks=[];child.stdout.on('data',chunk=>chunks.push(chunk));child.stderr.on('data',chunk=>process.stderr.write(chunk));child.stdin.end(body);
   child.on('error',()=>{res.writeHead(500);res.end('Preview CGI unavailable');});
   child.on('close',()=>{if(res.writableEnded)return;const output=Buffer.concat(chunks),split=output.indexOf('\r\n\r\n');if(split<0){res.writeHead(500);res.end('Invalid preview response');return;}let status=200;const headers={};for(const line of output.subarray(0,split).toString().split('\r\n')){const colon=line.indexOf(':');if(colon<0)continue;const name=line.slice(0,colon).toLowerCase(),value=line.slice(colon+1).trim();if(name==='status')status=Number(value.split(' ')[0]);else if(name==='set-cookie')headers[name]=[...(headers[name]||[]),value];else headers[name]=value;}res.writeHead(status,headers);res.end(output.subarray(split+4));});
   return;
  }
  const target=path.resolve(root,'public','.'+(pathname==='/'?'/index.html':pathname));
  if(!target.startsWith(path.join(root,'public')+path.sep)){res.writeHead(403);res.end();return;}
  const data=await fs.readFile(target);res.writeHead(200,{'Content-Type':mime[path.extname(target)]||'application/octet-stream','Cache-Control':'no-store'});res.end(data);
 }catch{res.writeHead(404);res.end('Not found');}
}).listen(port,'127.0.0.1',()=>console.log('Synthetic preview listening on '+port));
