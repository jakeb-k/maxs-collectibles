import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {ensureCompose} from './ensure-compose.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
function fixture(){
 const base=path.join(root,'.cache');fs.mkdirSync(base,{recursive:true});
 const folder=fs.mkdtempSync(path.join(base,'boundary-check-'));
 const checkout=path.join(folder,'checkout');fs.mkdirSync(path.join(checkout,'tools/testing'),{recursive:true});
 for(const file of ['check.mjs','testing/ensure-compose.mjs','testing/environment.mjs','testing/results.mjs','testing/content-state.mjs'])fs.copyFileSync(path.join(root,'tools',file),path.join(checkout,'tools',file));
 return {folder,checkout,outside:path.join(folder,'outside')};
}
for(const location of ['.cache','storage','.cache/check.lock']){
 test(`Runner refuses ${location} symlink before creating outside directories or invoking Docker`,()=>{
  const {folder,checkout,outside}=fixture();
  try{
   fs.mkdirSync(outside);const target=path.join(checkout,location);fs.mkdirSync(path.dirname(target),{recursive:true});fs.symlinkSync(outside,target);
   const check=spawnSync(process.execPath,[path.join(checkout,'tools/check.mjs'),'fresh'],{env:{PATH:''},encoding:'utf8'});
   assert.notEqual(check.status,0);assert.match(check.stderr,/Symlink path/);assert.deepEqual(fs.readdirSync(outside),[]);
   assert.equal(fs.existsSync(path.join(checkout,'storage/testing/runs')),false);
  }finally{fs.rmSync(folder,{recursive:true,force:true});}
 });
}
test('Runner rejects dangling ancestor symlinks before creating directories',()=>{
 const {folder,checkout,outside}=fixture();
 try{
  fs.symlinkSync(outside,path.join(checkout,'storage'));
  const check=spawnSync(process.execPath,[path.join(checkout,'tools/check.mjs'),'fresh'],{env:{PATH:''},encoding:'utf8'});
  assert.notEqual(check.status,0);assert.match(check.stderr,/Symlink path/);assert.equal(fs.existsSync(outside),false);
 }finally{fs.rmSync(folder,{recursive:true,force:true});}
});
for(const location of ['.cache/bin','.cache/bin/docker-compose']){
 test(`Compose fallback rejects ${location} symlink without a write/download`,async()=>{
  const {folder,checkout,outside}=fixture();const previous=process.env.PATH;
  try{
   fs.mkdirSync(outside);const target=path.join(checkout,location);fs.mkdirSync(path.dirname(target),{recursive:true});fs.symlinkSync(outside,target);
   process.env.PATH='';await assert.rejects(ensureCompose(checkout),/Symlink Compose path/);assert.deepEqual(fs.readdirSync(outside),[]);
  }finally{process.env.PATH=previous;fs.rmSync(folder,{recursive:true,force:true});}
 });
}
