/** Native Bootstrap 3/browser regressions; no DNS, database or cloud requests.
 * Run: node tests/browser-regressions.mjs
 * Set DNSMGR_PLAYWRIGHT_MODULE / DNSMGR_CHROME for external runtime installs.
 */
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { pathToFileURL, fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const modulePath = process.env.DNSMGR_PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = await import(modulePath.startsWith('/') ? pathToFileURL(modulePath).href : modulePath);
const browser = await chromium.launch({headless: true, ...(process.env.DNSMGR_CHROME ? {executablePath: process.env.DNSMGR_CHROME} : {})});
const reports = [];
const asset = file => fs.readFileSync(path.join(root, 'public/static', file), 'utf8');
const skins = fs.readdirSync(path.join(root, 'public/static/css/skins')).filter(n => n.endsWith('.css')).map(n => n.slice(0, -4));
function formatter(file, field) {
 const source = fs.readFileSync(path.join(root, 'app/view', file + '.html'), 'utf8');
 const start = source.indexOf(`field: '${field}'`);
 assert(start !== -1, `${file}: field ${field}`);
 const offset = source.indexOf('formatter: function', start) + 'formatter: '.length;
 assert(offset > start, `${file}: formatter ${field}`);
 // Let the JS parser identify the function end; this handles quotes and nesting.
 for (let end = source.indexOf('}', offset); end >= 0; end = source.indexOf('}', end + 1)) {
  const code = source.slice(offset, end + 1);
  try { Function(`return (${code})`); return code; } catch {}
 }
 throw new Error(`${file}: invalid formatter ${field}`);
}
try {
 const page = await browser.newPage();
 await page.route('**/*', route => route.abort());
 const css = asset('css/bootstrap-3.4.1.min.css') + asset('css/app.min.css') + asset('css/modern.css');
 const menuHtml = '<div class="panel" style="height:180px;overflow:hidden;margin:20px"><div class="table-responsive" style="height:110px"><table class="table" style="min-width:700px"><tbody><tr><td>Hermes</td><td style="width:130px"><div class="btn-group"><button class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-expanded="false">更多 <span class="caret"></span></button><ul class="dropdown-menu dropdown-menu-right"><li><a href="#reset">重置Secret</a></li><li><a href="#audit">查看调用记录</a></li><li class="divider"></li><li><a href="#delete">删除</a></li></ul></div></td></tr></tbody></table></div></div>';
 for (const width of [1440, 390]) {
  await page.setViewportSize({width, height: 800});
  await page.setContent(`<style>${css}</style>${menuHtml}`);
  await page.addScriptTag({content: asset('js/jquery-3.7.1.min.js')});
  await page.addScriptTag({content: asset('js/bootstrap-3.4.1.min.js')});
  await page.addScriptTag({content: asset('js/zn.js')});
  for (const skin of skins) {
   await page.evaluate(({skin,css}) => {document.body.className = skin; let style=document.getElementById('skinStyle'); if(!style){style=document.createElement('style');style.id='skinStyle';document.head.appendChild(style);}style.textContent=css; const el = document.querySelector('.table-responsive'); el.scrollLeft = el.scrollWidth;}, {skin,css:asset('css/skins/'+skin+'.css')});
   await page.click('[data-toggle="dropdown"]');
   const geometry = await page.evaluate(() => {
    const menu = document.querySelector('.zn-floating-dropdown'); if (!menu) return null;
    const r = menu.getBoundingClientRect(); const last = menu.querySelector('li:last-child a').getBoundingClientRect();
    const hit = document.elementFromPoint(last.left + 15, last.top + last.height / 2);
    return {parent: menu.parentNode.tagName, x:r.x, right:r.right, y:r.y, bottom:r.bottom, visible: !!hit && menu.contains(hit)};
   });
   assert(geometry && geometry.parent === 'BODY' && geometry.visible, `${skin}/${width}: menu last item clipped`);
   assert(geometry.x >= 0 && geometry.right <= width && geometry.y >= 0 && geometry.bottom <= 800, `${skin}/${width}: outside viewport`);
   await page.evaluate(() => document.querySelector('.table-responsive').dispatchEvent(new Event('scroll')));
   await page.waitForTimeout(20);
   assert(await page.locator('.zn-floating-dropdown').isVisible(), `${skin}/${width}: stale scroll closed the newly opened menu`);
   await page.locator('[data-toggle="dropdown"]').focus();
   await page.keyboard.press('ArrowDown');
   assert(await page.evaluate(() => document.activeElement.closest('.zn-floating-dropdown') !== null), `${skin}/${width}: keyboard focus`);
   await page.keyboard.press('Escape');
   assert.equal(await page.locator('.zn-floating-dropdown').count(), 0, `${skin}/${width}: menu not restored`);
   await page.click('[data-toggle="dropdown"]');
   await page.locator('.zn-floating-dropdown a[href="#audit"]').click();
   assert.equal(await page.locator('.zn-floating-dropdown').count(), 0, `${skin}/${width}: action click did not close`);
   if (width === 390) {
    await page.click('[data-toggle="dropdown"]');
    await page.evaluate(() => {document.querySelector('.table-responsive').scrollLeft = 0;});
    await page.waitForTimeout(30);
    assert.equal(await page.locator('.zn-floating-dropdown').count(), 0, `${skin}/${width}: moved anchor must close its menu`);
   }
   reports.push({test:'clipped_dropdown', skin, width, passed:true});
  }
 }
 const probes = [
  ['domain/domain','name','<img src=x onerror="window.__xss=1">.example',{id:42}],
  ['domain/domain','category_name','<svg onload="window.__xss=1">',{}],
  ['schedule/stask','rr','<img src=x onerror="window.__xss=1">',{domain:'<svg onload="window.__xss=1">',remark:'" onmouseover="window.__xss=1'}],
  ['cert/certorder','domains',['<img src=x onerror="window.__xss=1">'],{}],
  ['cert/certorder','status',-1,{error:"');window.__xss=1;//\"><img src=x onerror=\"window.__xss=1\">",retrytime:null}],
  ['cert/deploytask','status',-1,{error:"');window.__xss=1;//\"><img src=x onerror=\"window.__xss=1\">"}],
  ['cert/cname','domain','<img src=x onerror="window.__xss=1">',{}],
  ['domain/record','action',null,{RecordId:"');window.__xss=1;//\"",Type:'TXT',Name:'txt',Status:'1'}],
  ['cloudflare/tunnels','action',null,{id:"');window.__xss=1;//\"",name:"');window.__xss=1;//\""}],
  ['cloudflare/hostnames','action',null,{id:"');window.__xss=1;//\"",hostname:"');window.__xss=1;//\""}],
 ];
 for (const [file, field, value, row] of probes) {
  const code = formatter(file,field);
  await page.evaluate(({code,value,row,field}) => {
   window.__xss = 0;
   window.showmsg = message => {window.__shownError = message;};
   window.dnsconfig={status:true,remark:1};
   ['editframe','setStatus','delItem','setRemark','checkRecord','showToken','openPublicHostnames','openCidrRoutes','openHostnameRoutes','deleteTunnel','openEditDialog','openVerificationDialog','openCfOptimizedForHostname','openDcvForHostname','deleteHostname'].forEach(name=>window[name]=function(){});
   window.escapeJs = window.jsEscape;
   const fn = Function('return ('+code+')')();
   let result;
   try {result = fn(value,row,0);} catch(error) {throw new Error(error.message+' in '+code);}
   const target = document.createElement('div');target.id='probe';target.innerHTML=result;document.body.appendChild(target);
   target.querySelectorAll('.tips[onclick]').forEach(el => el.click());
   if(field==='action')target.querySelectorAll('a[href^="javascript:"]').forEach(el=>el.click());
  },{code,value,row,field});
  await page.waitForTimeout(40);
  assert.equal(await page.evaluate(() => window.__xss),0,`${file}/${field}: XSS executed`);
  await page.evaluate(() => document.getElementById('probe').remove());
  reports.push({test:'stored_xss_formatter',file,field,passed:true});
 }
 await page.route('http://dnsmgr.fixture/**', route => route.fulfill({contentType:'text/html',body:'<meta name="csrf-token" content="fixture-token">'}));
 await page.goto('http://dnsmgr.fixture/?value=a%3Db+c&broken=%E0%A4%A&empty=&__proto__=safe');
 await page.addScriptTag({content:asset('js/jquery-3.7.1.min.js')});
 await page.addScriptTag({content:asset('js/zn.js')});
 await page.addScriptTag({content:asset('js/custom.js')});
 const parsed = await page.evaluate(() => ({value:window.$_GET.value,broken:window.$_GET.broken,empty:window.$_GET.empty,proto:window.$_GET.__proto__,inherited:Object.getPrototypeOf(window.$_GET)}));
 assert.equal(parsed.value,'a=b c');assert.equal(parsed.proto,'safe');assert.equal(parsed.inherited,null);
 let ajaxToken;
 await page.route('http://dnsmgr.fixture/csrf-probe', route => {ajaxToken=route.request().headers()['x-csrf-token'];return route.fulfill({contentType:'application/json',body:'{"code":0}'});});
 await page.evaluate(() => new Promise((resolve,reject) => jQuery.post('/csrf-probe',{},resolve,'json').fail(reject)));
 assert.equal(ajaxToken,'fixture-token');
 reports.push({test:'same_origin_csrf_header',passed:true});
 reports.push({test:'query_parameters',passed:true});
 console.log(JSON.stringify({passed:reports.length, failed:0, scope:'actual native assets and explicit project formatters; isolated browser fixtures',results:reports},null,2));
} finally {await browser.close();}
