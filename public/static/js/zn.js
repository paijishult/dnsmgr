// Encode at the HTML output boundary; never treat provider or API text as markup.
window.htmlEscape = window.htmlEscape || function (value) {
	return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
	});
};
window.jsEscape = function (value) {
	return htmlEscape(String(value == null ? '' : value).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\r/g, '\\r').replace(/\n/g, '\\n').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029'));
};

// All existing native AJAX forms share the same session-bound CSRF token.
if (window.jQuery) {
	jQuery.ajaxPrefilter(function (options, originalOptions, xhr) {
		var target;
		try { target = new URL(options.url || window.location.href, window.location.href); } catch (e) { return; }
		if (target.origin !== window.location.origin || /^(GET|HEAD|OPTIONS)$/i.test(options.type || 'GET')) return;
		var token = document.querySelector('meta[name="csrf-token"]');
		if (token && token.content) xhr.setRequestHeader('X-CSRF-Token', token.content);
	});
}

/*
 * 界面增强：页面标题、快速搜索（⌘K / Ctrl+K）
 * 只做增强，不改变原有页面功能；任何异常都不会影响页面其他脚本。
 */
(function ($) {
	'use strict';
	if (!$) return;

	// Bootstrap 3 menus inside scrollable tables/cards are clipped by overflow.
	// Move only those open menus to body and restore them before Bootstrap hides.
	// Keeping the original dropdown owner preserves Bootstrap's click lifecycle.
	var floatingDropdown = null;
	function restoreDropdown() {
		if (!floatingDropdown) return;
		var state = floatingDropdown;
		floatingDropdown = null;
		state.menu.removeClass('zn-floating-dropdown').attr('style', state.style || '');
		if (state.placeholder.parentNode) {
			state.placeholder.parentNode.insertBefore(state.menu[0], state.placeholder);
			state.placeholder.parentNode.removeChild(state.placeholder);
		} else state.menu.remove();
	}
	function closeDropdown() {
		if (!floatingDropdown) return;
		var toggle = floatingDropdown.toggle;
		if ($.contains(document, toggle[0])) toggle.dropdown('toggle');
		else restoreDropdown();
	}
	$(document).on('shown.bs.dropdown', '.btn-group, .dropdown', function (event) {
		if (event.target !== this || !$(this).closest('.table-responsive, .fixed-table-container, .panel, .box').length) return;
		var owner = $(this), menu = owner.children('.dropdown-menu').first(), toggle = owner.children('[data-toggle="dropdown"]').first();
		if (!menu.length || !toggle.length) return;
		restoreDropdown();
		var placeholder = document.createComment('dropdown-menu');
		menu[0].parentNode.insertBefore(placeholder, menu[0]);
		floatingDropdown = { menu: menu, toggle: toggle, placeholder: placeholder, style: menu.attr('style') };
		menu.appendTo(document.body).addClass('zn-floating-dropdown').css({ position: 'fixed', display: 'block', visibility: 'hidden', right: 'auto', bottom: 'auto', margin: 0, maxHeight: Math.max(40, window.innerHeight - 16), overflowY: 'auto', zIndex: 1060 });
		var rect = toggle[0].getBoundingClientRect(), width = menu.outerWidth(), height = menu.outerHeight();
		floatingDropdown.anchor = {left: rect.left, top: rect.top};
		var below = window.innerHeight - rect.bottom - 8, above = rect.top - 8;
		var openAbove = height > below && above > below;
		menu.css('max-height', Math.max(40, openAbove ? above : below));
		height = menu.outerHeight();
		var left = menu.hasClass('dropdown-menu-right') ? rect.right - width : rect.left;
		menu.css({ left: Math.max(8, Math.min(left, window.innerWidth - width - 8)), top: Math.max(8, openAbove ? rect.top - height - 2 : rect.bottom + 2), visibility: 'visible' });
	});
	$(document).on('hide.bs.dropdown', function () { restoreDropdown(); });
	if (window.MutationObserver) new MutationObserver(function () {
		if (floatingDropdown && !document.documentElement.contains(floatingDropdown.toggle[0])) restoreDropdown();
	}).observe(document.body, {childList: true, subtree: true});
	$(document).on('pre-body.bs.table', closeDropdown);
	$(window).on('resize.znDropdown', closeDropdown);
	document.addEventListener('scroll', function (event) {
		if (!floatingDropdown || floatingDropdown.menu[0].contains(event.target)) return;
		// A queued scroll event can arrive after the button was scrolled into
		// view and clicked. Close only if its anchor actually moved since opening.
		var rect = floatingDropdown.toggle[0].getBoundingClientRect(), anchor = floatingDropdown.anchor;
		if (!anchor || Math.abs(rect.left - anchor.left) > 1 || Math.abs(rect.top - anchor.top) > 1) closeDropdown();
	}, true);
	$(document).on('keydown', '.zn-floating-dropdown', function (event) {
		if (!floatingDropdown || [27, 35, 36, 38, 40].indexOf(event.which) === -1 || /input|textarea/i.test(event.target.tagName)) return;
		event.preventDefault(); event.stopPropagation();
		var toggle = floatingDropdown.toggle;
		if (event.which === 27) { closeDropdown(); toggle.trigger('focus'); return; }
		var links = floatingDropdown.menu.find('li:not(.disabled) > a').filter(':visible'), index = links.index(event.target);
		if (event.which === 36) index = 0;
		else if (event.which === 35) index = links.length - 1;
		else index = Math.max(0, Math.min(links.length - 1, index + (event.which === 38 ? -1 : 1)));
		links.eq(index).trigger('focus');
	});
	$(document).on('keydown', '[data-toggle="dropdown"]', function (event) {
		if (floatingDropdown && floatingDropdown.toggle[0] === this && (event.which === 38 || event.which === 40)) {
			event.preventDefault(); event.stopPropagation();
			floatingDropdown.menu.find('li:not(.disabled) > a').filter(':visible').first().trigger('focus');
		}
	});
	$(function () {
		$('.panel-body > table.table, .box-body > table.table, .modal-body > table.table').each(function () {
			if (!$(this).closest('.table-responsive, .bootstrap-table').length) $(this).wrap('<div class="table-responsive"></div>');
		});
	});

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// ---------- 顶栏页面标题 ----------
	try {
		var title = (document.title || '').split(' - ')[0];
		if (title && title !== '聚合DNS管理系统') $('#znPageTitle').text(title);
	} catch (e) {}

	// ---------- 快速搜索 ----------
	var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
	$('#znSearchKbd').text(isMac ? '⌘K' : 'Ctrl K');

	var spot = null, input, list, items = [], active = 0, menus = null, timer = null, xhr = null, lastQuery = '';

	function collectMenus() {
		if (menus) return menus;
		menus = [];
		$('.sidebar-menu a[href]').each(function () {
			var href = $(this).attr('href');
			if (!href || href.charAt(0) !== '/') return;
			var text = $.trim($(this).text());
			var parent = $(this).closest('.treeview').children('a').find('span').first().text();
			var icon = ($(this).find('.fa').attr('class') || '').match(/fa-[a-z0-9-]+/g);
			icon = icon ? icon.filter(function (c) { return c !== 'fa-fw' && c !== 'fa-circle-o'; })[0] : null;
			if (!icon) icon = ($(this).closest('.treeview').children('a').find('.fa').first().attr('class') || '').match(/fa-[a-z0-9-]+/g);
			if ($.isArray(icon)) icon = icon.filter(function (c) { return c !== 'fa-fw'; })[0];
			menus.push({ text: text, sub: parent ? $.trim(parent) : '', href: href, icon: icon || 'fa-angle-right' });
		});
		return menus;
	}

	function build() {
		spot = $('<div class="zn-spot" role="dialog" aria-modal="true" aria-label="快速搜索">' +
			'<div class="zn-spot-mask"></div>' +
			'<div class="zn-spot-panel">' +
			'<div class="zn-spot-input"><i class="fa fa-search"></i><input type="text" placeholder="搜索域名、功能页面…" autocomplete="off" spellcheck="false"><kbd>Esc</kbd></div>' +
			'<div class="zn-spot-list" role="listbox"></div>' +
			'<div class="zn-spot-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> 选择</span><span><kbd>Enter</kbd> 打开</span><span><kbd>Esc</kbd> 关闭</span></div>' +
			'</div></div>').appendTo('body');
		input = spot.find('input');
		list = spot.find('.zn-spot-list');
		spot.find('.zn-spot-mask').on('click', close);
		input.on('input', function () { render($.trim(input.val())); });
		input.on('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
			else if (e.key === 'Enter') { e.preventDefault(); go(items[active]); }
			else if (e.key === 'Escape') { e.preventDefault(); close(); }
		});
		list.on('mousemove', '.zn-spot-item', function () {
			var i = $(this).data('i');
			if (i !== active) { active = i; paint(); }
		});
		list.on('click', '.zn-spot-item', function () { go(items[$(this).data('i')]); });
	}

	function open() {
		if (!spot) build();
		spot.addClass('show');
		$('body').css('overflow', 'hidden');
		input.val('');
		render('');
		setTimeout(function () { input.trigger('focus'); }, 10);
	}

	function close() {
		if (!spot) return;
		spot.removeClass('show');
		$('body').css('overflow', '');
		if (xhr) { xhr.abort(); xhr = null; }
	}

	function match(text, q) {
		return text.toLowerCase().indexOf(q.toLowerCase()) > -1;
	}

	function render(q, domains) {
		lastQuery = q;
		items = [];
		var html = '';
		var ms = collectMenus().filter(function (m) { return !q || match(m.text + ' ' + m.sub, q); });
		if (q) {
			if (domains && domains.length) {
				html += '<div class="zn-spot-group">域名</div>';
				domains.forEach(function (d) {
					items.push({ href: '/record/' + d.id });
					html += item(items.length - 1, 'fa-globe', esc(d.name), esc(d.typename || ''));
				});
			}
			if ($('.sidebar-menu a[href="/domain"]').length) {
				items.push({ href: '/domain?kw=' + encodeURIComponent(q) });
				html += '<div class="zn-spot-group">搜索</div>' + item(items.length - 1, 'fa-search', '在域名列表中搜索“' + esc(q) + '”', '');
			}
			if ($('.sidebar-menu a[href="/log"]').length) {
				items.push({ href: '/log?kw=' + encodeURIComponent(q) });
				html += item(items.length - 1, 'fa-list', '在操作日志中搜索“' + esc(q) + '”', '');
			}
		}
		if (ms.length) {
			html += '<div class="zn-spot-group">功能</div>';
			ms.slice(0, q ? 8 : 20).forEach(function (m) {
				items.push({ href: m.href });
				html += item(items.length - 1, m.icon, esc(m.text), esc(m.sub));
			});
		}
		if (!items.length) html = '<div class="zn-spot-empty">没有找到相关内容</div>';
		list.html(html);
		active = 0;
		paint();
		if (q && q.length >= 2 && !domains) searchDomains(q);
	}

	function item(i, icon, text, sub) {
		return '<div class="zn-spot-item" role="option" data-i="' + i + '"><i class="fa ' + esc(icon) + '"></i><span>' + text + '</span>' + (sub ? '<span class="sub">' + sub + '</span>' : '') + '</div>';
	}

	function searchDomains(q) {
		clearTimeout(timer);
		if (!$('.sidebar-menu a[href="/domain"]').length) return;
		timer = setTimeout(function () {
			if (xhr) xhr.abort();
			xhr = $.ajax({ type: 'POST', url: '/domain/data', data: { kw: q, offset: 0, limit: 6 }, dataType: 'json' })
				.done(function (res) {
					if (q !== lastQuery || !res || !res.rows) return;
					if (res.rows.length) render(q, res.rows);
				});
		}, 180);
	}

	function move(d) {
		if (!items.length) return;
		active = (active + d + items.length) % items.length;
		paint();
	}

	function paint() {
		var el = list.find('.zn-spot-item').removeClass('active').filter('[data-i="' + active + '"]').addClass('active');
		if (el.length) {
			var top = el[0].offsetTop, bottom = top + el[0].offsetHeight, view = list[0];
			if (top < view.scrollTop) view.scrollTop = top - 6;
			else if (bottom > view.scrollTop + view.clientHeight) view.scrollTop = bottom - view.clientHeight + 6;
		}
	}

	function go(it) {
		if (!it) return;
		close();
		window.location.href = it.href;
	}

	$(document).on('click', '#znSearchBtn', function (e) { e.preventDefault(); open(); });
	$(document).on('keydown', function (e) {
		if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
			if (!$('#znSearchBtn').length) return;
			if (!(spot && spot.hasClass('show')) && ($('.modal.in').length || $('.layui-layer-shade').length)) return;
			e.preventDefault();
			if (spot && spot.hasClass('show')) close(); else open();
		}
	});

	// ---------- 复制按钮反馈 ----------
	$(document).on('click', '.btn-copy, [data-copy]', function () {
		var b = $(this);
		if (!b.is('button')) return;
		b.addClass('copied');
		setTimeout(function () { b.removeClass('copied'); }, 1200);
	});
})(window.jQuery);
