import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {phpSummary,browserSummary} from './results.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
function report(name,text,verify){
 const folder=path.join(root,'.cache/report-checks');fs.mkdirSync(folder,{recursive:true});
 const file=path.join(folder,name);fs.writeFileSync(file,text);
 try{verify(file);}finally{fs.unlinkSync(file);}
}
test('PHP risk counts overlap failures without subtracting them twice',()=>{
 report('overlap.xml','<testsuites><testsuite tests="4" failures="1" errors="0" skipped="1"><testcase name="pass" class="Tests"/><testcase name="fail" class="Tests"><failure/></testcase><testcase name="risk" class="Tests"/><testcase name="skip" class="Tests"><skipped/></testcase></testsuite></testsuites>',file=>{
  const result=phpSummary(file,'There were 2 risky tests:\n\n1) Tests::fail\nDetails\n\n2) Tests::risk\nDetails\n\nTests: 4, Risky: 2.');
  assert.deepEqual(result,{total:4,passed:1,failed:1,errors:0,risky:2,skipped:1,warnings:0,riskyOverlapping:1});
 });
});
test('Browser reports failures, skips, flaky cases and global errors separately',()=>{
 report('browser.json',JSON.stringify({stats:{expected:2,unexpected:1,skipped:3,flaky:1},errors:[{message:'setup failed'}]}),file=>{
  assert.deepEqual(browserSummary(file),{total:7,passed:2,failed:1,errors:1,risky:0,skipped:3,flaky:1});
 });
});
test('Missing suite data fails reporting instead of reporting a pass',()=>{
 report('invalid.xml','<testsuites/>',file=>assert.throws(()=>phpSummary(file)));
 report('invalid.json','{}',file=>assert.throws(()=>browserSummary(file)));
});
