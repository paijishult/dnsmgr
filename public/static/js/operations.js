$(function () {
  'use strict';
  var panel = 'tasks', rows = [], credential = null, generation = 0, busy = false;
  var labels = {healthy:'正常',manual:'需人工核对',unknown:'未检查',warning:'即将到期或需轮换',failed:'失败',success:'成功',passed:'通过',pending:'待验收'};
  var help = {
    tasks:'每种类型显示最近100个任务。可重试失败任务、暂停或恢复自动任务；进入原任务页面查看详细配置与日志。',
    conflicts:'列出同一记录的共同写入者，包括优选IP对整个主机记录集的修改。配对的定时策略可能是有意配置，请核对执行时间与主备值。',
    matrix:'对照当前证书与最近部署指纹，并查看每个部署目标、验收IP的结果。多个目标必须全部通过验收。显示最近100个部署任务。',
    drift:'检测本系统之外的解析变化。系统内的成功变更会同步无漂移的基线；已有外部差异会保留。差异明细最多显示新增、移除各100项。特殊路由元数据受平台适配器能力限制。',
    credentials:'检查DNS查询权限、受支持面板的访问权限及凭据到期、轮换时间。只读探测不代表拥有所有写入权限。检查默认关闭，保存配置后可启用。',
    notifications:'按渠道展示最近200条通知。发送失败自动退避重试，8次失败后保留记录，可手动重试。成功记录保留30天。'
  };
  function message(text, error) { $('#opsMessage').empty().append($('<div>').addClass('alert '+(error?'alert-danger':'alert-success')).text(text)); }
  function date(value) { return !value ? '—' : typeof value === 'number' || /^\d+$/.test(value) ? new Date(Number(value)*1000).toLocaleString() : value; }
  function cell(tr, value) { return $('<td>').css({'max-width':'340px','white-space':'normal','overflow-wrap':'anywhere'}).text(value == null || value === '' ? '—' : value).appendTo(tr); }
  function button(td, title, callback, style) { $('<button type="button">').addClass('btn btn-xs '+(style||'btn-default')).css('margin','2px').text(title).on('click',callback).appendTo(td); }
  function request(url, data) {
    if (busy) return $.Deferred().reject().promise(); busy=true;
    return $.ajax({url:url,type:'POST',data:data,dataType:'json'}).done(function(r){if(r.code!==0)message(r.msg||'操作失败',true);else {message(r.msg||'操作成功');load();}}).fail(function(){message('请求失败，请核对执行状态后重试',true);}).always(function(){busy=false;});
  }
  function operation(action, id, data) { return request('/operations/op',Object.assign({action:action,id:id},data||{})); }
  function details(title, headings, entries) {
    $('#opsDetailsTitle').text(title); var body=$('#opsDetailsBody').empty(), table=$('<table>').addClass('table table-bordered').appendTo($('<div>').addClass('table-responsive').appendTo(body)), head=$('<tr>').appendTo($('<thead>').appendTo(table));
    headings.forEach(function(h){$('<th>').text(h).appendTo(head);});var tbody=$('<tbody>').appendTo(table);
    entries.forEach(function(entry){var tr=$('<tr>').appendTo(tbody);entry.forEach(function(v){cell(tr,v);});});
    if(!entries.length)$('<p>').text('暂无结果').appendTo(body);$('#opsDetails').modal('show');
  }
  function taskActions(td,r) {
    $('<a>').addClass('btn btn-xs btn-default').attr('href',r.url).text('管理与日志').appendTo(td);
    if (r.kind==='monitor'||r.kind==='schedule') button(td,r.status==='停用'?'启用':'停用',function(){request(r.kind==='monitor'?'/dmonitor/task/setactive':'/schedule/stask/setactive',{id:r.id,active:r.status==='停用'?1:0});});
    if(r.kind==='batch') {
      if(r.status==='失败')button(td,'重试',function(){request('/batch/task/retry',{id:r.id});},'btn-warning');
      if(['等待','执行中','失败'].indexOf(r.status)!==-1)button(td,'取消',function(){request('/batch/task/cancel',{id:r.id});},'btn-danger');
    }
    if(['order','deploy'].indexOf(r.kind)!==-1 && ['失败','失败或部分成功','待验收'].indexOf(r.status)!==-1) button(td,'重试',function(){request('/cert/'+(r.kind==='order'?'order':'deploy')+'/process',{id:r.id,reset:0});},'btn-warning');
  }
  function render() {
    var search=$('#opsSearch').val().toLowerCase(), data=rows.filter(function(r){return JSON.stringify(r).toLowerCase().indexOf(search)!==-1;});
    var headings={tasks:['类型 / ID','名称','状态','进度','更新 / 下次运行','错误','操作'],conflicts:['域名','主机记录','共同写入任务','提示'],matrix:['任务','当前指纹 / 到期','最近部署指纹','目标结果','操作'],drift:['域名','状态','上次检查','差异 / 错误','操作'],credentials:['账户','类型','状态','上次检查','到期 / 轮换','说明','操作'],notifications:['ID / 渠道','状态','次数','下次尝试','错误','操作']}[panel];
    var head=$('#opsTable thead').empty(), tr=$('<tr>').appendTo(head);headings.forEach(function(h){$('<th>').text(h).appendTo(tr);});var tbody=$('#opsTable tbody').empty();
    data.forEach(function(r){
      var tr=$('<tr>').appendTo(tbody),td;
      if(panel==='tasks') {cell(tr,r.kind_name+' #'+r.id);cell(tr,r.name);cell(tr,r.status);cell(tr,r.progress);cell(tr,r.updated_at+' / '+r.next_at);cell(tr,r.error);taskActions(cell(tr,'' ).empty(),r);}
      if(panel==='conflicts') {cell(tr,r.domain);cell(tr,r.rr);cell(tr,r.tasks.map(function(t){return t.kind+' #'+t.id;}).join('、'));cell(tr,r.message);}
      if(panel==='matrix') {cell(tr,r.name);cell(tr,(r.expected_sha256||'未知')+' / '+(r.expires_at||'—'));cell(tr,r.deployed_sha256);cell(tr,r.targets.length+' 个部署回执 / '+r.verification.length+' 个验收结果');td=cell(tr,'').empty();button(td,'部署目标',function(){details(r.name,['目标','结果','错误'],r.targets.map(function(t){return[t.target,labels[t.status]||t.status,t.error];}));});button(td,'验收明细',function(){details(r.name,['目标','IP','结果','实际指纹','到期','错误'],r.verification.map(function(t){return[t.host+':'+t.port,t.address,labels[t.status]||t.status,t.observed_sha256,t.expires_at,t.error];}));});}
      if(panel==='drift') {cell(tr,r.name||'#'+r.did);cell(tr,!r.enabled?'停用':r.error?'检查失败':r.drifted?'发现变化':'一致');cell(tr,date(r.checked_at));cell(tr,r.error||('新增 '+(r.diff.added_count||0)+' / 移除 '+(r.diff.removed_count||0)));td=cell(tr,'').empty();button(td,'差异',function(){var entries=[];['removed','added'].forEach(function(k){(r.diff[k]||[]).forEach(function(t){entries.push([k==='added'?'新增':'移除',t.Name,t.Type,Array.isArray(t.Value)?t.Value.join('\n'):t.Value,t.TTL]);});});details(r.name,['变更','名称','类型','值','TTL'],entries);});if(r.enabled)button(td,'检查',function(){operation('check_drift',r.did);});button(td,'接受当前为基线',function(){if(window.confirm('将当前解析设为新基线，并清除已记录差异？'))operation('baseline',r.did,{interval:r.interval_seconds});});if(r.enabled)button(td,'停用',function(){operation('disable_watch',r.did);});}
      if(panel==='credentials') {cell(tr,r.name||'#'+r.aid);cell(tr,r.kind+' / '+r.provider);cell(tr,(r.enabled?'':'未启用 · ')+(labels[r.status]||r.status));cell(tr,date(r.checked_at));cell(tr,(r.api_expires_at||r.expires_at||'未设置到期')+' / '+r.rotation_days+'天');cell(tr,r.error);td=cell(tr,'').empty();button(td,'设置',function(){credential=r;$('#credentialTitle').text('凭据设置 · '+r.kind+' #'+r.aid);$('#credentialEnabled').prop('checked',!!Number(r.enabled));$('#credentialExpires').val((r.expires_at||'').slice(0,10));$('#credentialRotated').val((r.rotated_at||'').slice(0,10));$('#credentialDays').val(r.rotation_days);$('#credentialModal').modal('show');});button(td,'检查',function(){operation('credential_check',r.aid,{kind:r.kind});});}
      if(panel==='notifications') {cell(tr,r.id+' / '+r.channel);cell(tr,Number(r.status)===1?'已送达':Number(r.status)===-1?'重试耗尽':'待发送');cell(tr,r.attempts);cell(tr,Number(r.status)===0?date(r.nexttime):'—');cell(tr,r.error);td=cell(tr,'').empty();if(Number(r.status)===-1)button(td,'重试',function(){operation('notification_retry',r.id);});}
    });
    if(!data.length)$('<tr>').append($('<td>').attr('colspan',headings.length).text('暂无记录')).appendTo(tbody);
  }
  function load() {
    var token=++generation;$('#opsKind').toggle(panel==='tasks');$('#watchForm').toggle(panel==='drift');$('#opsHelp').text(help[panel]);
    $.post('/operations/data',{panel:panel,kind:$('#opsKind').val()},null,'json').done(function(r){if(token!==generation)return;if(r.code!==0){message(r.msg||'加载失败',true);return;}rows=r.rows||[];$('#opsHeartbeat').text('调度心跳：'+r.heartbeat);render();}).fail(function(){if(token===generation)message('加载失败，请重试',true);});
  }
  $('#opsTabs').on('click','[data-panel]',function(e){e.preventDefault();panel=$(this).data('panel');$(this).parent().addClass('active').siblings().removeClass('active');$('#opsSearch').val('');$('#opsMessage').empty();load();});
  $('#opsKind,#opsRefresh').on('change click',load);$('#opsSearch').on('input',render);
  $('#watchForm').on('submit',function(e){e.preventDefault();operation('watch',Number($('#watchDomain').val()),{interval:$('#watchInterval').val()});});
  $('#credentialForm').on('submit',function(e){e.preventDefault();if(!credential)return;operation('credential_save',credential.aid,{kind:credential.kind,enabled:$('#credentialEnabled').prop('checked')?1:0,expires_at:$('#credentialExpires').val(),rotated_at:$('#credentialRotated').val(),rotation_days:$('#credentialDays').val()}).done(function(r){if(r.code===0)$('#credentialModal').modal('hide');});});
  setInterval(function(){if(!document.hidden&&!busy&&!$('.modal.in').length)load();},30000);load();
});
