if (typeof window.htmlEscape !== 'function') {
	window.htmlEscape = function(str) {
		if (str === null || typeof str === 'undefined') return '';
		return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	};
}
// URLSearchParams handles encoded separators, '+' and malformed percent escapes.
window.$_GET = Object.create(null);
new URLSearchParams(window.location.search).forEach(function (value, key) {
    window.$_GET[key] = value;
});

function searchRefresh(){
	$('#listTable').bootstrapTable('refresh');
	return false;
}
function searchSubmit(){
	if(typeof sidePagination != 'undefined' && sidePagination == 'client'){
		$('#listTable').bootstrapTable('refresh');
	}else{
		$('#listTable').bootstrapTable('selectPage', 1);
	}
	return false;
}
function searchClear(){
	$('#searchToolbar').find('input[name]').each(function() {
		$(this).val('');
	});
	$('#searchToolbar').find('select[name]').each(function() {
		$(this).find('option:first').prop("selected", 'selected');
	});
	var $table = $('#listTable');
	$table.data('_sortClicks', null);
	delete window.$_GET.sortName;
	delete window.$_GET.sortOrder;
	$table.bootstrapTable('refreshOptions', {
		sortName: '',
		sortOrder: 'asc',
		pageNumber: 1
	});
}
function updateToolbar(){
    $('#searchToolbar').find(':input[name]').each(function() {
		var name = $(this).attr('name');
		if(typeof window.$_GET[name] != 'undefined')
			$(this).val(window.$_GET[name]);
	})
}
function updateQueryStr(obj){
	var arr = [];
    for (var p in obj){
		if (obj.hasOwnProperty(p) && typeof obj[p] != 'undefined' && obj[p] != null && obj[p] != '') {
			arr.push(p + "=" + encodeURIComponent(obj[p]));
		}
	}
	history.replaceState({}, null, '?'+arr.join("&"));
}

var VALID_PAGE_SIZES = [10, 15, 20, 30, 50, 100, 200, 300, 500];
var DEFAULT_PAGE_SIZE = 15;

if (typeof $.fn.bootstrapTable !== "undefined") {
    $.fn.bootstrapTable.custom = {
        method: 'post',
        contentType: "application/x-www-form-urlencoded",
        escape: true,
        sortable: true,
        pagination: true,
        sidePagination: 'server',
        pageNumber: 1,
        pageSize: 15,
        pageList: [10, 15, 20, 30, 50, 100, 200, 300, 500],
		loadingFontSize: '18px',
		toolbar: '#searchToolbar',
		showColumns: true,
		minimumCountColumns: 2,
		showToggle: true,
		showFullscreen: true,
		paginationPreText: '前页',
		paginationNextText: '后页',
		showJumpTo: true,
		paginationLoop: false,
		queryParamsType: '',
		queryParams: function(params) {
			$('#searchToolbar').find(':input[name]').each(function() {
				//if(!$(this).is(":visible")) return;
				params[$(this).attr('name')] = $(this).val()
			})
			if (!params.sortName) {
				delete params.sortName;
				delete params.sortOrder;
			}
			updateQueryStr(params);
			params.offset = params.pageSize * (params.pageNumber-1);
			params.limit = params.pageSize;
			return params;
		},
        formatLoadingMessage: function(){
			return '';
		},
		formatShowingRows: function(t,n,r,e){
			return '显示第 '+t+' 到第 '+n+' 条, 总共 <b>'+r+'</b> 条';
		},
		formatRecordsPerPage: function(t){
			return '每页显示 '+t+' 条';
		},
		formatNoMatches: function(){
			return '没有找到匹配的记录';
		},
		onSort: function(name, order) {
			var $table = $('#listTable');
			if (!name) {
				$table.data('_sortClicks', null);
				return;
			}
			var clicks = $table.data('_sortClicks') || {};
			if (clicks._last !== name) {
				clicks = {_last: name};
				clicks[name] = 1;
				this.sortOrder = 'desc';
			} else {
				clicks[name] = (clicks[name] || 0) + 1;
				if (clicks[name] === 1) {
					this.sortOrder = 'desc';
				} else if (clicks[name] === 2) {
					this.sortOrder = 'asc';
				} else {
					this.sortName = undefined;
					this.sortOrder = undefined;
					clicks = {};
				}
			}
			$table.data('_sortClicks', clicks);
		},
    };
    $.extend($.fn.bootstrapTable.defaults, $.fn.bootstrapTable.custom);
}

function httpGet(url, callback){
	$.ajax({
		url: url,
		type: 'get',
		dataType: 'json',
		success: function (res) {
			callback(res)
		},
		error: function () {
			if (typeof layer !== "undefined") {
				layer.closeAll();
				layer.msg('服务器错误');
			}
		}
	});
}

