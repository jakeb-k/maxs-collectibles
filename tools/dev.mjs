import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {ensureCompose} from './testing/ensure-compose.mjs';
import {fixtureEnvironment} from './testing/environment.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const directory=path.join(root,'.cache/development');
const run=path.join(root,'storage/testing/runs/development-preview');
const nodeImage='node:24-bookworm@sha256:14078204b6bb268c107ceff6d3c43f45a888105e5c2b18b14c32b3409d93a1b0';
const environment=Object.fromEntries(Object.entries(process.env).filter(([key])=>['PATH','HOME','DOCKER_HOST','DOCKER_CONTEXT','DOCKER_CONFIG','COLIMA_HOME'].includes(key)));
function safe(target){
 if(fs.realpathSync(root)!==root)throw Error('Symlink checkout rejected');
 const relative=path.relative(root,target);
 if(!relative||relative.startsWith('..')||path.isAbsolute(relative))throw Error('Unsafe preview path');
 let current=root;
 for(const part of relative.split(path.sep)){current=path.join(current,part);if(fs.lstatSync(current,{throwIfNoEntry:false})?.isSymbolicLink())throw Error('Symlink preview path rejected');}
}
function command(args){console.log(args.join(' '));const result=spawnSync(args[0],args.slice(1),{cwd:root,env:environment,stdio:'inherit'});if(result.status!==0)throw Error(`Command failed (${result.status}): ${args.join(' ')}`);}
let locked=false;
const lock=path.join(root,'.cache/check.lock');
try {
 const mode=process.argv[2]||'up';
 if(!['up','stop','logs'].includes(mode))throw Error('Use node tools/dev.mjs [up|stop|logs]');
 safe(directory);safe(path.join(directory,'mysql'));safe(run);
 fs.mkdirSync(directory,{recursive:true});
 for(const child of ['sessions','cache','views','logs','app','bootstrap','framework/testing']){const target=path.join(run,child);safe(target);fs.mkdirSync(target,{recursive:true});}
 const envFile=path.join(directory,'run.env');safe(envFile);
 if(!fs.existsSync(envFile)){
  const env=fixtureEnvironment('development-preview',`base64:${crypto.randomBytes(32).toString('base64')}`);
  env.APP_URL='http://127.0.0.1:8000';
  fs.writeFileSync(envFile,Object.entries(env).map(([key,value])=>`${key}=${value}`).join('\n')+'\n',{mode:0o600});
 }
 const compose=await ensureCompose(root);
 const base=[...compose,'-f','tools/development/compose.yml','-p','maxs-dev'];
 const php=(args)=>command([...base,'run','--rm','php',...args]);
 if(mode==='stop'){command([...base,'stop']);process.exit(0);}
 if(mode==='logs'){command([...base,'logs','--tail=100','web']);process.exit(0);}
 safe(lock);fs.mkdirSync(lock);locked=true;
 // Stop only this preview while changing dependencies/schema; retain its data and key.
 command([...base,'stop','web']);
 command([...base,'up','-d','--wait','mysql']);
 command([...base,'build','php']);
 php(['composer','install','--no-interaction','--no-scripts']);
 command(['docker','run','--rm','-v',`${root}:/app`,'-w','/app','-e','npm_config_cache=/app/.cache/npm',nodeImage,'npm','ci']);
 command(['docker','run','--rm','-v',`${root}:/app`,'-w','/app','-e','npm_config_cache=/app/.cache/npm',nodeImage,'npm','run','build']);
 php(['php','tools/testing/database.php']);
 php(['php','artisan','migrate','--force','--no-interaction']);
 const marker=path.join(run,'fixtures-ready');safe(marker);
 if(!fs.existsSync(marker)){
  php(['php','tools/testing/database.php','seed']);
  php(['php','tools/testing/storefront-fixtures.php']);
  fs.writeFileSync(marker,new Date().toISOString());
 }
 command([...base,'up','-d','web']);
 let ready=false;
 for(let attempt=0;attempt<60;attempt++){
  try{const response=await fetch('http://127.0.0.1:8000/up',{signal:AbortSignal.timeout(3000)});if(response.ok){ready=true;break;}}catch{}
  await new Promise(resolve=>setTimeout(resolve,500));
 }
 if(!ready)throw Error('Preview readiness failed. Run node tools/dev.mjs logs.');
 console.log('Preview ready: http://127.0.0.1:8000\nSynthetic administrator: browser-admin@example.test / isolated-password\nPersistent preview data is isolated from test-suite resets. Stop: node tools/dev.mjs stop');
}catch(error){console.error(error.stack||error);process.exitCode=1;}
finally{if(locked)fs.rmdirSync(lock);}
