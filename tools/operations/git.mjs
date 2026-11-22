import path from 'node:path';
import {spawnSync} from 'node:child_process';
const root=path.resolve(import.meta.dirname,'../..');
// Trust is limited to this process and exact workspace; global Git settings stay unchanged.
const result=spawnSync('git',['-c','safe.directory='+root.replaceAll('\\','/'),...process.argv.slice(2)],{cwd:root,stdio:'inherit'});
process.exitCode=result.status??1;
