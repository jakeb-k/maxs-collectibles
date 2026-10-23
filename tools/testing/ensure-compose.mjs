import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
export async function ensureCompose(root){
 for(const candidate of [['docker','compose'],['docker-compose']]){
  const probe=spawnSync(candidate[0],[...candidate.slice(1),'version'],{encoding:'utf8'});
  if(probe.status===0)return candidate;
 }
 const binary=path.join(root,'.cache/bin/docker-compose');
 if(fs.realpathSync(root)!==root)throw Error('Symlink Compose root rejected');
 let current=root;
 for(const segment of path.relative(root,binary).split(path.sep)){
  current=path.join(current,segment);
  if(fs.lstatSync(current,{throwIfNoEntry:false})?.isSymbolicLink())throw Error('Symlink Compose path rejected');
 }
 // Checked fallback for older macOS ARM Docker installations without the plugin.
 if(process.platform!=='darwin'||process.arch!=='arm64')throw Error('Docker Compose is required. Install Docker Desktop or the Docker Compose plugin, then retry.');
 const checksum='bd714a42b46e51757fc1121085b3096b9064e3f80d3ca5a991e33f44df4f43c9';
 if(!fs.existsSync(binary)){
  const response=await fetch('https://github.com/docker/compose/releases/download/v5.6.0/docker-compose-darwin-aarch64');
  if(!response.ok)throw Error('Compose download failed');const bytes=Buffer.from(await response.arrayBuffer());
  if(crypto.createHash('sha256').update(bytes).digest('hex')!==checksum)throw Error('Compose checksum mismatch');
  fs.mkdirSync(path.dirname(binary),{recursive:true});fs.writeFileSync(binary,bytes,{mode:0o755});
 }
 if(crypto.createHash('sha256').update(fs.readFileSync(binary)).digest('hex')!==checksum)throw Error('Compose binary changed');
 return [binary];
}
