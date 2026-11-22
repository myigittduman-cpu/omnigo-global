import {git,assertRemote} from './check.mjs';
try { assertRemote(); console.log('Yerel çalışma durumu:'); console.log(git(['status','--short','--branch'])); git(['fetch','origin','main']); console.log('Yerel / uzak ayrışma:'); console.log(git(['rev-list','--left-right','--count','HEAD...origin/main'])); } catch(e) { console.error(e.message); process.exitCode=1; }
