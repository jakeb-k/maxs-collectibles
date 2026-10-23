import fs from 'node:fs';
export function phpSummary(file, output=''){
 const xml=fs.readFileSync(file,'utf8');
 const header=xml.match(/<testsuite\s[^>]*>/)?.[0];
 if(!header)throw Error('PHPUnit JUnit report has no suite');
 const count=(name)=>Number(header.match(new RegExp(`\\b${name}="(\\d+)"`))?.[1]||0);
 output=output.replace(/\x1b\[[0-9;]*m/g,'');
 const risky=Number(output.match(/Risky:\s*(\d+)/)?.[1]||0);
 const warnings=Number(output.match(/Warnings:\s*(\d+)/)?.[1]||0);
 const total=count('tests'),failed=count('failures'),errors=count('errors'),skipped=count('skipped');
 // PHPUnit risk is an additional issue: a failing test may also be risky.
 const riskySection=output.split(/There (?:were|was) \d+ risky tests?:/)[1]?.split(/\n(?:FAILURES!|OK|Tests:)/)[0]||'';
 const riskyNames=[...riskySection.matchAll(/^\d+\) ([^\n]+)::([^\n]+)$/gm)].map(match=>`${match[1]}::${match[2]}`);
 const unsuccessful=new Set();
 for(const match of xml.matchAll(/<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g)){
  if(!/<(?:failure|error|skipped)\b/.test(match[2]||''))continue;
  const attribute=(name)=>match[1].match(new RegExp(`\\b${name}="([^"]+)"`))?.[1];
  unsuccessful.add(`${attribute('class')}::${attribute('name')}`);
 }
 const riskyOnly=riskyNames.filter(name=>!unsuccessful.has(name)).length;
 return {total,passed:total-failed-errors-skipped-riskyOnly,failed,errors,risky,skipped,warnings,riskyOverlapping:risky-riskyOnly};
}
export function browserSummary(file){
 const report=JSON.parse(fs.readFileSync(file,'utf8'));
 if(!report.stats)throw Error('Playwright report has no stats');
 const {expected=0,unexpected=0,skipped=0,flaky=0}=report.stats;
 return {total:expected+unexpected+skipped+flaky,passed:expected,failed:unexpected,errors:report.errors?.length||0,risky:0,skipped,flaky};
}
