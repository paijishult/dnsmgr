// Optional local adapter: run the SAME native PHP renderer when PHP is unavailable.
// CI runs php tests/render-pages.php directly with the Composer autoloader.
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {loadCheckedPHPRuntime} from './php-wasm-runtime.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const vendor=process.env.DNSMGR_COMPOSER_VENDOR||path.join(root,'vendor');
const output=process.env.DNSMGR_PAGE_FIXTURES||path.join(process.env.TMPDIR||'/tmp','dnsmgr-pages');
const require=createRequire(process.env.PHP_WASM_NODE_MODULE_DIR?path.join(process.env.PHP_WASM_NODE_MODULE_DIR,'loader.cjs'):import.meta.url);
const wasmPath=require.resolve('@php-wasm/node');
const {loadNodeRuntime}=await import(pathToFileURL(wasmPath.replace(/\.cjs$/,'.js')));
const {PHP}=await import(pathToFileURL(require.resolve('@php-wasm/universal',{paths:[path.dirname(wasmPath)]}).replace(/\.cjs$/,'.js')));
const php=await loadCheckedPHPRuntime({PHP,loadNodeRuntime});
function copy(from,to) {
 if(!php.isDir(to))php.mkdir(to);
 for(const entry of fs.readdirSync(from,{withFileTypes:true})) {
  if(['.git','node_modules','runtime','vendor'].includes(entry.name))continue;
  const source=path.join(from,entry.name),target=to+'/'+entry.name;
  if(entry.isDirectory())copy(source,target);
  else if(/\.(php|html|tpl|json|css)$/.test(entry.name))php.writeFile(target,fs.readFileSync(source));
 }
}
try {
 copy(root,'/project');copy(vendor,'/project/vendor');
 // Download-only local vendor directories have no Composer-generated autoloader.
 // Generate a local PSR-4 loader from the lockfile; CI exercises Composer's loader.
 if(!php.fileExists('/project/vendor/autoload.php')) {
  const lock=JSON.parse(fs.readFileSync(path.join(root,'composer.lock'),'utf8'));
  const mapping={'app\\':['/project/app/']},autoload=[];
  for(const pkg of lock.packages) {
   const base='/project/vendor/'+pkg.name+'/';
   for(const [prefix,dirs] of Object.entries(pkg.autoload?.['psr-4']||{})) {
    mapping[prefix]??=[];
    for(const dir of Array.isArray(dirs)?dirs:[dirs])mapping[prefix].push((base+dir).replace(/\/$/,'')+'/');
   }
   for(const file of pkg.autoload?.files||[])autoload.push(base+file);
  }
  php.writeFile('/project/vendor/autoload.php',`<?php
$maps=json_decode(${JSON.stringify(JSON.stringify(mapping))},true);
spl_autoload_register(function($class)use($maps){foreach($maps as $prefix=>$dirs){if(str_starts_with($class,$prefix)){foreach($dirs as $dir){$file=$dir.str_replace('\\\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file)){require $file;return;}}}}});
foreach(json_decode(${JSON.stringify(JSON.stringify(autoload))},true) as $file)require_once $file;
`);
 }
 php.writeFile('/render.php',"<?php putenv('DNSMGR_PAGE_FIXTURES=/fixture-pages');putenv('DNSMGR_COMPOSER_VENDOR=/project/vendor');require '/project/tests/render-pages.php';");
 const result=await php.run({scriptPath:'/render.php'});
 process.stdout.write(result.text);
 if(result.errors)process.stderr.write(result.errors);
 if(php.fileExists('/fixture-pages/manifest.json')) {
  const manifest=php.readFileAsText('/fixture-pages/manifest.json');
  fs.mkdirSync(output,{recursive:true});fs.writeFileSync(path.join(output,'manifest.json'),manifest);
  for(const entry of JSON.parse(manifest))if(!entry.error) {
   const file=entry.name.replaceAll('/','__')+'.html';fs.writeFileSync(path.join(output,file),php.readFileAsText('/fixture-pages/'+file));
  }
 }
 if(result.exitCode)process.exitCode=result.exitCode;
} finally {php.exit();}
