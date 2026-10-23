import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {contentState} from './content-state.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
test('Fingerprint detects tracked/untracked content and mode changes, ignores generated files and permits commit metadata',()=>{
 const parent=path.join(root,'.cache');fs.mkdirSync(parent,{recursive:true});
 const directory=fs.mkdtempSync(path.join(parent,'content-state-check-'));
 function git(args){const result=spawnSync('git',args,{cwd:directory,encoding:'utf8'});assert.equal(result.status,0,result.stderr);}
 try{
  git(['init','--quiet']);fs.writeFileSync(path.join(directory,'.gitignore'),'generated/\n');fs.writeFileSync(path.join(directory,'app.txt'),'one');git(['add','.']);
  const initial=contentState(directory).contentHash;
  fs.mkdirSync(path.join(directory,'generated'));fs.writeFileSync(path.join(directory,'generated/log'),'ignored');assert.equal(contentState(directory).contentHash,initial);
  git(['-c','user.name=Fixture','-c','user.email=fixture@example.test','-c','commit.gpgsign=false','-c','core.hooksPath=/dev/null','commit','--quiet','-m','Fixture commit']);assert.equal(contentState(directory).contentHash,initial);assert.match(contentState(directory).inputRevision,/^[0-9a-f]{40}$/);
  fs.writeFileSync(path.join(directory,'app.txt'),'two');assert.notEqual(contentState(directory).contentHash,initial);
  fs.writeFileSync(path.join(directory,'app.txt'),'one');assert.equal(contentState(directory).contentHash,initial);
  fs.chmodSync(path.join(directory,'app.txt'),0o755);assert.notEqual(contentState(directory).contentHash,initial);fs.chmodSync(path.join(directory,'app.txt'),0o644);
  fs.writeFileSync(path.join(directory,'new.txt'),'untracked');assert.notEqual(contentState(directory).contentHash,initial);fs.unlinkSync(path.join(directory,'new.txt'));assert.equal(contentState(directory).contentHash,initial);
 }finally{fs.rmSync(directory,{recursive:true,force:true});}
});