function httpPost(url, data, callback){
	$.ajax({
		url: url,
		type: 'post',
		data: data,
		dataType: 'json',
		success: function (res) {
			callback(res)
		},
		error: function () {
			if (typeof layer !== "undefined") {
				layer.closeAll();
				layer.msg('服务器错误');
			}
		}
	});
}

var isMobile = function(){
	if( /Android|SymbianOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Windows Phone|Midp/i.test(navigator.userAgent)) {
		return true;
	}
	return false;
}

function setCookie(name,value,expire = null)
{
	var cookie = name + "=" + escape(value);
	if(expire){
		var exp = new Date();
		exp.setTime(exp.getTime() + expire*1000);
		cookie += ";expires=" + exp.toGMTString();
	}
	document.cookie = cookie;
}
function getCookie(name)
{
	var arr,reg=new RegExp("(^| )"+name+"=([^;]*)(;|$)");
	if(arr=document.cookie.match(reg))
		return unescape(arr[2]);
	else
		return null;
}
function delCookie(name)
{
    var exp = new Date();
    exp.setTime(exp.getTime() - 1);
    var cval=getCookie(name);
    if(cval!=null){
      document.cookie= name + "="+cval+";expires="+exp.toGMTString();
    }
}

function getStoredPageSize(key, defaultSize) {
	defaultSize = defaultSize || DEFAULT_PAGE_SIZE;
    var urlSize = typeof window.$_GET['pageSize'] != 'undefined' ? parseInt(window.$_GET['pageSize']) : null;
    if (urlSize && VALID_PAGE_SIZES.indexOf(urlSize) !== -1) {
        return urlSize;
    }
    var stored = null;
    try { stored = localStorage.getItem(key); } catch (e) {}
    if (stored) {
        var storedSize = parseInt(stored);
        if (VALID_PAGE_SIZES.indexOf(storedSize) !== -1) {
            return storedSize;
        }
    }
    return defaultSize;
}

function setStoredPageSize(key, size) {
    var intSize = parseInt(size);
    if (VALID_PAGE_SIZES.indexOf(intSize) !== -1) {
        try { localStorage.setItem(key, intSize); } catch (e) {}
    }
}

/**
 * 可用性测试（DNS账户 / 域名 / SSL证书账户 / 自动部署账户 通用）
 * 结果只保存在当前浏览器（localStorage），不写数据库
 * 接口返回：{code:0|-1, msg, time(ms), level:'ok'|'warn', label, details:[{level:'ok'|'warn'|'fail', text}]}
 */
