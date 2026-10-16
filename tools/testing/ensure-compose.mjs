import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
export async function ensureCompose(root){
 const binary=path.join(root,'.cache/bin/docker-compose');
 // This verified fallback is for this project's macOS ARM workstation.
 if(process.platform!=='darwin'||process.arch!=='arm64')throw Error('Install Docker Compose on this platform; fallback supports macOS ARM only');
 const checksum='bd714a42b46e51757fc1121085b3096b9064e3f80d3ca5a991e33f44df4f43c9';
 if(!fs.existsSync(binary)){
  const response=await fetch('https://github.com/docker/compose/releases/download/v5.6.0/docker-compose-darwin-aarch64');
  if(!response.ok)throw Error('Compose download failed');const bytes=Buffer.from(await response.arrayBuffer());
  if(crypto.createHash('sha256').update(bytes).digest('hex')!==checksum)throw Error('Compose checksum mismatch');
  fs.mkdirSync(path.dirname(binary),{recursive:true});fs.writeFileSync(binary,bytes,{mode:0o755});
 }
 if(crypto.createHash('sha256').update(fs.readFileSync(binary)).digest('hex')!==checksum)throw Error('Compose binary changed');
 return binary;
}
