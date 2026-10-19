// Optional local runner when native PHP is unavailable. CI uses tests/run.php.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL, fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const moduleDir = process.env.PHP_WASM_NODE_MODULE_DIR;
const require = createRequire(moduleDir ? path.join(moduleDir, 'loader.cjs') : import.meta.url);
const wasmPath = require.resolve('@php-wasm/node');
const { loadNodeRuntime } = await import(pathToFileURL(wasmPath.replace(/\.cjs$/, '.js')));
const universalPath = require.resolve('@php-wasm/universal', { paths: [path.dirname(wasmPath)] });
const { PHP } = await import(pathToFileURL(universalPath.replace(/\.cjs$/, '.js')));
const php = new PHP(await loadNodeRuntime(process.env.PHP_VERSION || '8.3', { emscriptenOptions: { processId: 1 } }));
const phpFiles = [];
function copy(from, to) {
  if (!php.isDir(to)) php.mkdir(to);
  for (const ent of fs.readdirSync(from, { withFileTypes: true })) {
    if (['.git', 'vendor', 'node_modules', 'runtime'].includes(ent.name)) continue;
    const source = path.join(from, ent.name), target = to + '/' + ent.name;
    if (ent.isDirectory()) copy(source, target);
    else if (/\.(php|html|json|css|js)$/.test(ent.name)) {
      php.writeFile(target, fs.readFileSync(source));
      if (ent.name.endsWith('.php')) phpFiles.push(target);
    }
  }
}
copy(root, '/project'); php.mkdir('/project/runtime');
const lint = await php.run({ code: '<?php $files=json_decode(' + JSON.stringify(JSON.stringify(phpFiles)) + ',true);$errors=[];foreach($files as $file){try{token_get_all(file_get_contents($file),TOKEN_PARSE);}catch(Throwable $e){$errors[]=$file.": ".$e->getMessage();}}echo json_encode(["php"=>PHP_VERSION,"files"=>count($files),"syntax_errors"=>$errors]);if($errors)exit(1);' });
console.log(lint.text);
let failed = lint.exitCode ? 1 : 0;
const requested = process.argv.slice(2);
const suites = fs.readdirSync(path.join(root, 'tests')).filter(name => name.endsWith('_test.php') && (!requested.length || requested.includes(name))).sort();
for (const suite of suites) {
  try {
    const result = await php.run({ code: "<?php chdir('/project'); require '/project/tests/" + suite + "';" });
    process.stdout.write(suite + ': ' + result.text + '\n');
    if (result.errors) process.stderr.write(result.errors + '\n');
    if (result.exitCode) failed++;
  } catch (error) { failed++; console.error(suite, error.response?.errors || error.message); }
}
php.exit();
console.log(`${suites.length} suites, ${failed} failures`);
if (!suites.length || failed) process.exitCode = 1;
