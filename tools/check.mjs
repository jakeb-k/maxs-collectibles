import fs from 'node:fs';
import {ensureCompose} from './testing/ensure-compose.mjs';
import {fixtureEnvironment} from './testing/environment.mjs';
import {phpSummary,browserSummary} from './testing/results.mjs';
import {contentState} from './testing/content-state.mjs';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runs=path.join(root,'storage/testing/runs');
const nodeImage='node:24-bookworm@sha256:14078204b6bb268c107ceff6d3c43f45a888105e5c2b18b14c32b3409d93a1b0';
const cleanEnv=Object.fromEntries(Object.entries(process.env).filter(([k])=>['PATH','HOME','DOCKER_HOST','DOCKER_CONTEXT','DOCKER_CONFIG','COLIMA_HOME'].includes(k)));
let composeCommand;
let active, seq=0; const commands=[]; const servers=new Set();
function boundary(target, base=root) {
  const relative=path.relative(base,target);
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw Error('Unsafe path boundary');
  let current=base;
  if (fs.realpathSync(base)!==base) throw Error('Symlink root');
  for (const segment of relative.split(path.sep)) { current=path.join(current,segment); if(fs.lstatSync(current,{throwIfNoEntry:false})?.isSymbolicLink()) throw Error('Symlink path'); }
}
function command(args,{allowFailure=false,network=true}={}) {
  const startedAt=new Date().toISOString();
  const result=spawnSync(args[0],args.slice(1),{cwd:root,env:cleanEnv,encoding:'utf8',maxBuffer:32*1024*1024});
  const record={command:args,exit:result.status,signal:result.signal,startedAt,output:`${result.stdout||''}${result.stderr||''}`};
  commands.push(record); if(active) fs.writeFileSync(path.join(active.dir,`command-${++seq}.log`),JSON.stringify(record,null,2));
  if(result.status!==0&&!allowFailure) throw Error(`${args.join(' ')} failed (${result.status}): ${record.output.slice(-3500)}`);
  return record;
}
async function http(url, options={}) {
 const controller=new AbortController();
 // Keep a referenced deadline while Node waits for Docker's newly published port.
 const deadline=setTimeout(()=>controller.abort(new Error('Test HTTP deadline exceeded')),5000);
 try { return await fetch(url,{...options,signal:controller.signal}); }
 finally { clearTimeout(deadline); }
}
function runCreate(prefix='check') {
  const id=`${prefix}-${Date.now()}-${crypto.randomBytes(4).toString('hex')}`;
  const dir=path.join(runs,id);boundary(dir);fs.mkdirSync(dir,{recursive:true});
  for(const child of ['sessions','cache','views','logs','app','bootstrap','framework','framework/testing'])fs.mkdirSync(path.join(dir,child),{recursive:true});
  const env=fixtureEnvironment(id,`base64:${crypto.randomBytes(32).toString('base64')}`);
  const run={id,dir,env,envFile:path.join(dir,'run.env')};save(run);return run;
}
function save(run){ fs.writeFileSync(run.envFile,Object.entries(run.env).map(([k,v])=>`${k}=${v}`).join('\n')+'\n',{mode:0o600}); }
function php(args,run=active,options={}) { return command(['docker','run','--rm','--network','maxs-test_isolated','--env-file',run.envFile,'-v',`${root}:/app`,'-w','/app','maxs-test-php:8.4',...args],options); }
function compose(args){return command([...composeCommand,'--env-file','.cache/current.env','-p','maxs-test',...args]);}
function node(args,options={}){return command(['docker','run','--rm','-v',`${root}:/app`,'-w','/app','-e','npm_config_cache=/app/.cache/npm',nodeImage,...args],options);}
function browser(spec,run,{allowFailure=false}={}){if(spec === 'tests/Browser' || ['tests/Browser/storefront.spec.js','tests/Browser/commerce.spec.js','tests/Browser/accessibility.spec.js','tests/Browser/accounts-contact.spec.js','tests/Browser/production.spec.js'].includes(spec)) php(['php','tools/testing/storefront-fixtures.php'],run);return command(['docker','run','--rm','--network',`container:maxs-test-server-${run.id}`,'-v',`${root}:/app`,'-w','/app','-e','MAXS_BROWSER_URL=http://127.0.0.1:8000','-e',`MAXS_BROWSER_OUTPUT=/app/storage/testing/runs/${run.id}/playwright`,'mcr.microsoft.com/playwright:v1.64.1-noble@sha256:7852dc5a7d72f5fe458a597d690f6c42fbc8e9e26ddfa95f8e6ef552558d9bbf','npx','playwright','test',spec,'--config=tools/testing/playwright.config.js','--fail-on-flaky-tests'],{allowFailure});}
function stop(run){const name=`maxs-test-server-${run.id}`; if(servers.has(name)){command(['docker','rm','-f',name]);servers.delete(name);} }
function reset(run){
  stop(run);boundary(run.dir,runs);
  if(servers.has(`maxs-test-server-${run.id}`))throw Error('Server must stop before reset');
  php(['php','tools/testing/database.php'],run);
  for(const child of ['sessions','cache','views','app']) {const target=path.join(run.dir,child);boundary(target,runs);fs.rmSync(target,{recursive:true});fs.mkdirSync(target);}
  run.env.APP_KEY=`base64:${crypto.randomBytes(32).toString('base64')}`;
  run.env.SESSION_COOKIE+=`_r${crypto.randomBytes(4).toString('hex')}`;save(run);
}
function fresh(run=active){const existing=command(['docker','ps','--filter','label=maxs.test.server=true','--format','{{.Names}}']).output.trim();if(existing)throw Error('Stop all test servers before schema reset');php(['php','tools/testing/database.php'],run);stop(run);php(['php','artisan','migrate:fresh','--force','--no-interaction'],run);php(['php','tools/testing/database.php','seed'],run);}
async function server(run=active){
  const name=`maxs-test-server-${run.id}`;
  command(['docker','run','-d','--name',name,'--label','maxs.test.server=true','--network','maxs-test_isolated','--env-file',run.envFile,'-v',`${root}:/app`,'-w','/app','-p','127.0.0.1::8000','maxs-test-php:8.4','php','artisan','serve','--host=0.0.0.0','--port=8000','--no-reload']);servers.add(name);
  const mapping=command(['docker','port',name,'8000/tcp']).output.trim();const base=`http://${mapping}`;
  if(new URL(base).hostname!=='127.0.0.1')throw Error('Non-loopback server');
  for(let i=0;i<100;i++){try{const r=await http(`${base}/up`);if(r.ok)return base;}catch{}await new Promise(r=>setTimeout(r,200));}
  throw Error('Server readiness timeout');
}
async function sessions(){
  const other=runCreate('other');const marker=path.join(other.dir,'sessions/preserve');fs.writeFileSync(marker,'other run');
  const otherBase=await server(other);
  let otherResponse=await http(otherBase+'/__test/session?sentinel=other-run');assert.equal(otherResponse.status,200);
  const otherCookies=otherResponse.headers.getSetCookie().map(c=>c.split(';')[0]).join('; ');
  const base=await server();
  const jar=new Map();
  const request=async(url,opts={})=>{ const response=await http(base+url,{redirect:'manual',...opts,headers:{Cookie:[...jar].map(([k,v])=>`${k}=${v}`).join('; '),Accept:'application/json',...opts.headers}});for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';')[0];const index=pair.indexOf('=');jar.set(pair.slice(0,index),pair.slice(index+1));}return response;};
  let response=await request('/__test/session?sentinel=anonymous-identity');assert.equal(response.status,200);let data=await response.json();assert.equal(data.sentinel,'anonymous-identity');
  response=await request('/__test/session');data=await response.json();assert.equal(data.sentinel,'anonymous-identity');assert.equal(data.user,null);
  response=await request('/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':data.csrf},body:JSON.stringify({email:'isolation@example.test',password:'isolated-password'})});assert.equal(response.status,302);
  response=await request('/__test/session');data=await response.json();assert.ok(data.user);assert.equal(data.sentinel,'anonymous-identity');
  const oldCookies=[...jar].map(([k,v])=>`${k}=${v}`).join('; ');
  reset(active);const restarted=await server();response=await http(restarted+'/__test/session',{headers:{Cookie:oldCookies,Accept:'application/json'}});data=await response.json();assert.equal(data.user,null);assert.equal(data.sentinel,null);assert.equal(fs.readFileSync(marker,'utf8'),'other run');otherResponse=await http(otherBase+'/__test/session',{headers:{Cookie:otherCookies}});assert.equal((await otherResponse.json()).sentinel,'other-run');stop(other);stop(active);
  return {anonymousAcrossRequests:true,loginAcrossRequests:true,oldCookiesInvalidAfterReset:true,otherRunPreserved:other.id};
}
function guardChecks(){
 const failures=[];for(const [key,value] of Object.entries({APP_ENV:'production',DB_HOST:'production',DB_DATABASE:'live',DB_USERNAME:'root',STRIPE_SECRET:'sk_live_forbidden',LARAVEL_STORAGE_PATH:'/outside',SESSION_FILES:'/outside',CACHE_FILES:'/outside',VIEW_COMPILED_PATH:'/outside',APP_PACKAGES_CACHE:'/outside',APP_CONFIG_CACHE:'/outside',APP_URL:'https://example.com',DB_URL:'mysql://bad',SESSION_COOKIE:'shared'})){
  const original=active.env[key];active.env[key]=value;save(active);const r=php(['php','tools/testing/guard.php'],active,{allowFailure:true});if(original===undefined)delete active.env[key];else active.env[key]=original;save(active);assert.notEqual(r.exit,0,`guard accepted ${key}`);failures.push(key);
 }
 const outside=path.join(root,'.cache/keep');fs.writeFileSync(outside,'keep');const link=path.join(active.dir,'unsafe');fs.symlinkSync(path.dirname(outside),link);assert.throws(()=>boundary(path.join(link,'keep'),runs));assert.throws(()=>reset({...active,dir:link}));assert.throws(()=>boundary(root,runs));fs.unlinkSync(link);assert.equal(fs.readFileSync(outside,'utf8'),'keep');
 const cached=php(['php','-r', '$cached=getenv("APP_CONFIG_CACHE");file_put_contents($cached,"<?php return [];");try { require "tools/testing/guard.php"; } finally { unlink($cached); }'],active,{allowFailure:true});assert.notEqual(cached.exit,0);failures.push('cached-config');
 const sentinel=(identity)=>command(['docker','exec','maxs-test-mysql-1','mysql','-uroot','-pmaxs-isolated-root-only','-e',`UPDATE maxs_guard.sentinel SET identity='${identity}'`]);
 php(['php','tools/testing/database.php']);try{sentinel('wrong-sentinel');const denied=php(['php','tools/testing/database.php'],active,{allowFailure:true});assert.notEqual(denied.exit,0);}finally{sentinel('maxs-isolated-v1');}failures.push('server-sentinel');return failures;
}
function procedure(mode,id,file){
 const catalog=JSON.parse(fs.readFileSync(path.join(root,'docs/operations/procedures.json')));const procedure=catalog[mode]?.[id];if(!procedure)throw Error(`Unknown ${mode} procedure: ${id}`);console.log(JSON.stringify(procedure,null,2));
 if(!file)throw Error('Signed evidence JSON path is required');const evidence=JSON.parse(fs.readFileSync(file));assert.equal(evidence.procedure,id);assert.equal(evidence.mode,mode);assert.ok(evidence.signedBy&&evidence.signedAt);assert.ok(!Number.isNaN(Date.parse(evidence.signedAt)));
 if(mode!=='review'){const host=new URL(evidence.target);assert.equal(host.protocol,'https:');if(mode==='staging')assert.equal(evidence.providerMode,'test');assert.ok(evidence.readOnly);}
 for(const step of procedure.steps){const result=evidence.results.find(r=>r.id===step.id);assert.ok(result&&result.status==='passed'&&result.command&&result.output&&result.artifacts?.length,`Missing evidence: ${step.id}`);for(const ref of [result.output,...result.artifacts]){const target=path.resolve(root,ref);boundary(target);assert.ok(fs.statSync(target).size>0);}}
 return evidence;
}
const [mode,arg,evidence]=process.argv.slice(2);
assert.equal(fs.realpathSync(root),root);
boundary(path.join(root,'.cache'));boundary(runs);boundary(path.join(root,'.cache/check.lock'));
fs.mkdirSync(path.join(root,'.cache'),{recursive:true});fs.mkdirSync(runs,{recursive:true});
let locked=false;let result={mode,startedAt:new Date().toISOString(),status:'failed'};
try {
 Object.assign(result,contentState(root));
 if(['review','staging','launch'].includes(mode)){result.procedure=procedure(mode,arg,evidence);}
 else {
  boundary(path.join(root,'.cache/current.env'));boundary(runs);fs.mkdirSync(path.join(root,'.cache/check.lock'));locked=true;active=runCreate();if(['browser','fresh','build'].includes(mode)){active.env.APP_URL='http://127.0.0.1:8000';save(active);}fs.writeFileSync(path.join(root,'.cache/current.env'),fs.readFileSync(active.envFile));
  boundary(path.join(root,'.cache/mysql'));composeCommand=await ensureCompose(root);compose(['up','-d','--wait','mysql']);compose(['build','php']);
  if(mode==='compatibility'){
   command(['docker','run','--rm','-v',`${root}:/app`,'-w','/app','-e','COMPOSER_HOME=/app/.cache/composer','maxs-test-php:8.4','composer','install','--no-interaction','--no-scripts']);result.guards=guardChecks();
   result.phpVersion=php(['php','--version']).output;result.imageCapabilities=php(['php','tools/testing/image-capabilities.php']).output;result.nodeVersion=node(['node','--version']).output;
   result.composerValidate=php(['composer','validate','--no-check-publish']).output;
   php(['composer','check-platform-reqs']);result.composerAudit=command(['docker','run','--rm','-v',`${root}:/app`,'-w','/app','-e','COMPOSER_HOME=/app/.cache/composer','maxs-test-php:8.4','composer','audit','--locked','--format=json'],{allowFailure:true});
   node(['npm','ci']);node(['node','--input-type=module','-e',"import {v4,validate,version} from 'uuid';const ids=Array.from({length:100},()=>v4());if(new Set(ids).size!==100||ids.some(id=>!validate(id)||version(id)!==4))throw Error('UUID v4 consumer contract');console.log('UUID v4 named ESM consumer contract passed');"]);result.npmAudit=node(['npm','audit','--json'],{allowFailure:true});node(['npm','run','build']);
   fresh();php(['php','tools/testing/providers.php']);result.sessions=await sessions();
   result.inheritedDrift=php(['php','vendor/bin/phpunit','--colors=never','--fail-on-risky','--fail-on-warning','--log-junit',`storage/testing/runs/${active.id}/inherited-junit.xml`],active,{allowFailure:true});
   result.phpSuite={exit:result.inheritedDrift.exit,...phpSummary(path.join(active.dir,'inherited-junit.xml'),result.inheritedDrift.output)};
   if(result.inheritedDrift.exit!==0||result.composerAudit.exit!==0||result.npmAudit.exit!==0)throw Error('Compatibility failed: PHPUnit drift or dependency audit remains unresolved');
  } else if(mode==='fresh'){
   command(['docker','run','--rm','-v',`${root}:/app`,'-w','/app','-e','COMPOSER_HOME=/app/.cache/composer','maxs-test-php:8.4','composer','install','--no-interaction','--no-scripts']);
   php(['composer','validate','--no-check-publish']);php(['composer','check-platform-reqs']);
   node(['npm','ci']);result.build=node(['npm','run','build'],{allowFailure:true});
   result.composerAudit=command(['docker','run','--rm','-v',`${root}:/app`,'-w','/app','-e','COMPOSER_HOME=/app/.cache/composer','maxs-test-php:8.4','composer','audit','--locked','--format=json'],{allowFailure:true});
   result.npmAudit=node(['npm','audit','--json'],{allowFailure:true});
   result.guards=guardChecks();fresh();php(['php','tools/testing/providers.php']);result.sessions=await sessions();
   const failures=[];
   if(result.composerAudit.exit!==0)failures.push('Composer audit');
   if(result.npmAudit.exit!==0)failures.push('npm audit');
   try {
    fresh();
    const check=php(['php','vendor/bin/phpunit','--colors=never','--fail-on-risky','--fail-on-warning','--log-junit',`storage/testing/runs/${active.id}/phpunit.xml`],active,{allowFailure:true});
    result.phpSuite={exit:check.exit,...phpSummary(path.join(active.dir,'phpunit.xml'),check.output)};
    if(check.exit!==0)failures.push('PHPUnit');
   } catch(error){result.phpSuite={...result.phpSuite,error:String(error.stack||error)};failures.push('PHPUnit setup/report');}
   try {
    // PHP suites may leave state behind: build an independent browser fixture database.
    fresh();await server();
    const check=browser('tests/Browser',active,{allowFailure:true});
    result.browserSuite={exit:check.exit,...browserSummary(path.join(active.dir,'playwright/results.json'))};
    if(check.exit!==0)failures.push('Playwright');
   } catch(error){result.browserSuite={...result.browserSuite,error:String(error.stack||error)};failures.push('Playwright setup/report');}
   console.log(JSON.stringify({buildExit:result.build.exit,php:result.phpSuite,browser:result.browserSuite},null,2));
   if(result.build.exit!==0)failures.push('production build');
   if(failures.length)throw Error(`Integrated verification failed: ${failures.join(', ')}`);
  }
  else if(mode==='php'){if(!arg||!arg.startsWith('tests/')||!fs.existsSync(path.join(root,arg)))throw Error('Existing tests/ path required');boundary(path.resolve(root,arg),path.join(root,'tests'));php(['php','tools/testing/database.php']);php(['php','vendor/bin/phpunit',arg]);}
  else if(mode==='browser'){if(!arg||!arg.startsWith('tests/')||!fs.existsSync(path.join(root,arg)))throw Error('Existing tests/ path required');boundary(path.resolve(root,arg),path.join(root,'tests'));fresh();const base=await server();browser(arg,active);result.baseURL=base;}
  else if(mode==='build'){node(['npm','ci']);node(['npm','run','build']);fresh();const base=await server();const response=await http(base+'/login');assert.equal(response.status,200);assert.match(await response.text(),/\/build\/assets\//);browser(fs.existsSync(path.join(root,'tests/Browser/production.spec.js'))?'tests/Browser/production.spec.js':'tests/Browser/ProductionAssets.spec.js',active);result.productionAssetSmoke=true;}
  else throw Error('Mode must be compatibility, php, browser, build, fresh, review, staging or launch');
 }
 result.status='passed';
} catch(error){result.error=String(error.stack||error);process.exitCode=1;console.error(result.error);}
finally{try{const state=contentState(root);result.outputContentHash=state.contentHash;if(result.contentHash!==state.contentHash){result.staleContent=true;result.status='failed';result.error=[result.error,'Checkout content changed while verification was running'].filter(Boolean).join('\n');process.exitCode=1;}}catch(error){result.status='failed';result.error=String(error);process.exitCode=1;}for(const name of servers){command(['docker','rm','-f',name],{allowFailure:true});}result.finishedAt=new Date().toISOString();result.commands=commands;if(active){fs.writeFileSync(path.join(active.dir,'result.json'),JSON.stringify(result,null,2));console.log(`Evidence: ${active.dir}/result.json`);}if(locked)fs.rmdirSync(path.join(root,'.cache/check.lock'));}