window.znTester = function(opts) {
	var t = { results: {}, running: false };
	var key = 'zn_test_' + opts.key;
	try { t.results = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) { t.results = {}; }
	var save = function() { try { localStorage.setItem(key, JSON.stringify(t.results)); } catch (e) {} };
	var ago = function(ts) {
		var s = Math.max(0, Math.round((Date.now() - ts) / 1000));
		if (s < 60) return '刚刚';
		if (s < 3600) return Math.floor(s / 60) + '分钟前';
		if (s < 86400) return Math.floor(s / 3600) + '小时前';
		return Math.floor(s / 86400) + '天前';
	};
	t.render = function(id) {
		var r = t.results[id];
		if (!r) return '<span class="zn-test idle"><span class="dot"></span>未测试</span>';
		if (r.status == 'run') return '<span class="zn-test run"><span class="dot"></span>测试中…</span>';
		var when = '<span class="when">' + ago(r.at) + '</span>';
		var click = ' onclick="' + opts.name + '.detail(' + id + ')"';
		if (r.status == 'ok') return '<span class="zn-test ok"' + click + ' title="点击查看详情"><span class="dot"></span>' + htmlEscape(r.label || '可用') + (r.time ? ' · ' + r.time + 'ms' : '') + ' ' + when + '</span>';
		if (r.status == 'warn') return '<span class="zn-test warn"' + click + ' title="' + htmlEscape(r.msg) + '"><span class="dot"></span>' + htmlEscape(r.label || '有警告') + ' ' + when + '</span>';
		return '<span class="zn-test fail"' + click + ' title="' + htmlEscape(r.msg) + '"><span class="dot"></span>' + htmlEscape(r.label || '不可用') + ' ' + when + '</span>';
	};
	t.cell = function(id) { return '<span id="zt-' + opts.key + '-' + id + '">' + t.render(id) + '</span>'; };
	t.paint = function(id) { $('#zt-' + opts.key + '-' + id).html(t.render(id)); };
	t.detailHtml = function(r) {
		var html = '<div class="zn-test-detail">';
		if (r.msg && !(r.details && r.details.length)) html += '<p class="zn-test-msg">' + htmlEscape(r.msg) + '</p>';
		if (r.details && r.details.length) {
			html += '<ul>';
			$.each(r.details, function(_, d) {
				var lv = ['ok', 'warn', 'fail'].indexOf(d.level) !== -1 ? d.level : 'ok';
				var icon = lv == 'ok' ? 'fa-check-circle' : (lv == 'warn' ? 'fa-exclamation-circle' : 'fa-times-circle');
				html += '<li class="' + lv + '"><i class="fa ' + icon + '"></i><span>' + htmlEscape(d.text) + '</span></li>';
			});
			html += '</ul>';
		}
		if (r.at) html += '<p class="zn-test-when">测试时间：' + new Date(r.at).toLocaleString() + (r.time ? '，耗时 ' + r.time + 'ms' : '') + '</p>';
		return html + '</div>';
	};
	t.detail = function(id) {
		var r = t.results[id];
		if (!r || r.status == 'run') return;
		var icon = r.status == 'ok' ? 1 : (r.status == 'warn' ? 0 : 2);
		layer.alert(t.detailHtml(r), { icon: icon, title: (opts.title ? opts.title(id) : 'ID ' + id) + ' 测试结果' });
	};
	t.run = function(id) {
		t.results[id] = { status: 'run' };
		t.paint(id);
		return $.ajax({ type: 'POST', url: opts.url, data: opts.data(id), dataType: 'json', timeout: 130000 })
		.then(function(data) {
			var status = data.code == 0 ? (data.level == 'warn' ? 'warn' : 'ok') : 'fail';
			t.results[id] = { status: status, msg: data.msg, label: data.label, details: data.details, time: data.time || 0, at: Date.now() };
		}, function(xhr, status) {
			t.results[id] = { status: 'fail', msg: status == 'timeout' ? '请求超时' : '服务器错误（HTTP ' + xhr.status + '）', time: 0, at: Date.now() };
			return $.Deferred().resolve();
		}).always(function() { save(); t.paint(id); });
	};
	t.one = function(id) {
		t.run(id).then(function() {
			var r = t.results[id];
			if (r.status == 'ok' && !(r.details && r.details.length > 1)) layer.msg((r.label || '可用') + (r.time ? '，耗时 ' + r.time + 'ms' : ''), { icon: 1, time: 1500 });
			else t.detail(id);
		});
	};
	t.all = function(ids) {
		if (t.running) return;
		if (!ids.length) { layer.msg('没有可测试的项目'); return; }
		t.running = true;
		var $btn = $(opts.button).addClass('disabled'), $sum = $(opts.summary);
		var queue = ids.slice(), done = 0, ok = 0, warn = 0, fail = 0;
		var summary = function() {
			$sum.html((t.running ? '正在测试 ' + done + '/' + ids.length + '，' : '共 ' + ids.length + ' 个：') + '<b class="ok">正常 ' + ok + '</b>' + (warn ? '，<b class="warn">警告 ' + warn + '</b>' : '') + '，<b class="fail">失败 ' + fail + '</b>');
		};
		$.each(queue, function(_, id) { t.results[id] = { status: 'run' }; t.paint(id); });
		summary();
		var next = function() {
			if (!queue.length) return $.Deferred().resolve();
			var id = queue.shift();
			return t.run(id).then(function() {
				done++;
				var s = t.results[id].status;
				if (s == 'ok') ok++; else if (s == 'warn') warn++; else fail++;
				summary();
				return next();
			});
		};
		$.when(next(), next(), next()).then(function() {
			t.running = false; $btn.removeClass('disabled'); summary();
			if (fail) layer.msg('测试完成，' + fail + ' 个失败' + (warn ? '、' + warn + ' 个有警告' : ''), { icon: 2, time: 2500 });
			else if (warn) layer.msg('测试完成，' + warn + ' 个有警告', { icon: 0, time: 2500 });
			else layer.msg('测试完成，全部 ' + ok + ' 个正常', { icon: 1, time: 2000 });
		});
	};
	// 取列表接口的全部ID后逐个测试
	t.allFrom = function(listUrl, extra) {
		if (t.running) return;
		var ii = layer.load(2);
		$.ajax({ type: 'POST', url: listUrl, data: $.extend({ offset: 0, limit: 1000 }, extra || {}), dataType: 'json' }).then(function(data) {
			layer.close(ii);
			t.all($.map(data.rows || [], function(r) { return r.id; }));
		}, function() { layer.close(ii); layer.msg('获取列表失败', { icon: 2 }); });
	};
	return t;
};
