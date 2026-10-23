import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
export function contentState(root){
 const files=spawnSync('git',['ls-files','--cached','--others','--exclude-standard','-z'],{cwd:root,encoding:'utf8'});
 if(files.status!==0)throw Error(`Cannot fingerprint Git checkout: ${files.stderr}`);
 const hash=crypto.createHash('sha256');
 for(const name of [...new Set(files.stdout.split('\0').filter(Boolean))].sort()){
  const file=path.join(root,name);const stat=fs.lstatSync(file,{throwIfNoEntry:false});
  const kind=!stat?'missing':stat.isSymbolicLink()?'symlink':stat.isFile()?'file':'other';
  hash.update(JSON.stringify({name,kind,mode:stat?stat.mode&0o777:null})+'\0');
  if(kind==='file')hash.update(fs.readFileSync(file));
  if(kind==='symlink')hash.update(fs.readlinkSync(file));
  hash.update('\0');
 }
 const revision=spawnSync('git',['rev-parse','--verify','HEAD'],{cwd:root,encoding:'utf8'});
 return {inputRevision:revision.status===0?revision.stdout.trim():null,contentHash:hash.digest('hex')};
}
