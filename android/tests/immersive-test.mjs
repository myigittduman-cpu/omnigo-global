import fs from 'node:fs/promises';
import path from 'node:path';
import {root,toolchain,soleDirectory,run,files} from '../store/runtime.mjs';

const jdk=await soleDirectory(path.join(toolchain,'jdk-21'));
const output=path.join(root,'android/build/immersive-tests');
await fs.mkdir(output,{recursive:true});
await run(path.join(jdk,'bin/javac.exe'),['-encoding','UTF-8','--release','8','-d',output,
 ...await files(path.join(root,'android/tests/immersive-stubs'),'.java'),
 path.join(root,'android/app/src/main/java/com/omnigoglobal/client/ImmersiveWindow.java'),
 path.join(root,'android/tests/ImmersiveWindowTest.java')]);
await run(path.join(jdk,'bin/java.exe'),['-cp',output,'com.omnigoglobal.client.ImmersiveWindowTest']);
