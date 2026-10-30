/** All-page native browser fixture audit. Requires pages rendered by ThinkPHP.
 * DNSMGR_PAGE_FIXTURES points to a directory with manifest.json and *.html.
 * Tests use real templates/assets and simulated AJAX; no live DB/cloud actions.
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {pathToFileURL, fileURLToPath} from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureDir = process.env.DNSMGR_PAGE_FIXTURES;
if (!fixtureDir) throw new Error('Set DNSMGR_PAGE_FIXTURES to the ThinkPHP rendered fixture directory');
const reportDir = process.env.DNSMGR_BROWSER_OUTPUT || path.join(fixtureDir, 'browser');fs.mkdirSync(reportDir,{recursive:true});
const manifest = JSON.parse(fs.readFileSync(path.join(fixtureDir,'manifest.json'))).filter(entry=>!process.env.DNSMGR_PAGE_FILTER || new RegExp(process.env.DNSMGR_PAGE_FILTER).test(entry.name));
const {chromium} = await import(process.env.DNSMGR_PLAYWRIGHT_MODULE ? pathToFileURL(process.env.DNSMGR_PLAYWRIGHT_MODULE).href : 'playwright');
const browser = await chromium.launch({headless:true,...(process.env.DNSMGR_CHROME?{executablePath:process.env.DNSMGR_CHROME}:{})});
const row = {id:1,aid:1,oid:1,did:1,uid:1000,name:'example.com',username:'fixture-admin',type:1,typename:'阿里云',icon:'aliyun.png',aremark:'示例账户',aname:'示例账户',certtypename:'阿里云',status:1,active:1,rr:'www',domain:'example.com',domains:['example.com'],keytype:'RSA',keysize:2048,isauto:1,issuetime:'2026-10-01 10:00:00',expiretime:'2027-01-01 10:00:00',end_day:70,error:'',islock:0,remark:'fixture & <text>',category_name:'示例 & 分类',addtime:'2026-10-19 10:00:00',lasttime:'2026-10-19 10:00:00',regtime:'2026-01-01 10:00:00',checkstatus:1,recordcount:1,processid:'fixture-process',recordnum:1,ip_type:'v4',cycle:0,ttl:600,frequency:30,main_value:'192.0.2.1',backup_value:'192.0.2.2',switchdate:'1',switchtime:'10:00',switchtype:0,value:'192.0.2.1',keyid:'0123456789abcdef',scopes:['domain:read','record:write','cert:read','cert:write','cert:download'],ipwhitelist:[],ratelimit:120,calls:0,expired:false,lastip:'192.0.2.1',RecordId:'fixture-record',Name:'www',Type:'A',Line:'default',LineName:'默认',Value:'192.0.2.1',TTL:600,Weight:10,Remark:'fixture & <text>',Status:'1',SubDomain:'www',RecordCount:1,Open:1,hostname:'app.example.com',custom_origin_server:'origin.example.com',ssl_method:'txt',ssl_min_tls_version:'1.2',ssl_status:'active',ssl_validation_status:'active',verification_status:'active',created_on:'2026-10-19',connection_count:1,created_at:'2026-10-19',source:'web',target:'example.com',method:'POST',path:'/domain',code:0,duration:1,ip:'192.0.2.1',ua:'fixture',verification:{status:'passed',host:'example.com',port:443,checked_at:'2026-10-19 10:00:00'}};
const task={id:1,did:1,name:'fixture & <text>',total:3,completed:1,status:3,cancel_requested:0,attempts:1,error:'fixture <img src=x onerror="window.__xss=1">',snapshot_id:1,addtime:'2026-10-19 10:00:00',updatetime:'2026-10-19 10:01:00',expiretime:'2099-10-20 10:00:00'};
const results=[];
const mime=p=>p.endsWith('.css')?'text/css':p.endsWith('.js')?'application/javascript':p.endsWith('.woff2')?'font/woff2':p.endsWith('.woff')?'font/woff':p.endsWith('.png')?'image/png':p.endsWith('.svg')?'image/svg+xml':'application/octet-stream';
try {
 for (const entry of manifest) {
  if (entry.error) {results.push({page:entry.name,renderError:entry.error});continue;}
  const htmlFile=path.join(fixtureDir,entry.name.replaceAll('/','__')+'.html');
  const html=fs.readFileSync(htmlFile,'utf8');
  // Parse every inline JavaScript block, including scripts not reached on load.
  const parseErrors=[];
  for (const [i,tag] of [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)].entries()) {
   if (/type=["']application\/json/.test(tag[1]) || /src=/.test(tag[1])) continue;
   try {new vm.Script(tag[2],{filename:entry.name+':script'+i});} catch(e){parseErrors.push(e.message);}
  }
  for (const width of [1440,390]) {
   const page=await browser.newPage({viewport:{width,height:900}});page.setDefaultTimeout(5000);const errors=[],consoleErrors=[],requests=[];
   page.on('pageerror',e=>errors.push(e.message));
   page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
   await page.addInitScript(()=>sessionStorage.setItem('domains',JSON.stringify([{id:1,name:'example.com',type:'aliyun',icon:'aliyun.png'}])));
   await page.route('**/*',async route=>{
    const req=route.request(),url=new URL(req.url()),p=url.pathname;
    if(url.hostname!=='dnsmgr.fixture')return route.abort();
    if(p==='/__page__'&&req.method()==='GET')return route.fulfill({contentType:'text/html',body:html});
    if(p==='/verifycode')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="110" height="48"><rect width="110" height="48" fill="#f4f5f7"/><text x="18" y="32" font-size="25" fill="#444">1234</text></svg>'});
    if(p.startsWith('/static/')||p==='/favicon.ico') {
     const file=path.join(root,'public',p);
     if(!fs.existsSync(file)) {requests.push({missingAsset:p});return route.fulfill({status:404,body:''});}
     return route.fulfill({contentType:mime(p),body:fs.readFileSync(file)});
    }
    requests.push({url:p,method:req.method(),csrf:req.headers()['x-csrf-token']||null});
    let data={code:0,msg:'fixture',total:1,rows:[row],data:[]};
    if(p==='/operations/data') {
     const panel=new URLSearchParams(req.postData()||'').get('panel');
     const dangerous='fixture <img src=x onerror="window.__xss=1">';
     const panels={
      tasks:[{kind:'monitor',kind_name:'容灾监控',id:1,name:dangerous,status:'正常',progress:null,error:dangerous,updated_at:'2026-10-25 12:00:00',next_at:'2026-10-25 12:05:00',url:'/dmonitor/task'}],
      conflicts:[{domain:'example.com',rr:'www',tasks:[{kind:'monitor',id:1},{kind:'schedule',id:2}],message:dangerous}],
      matrix:[{id:1,name:dangerous,expected_sha256:'a'.repeat(64),deployed_sha256:'b'.repeat(64),expires_at:'2027-01-01',targets:[{target:dangerous,status:'failed',error:dangerous}],verification:[{host:'example.com',port:443,address:'1.1.1.1',status:'failed',observed_sha256:'b'.repeat(64),error:dangerous}]}],
      drift:[{did:1,name:'example.com',enabled:1,interval_seconds:3600,checked_at:1792886400,drifted:true,error:null,diff:{added_count:1,removed_count:1,added:[{Name:dangerous,Type:'TXT',Value:dangerous,TTL:600}],removed:[]}}],
      credentials:[{kind:'dns',aid:1,name:dangerous,provider:'cloudflare',enabled:1,status:'failed',checked_at:1792886400,rotation_days:90,expires_at:null,rotated_at:'2026-10-01',error:dangerous}],
      notifications:[{id:1,channel:'mail',status:-1,attempts:8,nexttime:0,error:dangerous}]
     };
     data={code:0,rows:panels[panel]||[],heartbeat:'2026-10-25 12:00:00'};
    } else if(p==='/operations/op')data={code:0,msg:'操作成功'};
    else if(p.startsWith('/record/snapshot/'))data={code:0,msg:'快照创建成功',snapshot_id:901};
    else if(p.startsWith('/record/history/data/'))data=req.postData()?.includes('snapshotid=')?{code:0,data:[row]}:{total:1,rows:[{id:901,label:'手动快照',record_count:1,addtime:'2026-10-20 10:00:00'}]};
    else if(p==='/system/qqbotbind')data={code:0,data:{message:'绑定 '+ 'f'.repeat(24)}};
    else if(p==='/batch/details')data={code:0,task,results:[{index:0,success:true},{index:1,success:false,message:task.error}]};
    else if(p==='/batch/enqueue')data={code:0,msg:'已加入后台任务',id:1};
    else if(p.startsWith('/batch/task/'))data={code:0,msg:'设置成功'};
    else if(p==='/batch/data')data={total:5,rows:[0,1,2,3,4].map((status,i)=>({...task,id:i+1,status}))};
    else if(p.startsWith('/record/preview/')||p.startsWith('/record/rollback/preview/'))data={code:0,data:{token:'fixture-review-token',expires:Math.floor(Date.now()/1000)+600,changes:[{before:null,operation:{action:'add',name:'www',type:'A',value:'192.0.2.1',line:'default',ttl:600}}]}};
    else if(p==='/logout')data={code:-1,msg:'fixture logout request verified'};
    else if(entry.name==='domain/qingcloud'&&p.startsWith('/record/data'))data={total:0,rows:[]};
    else if(p.includes('/record/quickinfo/'))data={code:0,data:{recordLine:[{id:'default',name:'默认'}],minTTL:600}};
    else if(p.includes('/category/list'))data={code:0,data:[{id:1,name:'示例分类'}]};
    else if(p.includes('dcv_delegation_uuid'))data={code:0,data:{uuid:'fixture-uuid'}};
    else if(p.includes('/group/'))data={code:0,data:[]};
    else if(p.includes('/record/group/'))data={code:0,data:[]};
    else if(p.includes('fallback/get'))data={code:0,data:{origin:'origin.example.com'}};
    else if(p.includes('txttargets'))data={code:0,data:{candidates:[]}};
    if(entry.name==='apikey/index')data.rows=[{...row,expiretime:null,lasttime:null}];
    if(entry.name==='cloudflare/tunnels')data.rows=[{...row,type:'cfd_tunnel',status:'healthy'}];
    return route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
   });
   await page.goto('http://dnsmgr.fixture/__page__',{waitUntil:'networkidle'});
   await page.waitForTimeout(150);
   const interactions = [], interactionErrors = [];
   async function check(label, fn) {try {await fn(); interactions.push(label);} catch(e) {interactionErrors.push(label+': '+e.message);}}
   const toggles = page.locator('.content [data-toggle="dropdown"]');
   for (let i=0;i<await toggles.count();i++) {
    const toggle=toggles.nth(i); if(!await toggle.isVisible())continue;
    await check('dropdown '+i,async()=>{
     await toggle.click();
     const menu=page.locator('.zn-floating-dropdown');
     if(await menu.count()) {
      const box=await menu.boundingBox();
      if(!box||box.x<0||box.y<0||box.x+box.width>width+1||box.y+box.height>900)throw new Error('menu outside viewport');
      const lastLinkHit = await menu.evaluate(el => {
       const links = Array.from(el.querySelectorAll('li:not(.disabled) > a')).filter(link=>link.getClientRects().length);
       if(!links.length)return true;
       const last=links[links.length-1],rect=last.getBoundingClientRect(),hit=document.elementFromPoint(rect.left+rect.width/2,rect.top+rect.height/2);
       return !!hit&&last.contains(hit);
      });
      if(!lastLinkHit)throw new Error('last dropdown action is clipped or obscured');
      if(entry.name==='apikey/index'&&await toggle.innerText().then(t=>t.includes('更多'))) {
       // Full-page capture can resize the viewport, correctly closing the menu.
       // A viewport capture preserves the visible interaction being verified.
       await page.screenshot({path:path.join(reportDir,'apikey-menu-'+width+'.png'),fullPage:false});
       if(!await menu.isVisible())throw new Error('menu closed during proof screenshot');
      }
     }
     await toggle.press('Escape');
     if(await page.locator('.zn-floating-dropdown').count())throw new Error('menu did not restore on Escape');
    });
   }
   if(await page.locator('#znSearchBtn').count())await check('quick search',async()=>{
    await page.locator('#znSearchBtn').click();await page.locator('.zn-spot input').fill('域名');
    await page.locator('.zn-spot input').press('Escape');
    if(await page.locator('.zn-spot').evaluate(el=>el.classList.contains('show')))throw new Error('search did not close');
   });
   if(entry.name==='apikey/index')await check('API key form',async()=>{
    await page.evaluate(()=>openForm());await page.locator('#formModal').waitFor({state:'visible'});
    await page.locator('#formModal [data-dismiss="modal"]').first().click();await page.locator('#formModal').waitFor({state:'hidden'});
   });
   if(entry.name==='cert/deploy_form')await check('native Miaomiaowu task fields and provider switching',async()=>{
    await page.locator('select[name=aid]').selectOption('2');
    await page.locator('input[name=domain]').fill('*.example.com');
    if(!await page.locator('input[name=domain]').evaluate(el=>el.value==='*.example.com'&&!el.required))throw new Error('optional certificate domain missing');
    await page.locator('select[name=aid]').selectOption('1');
    await page.locator('textarea[name=sites]').waitFor({state:'visible'});
    if(await page.locator('input[name=domain]').count())throw new Error('Miaomiaowu fields leaked into another provider');
    await page.locator('select[name=aid]').selectOption('2');
    await page.locator('input[name=domain]').waitFor({state:'visible'});
   });
   if(entry.name==='cert/deploy_form')await check('TLS verification settings',async()=>{
    await page.locator('#accountform input[type=checkbox]').last().check();
    await page.locator('#verify-host').waitFor({state:'visible'});
   });
   if(entry.name==='cert/deploy_form')await check('EasyPanel target switching and SamWaf ID',async()=>{
    await page.locator('select[name=aid]').selectOption('3');
    await page.locator('input[name=target][value="0"]').waitFor({state:'visible'});
    await page.locator('input[name=name]').waitFor({state:'visible'});
    await page.locator('input[name=type][value="1"]').check();
    await page.locator('textarea[name=domains]').waitFor({state:'visible'});
    await page.locator('input[name=target][value="1"]').check();
    for(const selector of ['input[name=name]', 'input[name=type]', 'textarea[name=domains]']) {
     if(await page.locator(selector).first().isVisible())throw new Error('website fields remain visible for the panel');
    }
    await page.locator('select[name=aid]').selectOption('4');
    await page.locator('input[name=id]').waitFor({state:'visible'});
    if(await page.locator('input[name=id]').evaluate(el=>el.required))throw new Error('new SamWaf upload requires an ID');
   });
   if(entry.name==='domain/record')await check('ALIAS options and special record IDs in clipboard',async()=>{
    if(await page.locator('option[value=ALIAS]').count()!==3)throw new Error('ALIAS missing from record forms or filter');
    const copied=await page.evaluate(()=>{
     const original=document.execCommand,wrap=document.createElement('div');let value;
     wrap.innerHTML='<span id="record-value-0_[]:." data-value="target.example.net."></span><a><i class="fa fa-copy"></i></a>';document.body.appendChild(wrap);
     document.execCommand=()=>{value=document.activeElement.value;return true;};
     try {copyToClipboard(null,'#record-value-0_[]:.');return value;}
     finally {document.execCommand=original;wrap.remove();}
    });
    if(copied!=='target.example.net.')throw new Error('record ID was interpreted as a CSS selector');
   });
   if(['domain/batchadd2','domain/batchedit'].includes(entry.name))await check('ALIAS offered only for supported selected providers',async()=>{
    for(const [providers,allowed] of [[['aliyun','powerdns'],true],[['aliyun','cloudflare'],false],[[],false]]) {
     await page.evaluate(async providers=>{const app=document.querySelector('#app').__vue__;app.domainList=providers.map((type,i)=>({id:i+1,type,name:'example.com',icon:'aliyun.png',result:''}));await app.$nextTick();},providers);
     if((await page.locator('option[value=ALIAS]').count()>0)!==allowed)throw new Error('incorrect ALIAS capability for '+providers);
    }
   });
   if(entry.name==='cloudflare/hostnames')await check('async TXT batches keep each selected DNS account',async()=>{
    await page.evaluate(()=>{
     const originalResolve=resolveTxtTargets,originalSubmit=submitBatchTxtRecord;
     try {
      for(const [dataName,prefix,run] of [['batchHostnameTxtData','txtDnsProvider_',confirmBatchHostnameTxt],['batchCertTxtData','certDnsProvider_',confirmBatchCertTxt]]) {
       const pending=[],submitted=[],nodes=[];
       window[dataName]={};
       for(const [i,id] of [11,22].entries()) {
        const domain='domain'+i+'.example.com',name='_validation.'+domain;
        const select=document.createElement('select');select.id=prefix+i;select.innerHTML='<option value="'+id+'">fixture</option>';document.body.appendChild(select);nodes.push(select);
        window[dataName][domain]={items:[{row:{hostname:domain},txtName:name,txtValue:'value'+i}],targets:[{domain_id:id}]};
       }
       resolveTxtTargets=(name,value,callback)=>pending.push({name,callback});
       submitBatchTxtRecord=(name,value,target,remark,callback)=>{submitted.push([name,target.domain_id]);callback(true);};
       run();
       if(pending.length!==2)throw new Error('TXT resolution did not start for both domains');
       pending.reverse().forEach(({name,callback})=>callback([{domain_id:11},{domain_id:22}]));
       if(JSON.stringify(submitted)!==JSON.stringify([['_validation.domain1.example.com',22],['_validation.domain0.example.com',11]]))throw new Error('async callback reused another domain selection');
       nodes.forEach(node=>node.remove());window[dataName]={};
      }
     } finally {resolveTxtTargets=originalResolve;submitBatchTxtRecord=originalSubmit;layer.closeAll();}
    });
   });
   if(entry.name==='index/setpwd')await check('TOTP disable credentials',async()=>{
    await page.evaluate(()=>close_totp());await page.locator('#totp-close-form').waitFor({state:'visible'});
    if(await page.locator('#totp-close-form [name=password]').count()!==1||await page.locator('#totp-close-form [name=code]').count()!==1)throw new Error('missing credentials');
    await page.evaluate(()=>layer.closeAll());
   });
   if(await page.locator('.sidebar-menu a[href="/batch"]').count())await check('batch sidebar last and short label',async()=>{
    const last=page.locator('.sidebar-menu > li').last();
    if(await last.locator('a[href="/batch"] span').innerText()!=='批量任务')throw new Error('batch entry is not last or label is long');
   });
   if(entry.name==='domain/history')await check('snapshot saved location feedback',async()=>{
    await page.locator('#createSnapshot').click();await page.locator('#historyMessage').filter({hasText:'快照 #901 已保存'}).waitFor();
    if(!await page.locator('#snapshotList #historyMessage').isVisible())throw new Error('saved location feedback is not in the snapshot panel');
    await page.locator('.snapshot-view[data-id="901"]').waitFor();
    await page.screenshot({path:path.join(reportDir,'snapshot-saved-'+width+'.png'),fullPage:false});
    await page.locator('.snapshot-open-saved').click();await page.locator('#detailModal').waitFor({state:'visible'});await page.locator('#detailModal [data-dismiss="modal"]').first().click();await page.locator('#detailModal').waitFor({state:'hidden'});
    if(!requests.some(r=>r.url==='/record/snapshot/1'&&r.method==='POST'&&r.csrf))throw new Error('snapshot save missing POST/token');
   });
   if(entry.name==='domain/history')await check('review token consumed once',async()=>{
    await page.locator('#recordLines').fill('www 192.0.2.1');await page.locator('#previewButton').click();
    await page.locator('#enqueueChanges').waitFor({state:'visible'});
    await page.evaluate(()=>{jQuery('#enqueueChanges').trigger('click');jQuery('#applyChanges').trigger('click');jQuery('#enqueueChanges').trigger('click');});
    await page.waitForTimeout(100);
    if(requests.filter(r=>r.url==='/batch/enqueue').length!==1||requests.some(r=>r.url.startsWith('/record/apply/')))throw new Error('preview token reused by UI');
    await page.locator('#detailModal [data-dismiss="modal"]').first().click();
   });
   if(entry.name==='operations/index')await check('operations tabs, actions and escaped provider data',async()=>{
    await page.locator('#opsTable tbody tr').first().waitFor();
    await page.locator('#opsTable button').filter({hasText:'停用'}).click();
    await page.waitForResponse(r=>new URL(r.url()).pathname==='/operations/data');
    for(const tab of ['conflicts','matrix','drift','credentials','notifications']) {
     const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/operations/data');
     await page.locator('[data-panel="'+tab+'"]').click();await response;
     await page.waitForTimeout(50);
     if(tab==='matrix'||tab==='drift') {
      await page.locator('#opsTable button').first().click();await page.locator('#opsDetails').waitFor({state:'visible'});
      if(await page.locator('#opsDetails img[onerror]').count())throw new Error('unescaped detail');
      await page.locator('#opsDetails [data-dismiss="modal"]').first().click();await page.locator('#opsDetails').waitFor({state:'hidden'});
     }
     if(tab==='credentials') {
      await page.locator('#opsTable button').filter({hasText:'设置'}).click();await page.locator('#credentialModal').waitFor({state:'visible'});
      await page.locator('#credentialDays').fill('120');await page.locator('#credentialForm button[type=submit]').click();await page.locator('#credentialModal').waitFor({state:'hidden'});
     }
     if(tab==='notifications') {await page.locator('#opsTable button').filter({hasText:'重试'}).click();await page.waitForTimeout(50);}
    }
    if(await page.locator('#opsTable img[onerror]').count())throw new Error('provider output rendered as HTML');
    if(!requests.some(r=>r.url==='/operations/op'&&r.method==='POST'&&r.csrf))throw new Error('operations missing native CSRF token');
   });
   if(entry.name==='batch/index')await check('batch task details and actions',async()=>{
    if(!await page.locator('.panel-body').first().innerText().then(text=>text.includes('快照在哪里')&&text.includes('批量任务的作用')))throw new Error('missing feature explanation');
    await page.locator('#viewDomainSnapshots').click();await page.locator('#batchMessage').filter({hasText:'请先选择'}).waitFor();
    await page.locator('.task-details').first().click();await page.locator('#taskResults tr').first().waitFor();
    if(await page.locator('#taskModal img[onerror]').count())throw new Error('task failure rendered as HTML');
    await page.locator('#taskModal [data-dismiss="modal"]').first().click();await page.locator('#taskModal').waitFor({state:'hidden'});
    await page.locator('.task-operation[data-action=retry]').first().click();await page.waitForTimeout(50);
    await page.locator('.task-operation[data-action=cancel]').first().click();await page.waitForTimeout(50);
    for(const action of ['retry','cancel'])if(!requests.some(r=>r.url==='/batch/task/'+action&&r.method==='POST'&&r.csrf))throw new Error(action+' missing native POST/token');
   });
   if(entry.name==='index/index')await check('cache clear uses CSRF POST',async()=>{
    await page.evaluate(()=>cleancache());await page.waitForTimeout(50);
    if(!requests.some(r=>r.url==='/cleancache'&&r.method==='POST'&&r.csrf))throw new Error('missing cache POST/token');
   });
   if(entry.name==='system/noticeset')await check('notification tests use CSRF POST',async()=>{
    await page.evaluate(()=>startQqbotBinding());await page.locator('#qqbotBindingMessage').filter({hasText:'绑定 '+ 'f'.repeat(24)}).waitFor();
    if(!requests.some(r=>r.url==='/system/qqbotbind'&&r.method==='POST'&&r.csrf))throw new Error('binding missing POST/token');
    for(const action of ['mailtest','tgbottest','webhooktest','customwebhooktest']) {
     const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/system/'+action);
     await page.evaluate(name=>window[name](),action);await response;
     if(!requests.some(r=>r.url==='/system/'+action&&r.method==='POST'&&r.csrf))throw new Error(action+' missing notification POST/token');
     await page.evaluate(()=>layer.closeAll());
    }
   });
   if(await page.locator('#logoutButton').count())await check('logout uses CSRF POST',async()=>{
    await page.evaluate(()=>jQuery('#logoutButton').trigger('click'));await page.waitForTimeout(50);
    if(!requests.some(r=>r.url==='/logout'&&r.method==='POST'&&r.csrf))throw new Error('missing logout POST/token');
   });
   if(width===390&&await page.locator('[data-toggle="push-menu"]').count())await check('mobile sidebar',async()=>{
    await page.locator('[data-toggle="push-menu"]').click();
    if(!await page.locator('body').evaluate(el=>el.classList.contains('sidebar-open')))throw new Error('sidebar failed to open');
    await page.locator('[data-toggle="push-menu"]').click();
   });
   const layout=await page.evaluate(()=>({width:innerWidth,documentWidth:document.documentElement.scrollWidth,bodyWidth:document.body.scrollWidth,visibleText:document.body.innerText.length,xss:window.__xss||0}));
   const viewportOverflow=layout.documentWidth>width+1;
   await page.evaluate(()=>document.querySelectorAll('.table-responsive,.fixed-table-body').forEach(el=>el.scrollLeft=0));
   const screenshot=entry.name.replaceAll('/','__')+'-'+width+'.png';
   await page.screenshot({path:path.join(reportDir,screenshot),fullPage:true});
   const doubleEscaped=await page.evaluate(()=>Array.from(document.querySelectorAll('.bootstrap-table tbody td')).some(td=>td.textContent.includes('fixture &amp; &lt;text&gt;')));
   const unescaped=await page.locator('img[onerror], svg[onload]').count();
   results.push({page:entry.name,width,parseErrors,errors,consoleErrors,missingAssets:requests.filter(r=>r.missingAsset),viewportOverflow,layout,unescaped,doubleEscaped,interactions,interactionErrors,screenshot});
   await page.close();
  }
 }
} finally {await browser.close();}
const failures=results.filter(r=>r.renderError||r.parseErrors?.length||r.errors?.length||r.consoleErrors?.length||r.missingAssets?.length||r.viewportOverflow||r.unescaped||r.doubleEscaped||r.interactionErrors?.length);
fs.writeFileSync(path.join(reportDir,'report.json'),JSON.stringify({scope:'real ThinkPHP rendered templates, real native assets, simulated AJAX; no live DB/cloud',results,failures},null,2));
console.log(JSON.stringify({pages:manifest.length,browserRuns:results.filter(r=>r.width).length,failures:failures.map(r=>({page:r.page,width:r.width,renderError:r.renderError,parseErrors:r.parseErrors,errors:r.errors,consoleErrors:r.consoleErrors,missingAssets:r.missingAssets,viewportOverflow:r.viewportOverflow,interactionErrors:r.interactionErrors})),report:path.join(reportDir,'report.json')},null,2));
process.exitCode=failures.length?1:0;
