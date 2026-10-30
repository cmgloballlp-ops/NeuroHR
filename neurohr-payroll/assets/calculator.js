/* NeuroHR Simple Payroll — front end. No dependencies. */
(function () {
	'use strict';

	var root = document.getElementById('nhp');
	if (!root || !window.NHP) { return; }

	var form = document.getElementById('nhp-form');
	var payslip = document.getElementById('nhp-payslip');
	var bar = document.getElementById('nhp-bar');
	var last = null;
	var timer = null;
	var saveTimer = null;
	var seq = 0;
	var tracked = false;
	var logo = '';

	// The payslip is printed on its own, so it lives directly under <body>.
	document.body.appendChild(payslip);

	var LABELS = {
		earnings: [
			['basic', 'Basic salary'],
			['allowance', 'Allowances & commission'],
			['allowance_tax_only', 'Taxable allowance'],
			['bonus', 'Bonus']
		],
		employee: [
			['epf', 'EPF'],
			['socso', 'SOCSO'],
			['l24', 'LINDUNG 24 Jam'],
			['eis', 'EIS'],
			['pcb', 'PCB (income tax)'],
			['zakat', 'Zakat'],
			['other', 'Other deductions']
		],
		employer: [
			['epf', 'EPF'],
			['socso', 'SOCSO'],
			['eis', 'EIS'],
			['hrd', 'HRD Corp levy']
		]
	};

	function $(sel, ctx) { return (ctx || root).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function isZero(s) { return !s || s === '0.00'; }

	/* ---------- money inputs shown as 1,234.50 ---------- */

	function isMoney(el) {
		return el && el.tagName === 'INPUT' && el.getAttribute('inputmode') === 'decimal' && el.name !== 'units';
	}

	function formatMoney(el) {
		var raw = String(el.value).replace(/[,\sRMrm]/g, '');
		if (raw === '') { return; }
		if (!/^\d*\.?\d*$/.test(raw) || raw === '.') { return; }
		var n = Math.floor(parseFloat(raw) * 100 + 0.5) / 100;
		el.value = n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	form.addEventListener('focusout', function (e) {
		if (isMoney(e.target)) { formatMoney(e.target); remember(); }
	});

	function values() {
		var out = {};
		$$('[name]', form).forEach(function (el) {
			out[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
		});
		out.period = periodValue();
		return out;
	}

	function ytdOn() { return form.elements.ytd.checked; }

	/* ---------- pay month ---------- */

	var monthSel = $('[data-period="month"]', form);
	var yearInput = $('[data-period="year"]', form);

	function periodParts() {
		var y = parseInt(yearInput.value, 10);
		return { month: parseInt(monthSel.value, 10), monthName: monthSel.options[monthSel.selectedIndex].text, year: y };
	}

	function periodValue() {
		var p = periodParts();
		if (!p.year || p.year < 1000 || p.year > 9999) { return ''; }
		return p.year + '-' + (p.month < 10 ? '0' : '') + p.month;
	}

	function periodLabel() {
		var p = periodParts();
		return p.monthName + ' ' + (p.year || '');
	}

	/* ---------- form behaviour ---------- */

	function syncVisibility() {
		var part = form.elements.type.value === 'parttime';
		$$('.nhp-only-monthly').forEach(function (el) { el.hidden = part; });
		$$('.nhp-only-parttime').forEach(function (el) { el.hidden = !part; });

		var single = form.elements.tax_category.value === '1';
		var resident = form.elements.resident.value === '1';
		form.elements.children.disabled = single || !resident;
		form.elements.tax_category.disabled = !resident;
		if (single) { form.elements.children.value = '0'; }

		var ytd = ytdOn();
		$('.nhp-ytd', form).hidden = !ytd;
		$('[data-action="next"]').hidden = !ytd;
		$('[data-tp1-label]', form).textContent = ytd ? 'Other tax reliefs claimed this month (RM)' : 'Other tax reliefs, yearly (RM)';
	}

	function setAutoLabels(settings) {
		$$('select[data-auto]', form).forEach(function (sel) {
			var opt = sel.querySelector('option[value="auto"]');
			if (!opt) { return; }
			var key = sel.getAttribute('data-auto');
			var v = settings[key];
			if (key === 'eis') { v = v ? 'Yes' : 'No'; }
			opt.textContent = 'Auto' + (v ? ' — ' + v : '');
		});
	}

	/* ---------- server calls ---------- */

	function post(url, body) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.NHP.nonce },
			body: JSON.stringify(body)
		});
	}

	function track(event) {
		if (window.NHP.track) { post(window.NHP.track, { event: event }).catch(function () {}); }
	}

	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(calculate, 250);
	}

	function calculate() {
		var mine = ++seq;
		post(window.NHP.endpoint, values()).then(function (res) {
			if (res.status === 401 || res.status === 403) { throw new Error('auth'); }
			if (!res.ok) { throw new Error('http'); }
			return res.json();
		}).then(function (data) {
			if (mine !== seq) { return; }
			last = data;
			var st = $('[data-out="status"]');
			if (st.classList.contains('is-error')) { status(''); }
			render(data);
			if (!tracked) { tracked = true; track('use'); }
		}).catch(function (err) {
			if (mine !== seq) { return; }
			status(err && err.message === 'auth'
				? 'Your login has expired. Please reload the page and log in again.'
				: 'Could not calculate right now. Please try again.', true);
		});
	}

	function status(msg, isError) {
		var el = $('[data-out="status"]');
		el.textContent = msg;
		el.classList.toggle('is-error', !!isError);
		el.hidden = !msg;
	}

	/* ---------- rendering ---------- */

	function items(group, f) {
		return LABELS[group].filter(function (pair) {
			var always = pair[0] === 'basic' || (group !== 'earnings' && ['epf', 'socso', 'eis', 'pcb'].indexOf(pair[0]) > -1);
			return always || !isZero(f[group][pair[0]]);
		}).map(function (pair) {
			var label = pair[1];
			if (group === 'earnings' && pair[0] === 'basic' && form.elements.type.value === 'parttime') {
				label = 'Wages (part-time)';
			}
			var sub = '';
			if (group === 'employee' && pair[0] === 'pcb' && !isZero(f.employee.pcb_bonus)) {
				sub = 'salary ' + f.employee.pcb_normal + ' + bonus ' + f.employee.pcb_bonus;
			}
			return { label: label, sub: sub, value: f[group][pair[0]] };
		});
	}

	function rows(group, f) {
		return items(group, f).map(function (it) {
			return '<tr><th>' + esc(it.label) + (it.sub ? ' <small>' + esc(it.sub) + '</small>' : '') + '</th><td>' + esc(it.value) + '</td></tr>';
		}).join('');
	}

	function render(d) {
		var f = d.formatted;
		$('[data-out="net"]').textContent = 'RM ' + f.net;
		if (bar) { bar.querySelector('strong').textContent = 'RM ' + f.net; }
		$('[data-out="earnings.gross"]').textContent = f.earnings.gross;
		$('[data-out="employee.total"]').textContent = f.employee.total;
		$('[data-out="cost"]').textContent = f.cost;

		['earnings', 'employee', 'employer'].forEach(function (g) {
			$('[data-list="' + g + '"]').innerHTML = rows(g, f);
		});

		var notes = $('[data-list="notes"]');
		notes.innerHTML = d.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('');
		notes.hidden = !d.notes.length;

		var s = d.settings;
		var details = [
			'EPF: employee ' + s.epf_employee_rate + ', employer ' + s.epf_employer_rate + (s.epf_band ? ' (' + s.epf_band + ')' : '') + '. ' + s.epf_auto_label + '.',
			'SOCSO: ' + s.socso_label + (s.socso_band ? ' (' + s.socso_band + ')' : '') + '.',
			'EIS: ' + (s.eis ? 'yes' : 'no') + '.',
			ytdOn()
				? 'PCB: LHDN computerised method using the year-to-date amounts.'
				: 'PCB: LHDN computerised method, assuming the same pay every month of the year.'
		].concat(s.pcb_steps || []);
		$('[data-list="details"]').innerHTML = details.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('');

		setAutoLabels(s);
	}

	/* ---------- next month ---------- */

	function nextMonth() {
		if (!last || !last.carry) { return; }
		var c = last.carry;
		var from = periodLabel();
		yearInput.value = c.period.slice(0, 4);
		monthSel.value = String(parseInt(c.period.slice(5, 7), 10));
		form.elements.ytd_Y.value = c.Y;
		form.elements.ytd_K.value = c.K;
		form.elements.ytd_X.value = c.X;
		form.elements.ytd_Z.value = c.Z;
		form.elements.ytd_LP.value = c.LP;
		form.elements.ytd_socso.value = c.socso;
		form.elements.ytd_l24.value = c.l24;
		// One-off items do not repeat next month.
		form.elements.bonus.value = '';
		form.elements.tp1.value = '';
		var payDate = $('[data-local="paydate"]', form);
		if (payDate) { payDate.value = ''; }

		syncVisibility();
		calculate();
		remember();
		track('next');
		status(from + ' added to the year-to-date totals. Now showing ' + periodLabel() + '.');
		root.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	/* ---------- remember on this browser ---------- */

	var rememberBox = $('[data-remember]', form);

	function storage() {
		try { return window.localStorage; } catch (e) { return null; }
	}

	function snapshot() {
		var data = { fields: values(), local: {}, month: monthSel.value, year: yearInput.value, logo: logo };
		$$('[data-local]', form).forEach(function (el) { data.local[el.getAttribute('data-local')] = el.value; });
		return data;
	}

	function remember() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(function () {
			var st = storage();
			if (!st) { return; }
			try {
				if (rememberBox && rememberBox.checked) {
					st.setItem(window.NHP.store, JSON.stringify(snapshot()));
				} else {
					st.removeItem(window.NHP.store);
				}
			} catch (e) { /* storage full or blocked */ }
		}, 300);
	}

	function restore() {
		var st = storage();
		var raw = null;
		try { raw = st ? st.getItem(window.NHP.store) : null; } catch (e) { raw = null; }
		if (!raw) { return; }
		var data;
		try { data = JSON.parse(raw); } catch (e) { return; }
		Object.keys(data.fields || {}).forEach(function (name) {
			var el = form.elements[name];
			if (!el || name === 'period') { return; }
			if (el.type === 'checkbox') { el.checked = data.fields[name] === '1'; } else { el.value = data.fields[name]; }
		});
		Object.keys(data.local || {}).forEach(function (key) {
			var el = $('[data-local="' + key + '"]', form);
			if (el) { el.value = data.local[key]; }
		});
		if (data.month) { monthSel.value = data.month; }
		if (data.year) { yearInput.value = data.year; }
		if (data.logo && window.NHP.pro) { setLogo(data.logo); }
		$$('input', form).forEach(function (el) { if (isMoney(el)) { formatMoney(el); } });
	}

	/* ---------- logo (Pro) ---------- */

	function setLogo(dataUrl) {
		logo = dataUrl || '';
		var img = $('[data-logo-preview]', form);
		var rm = $('[data-action="logo-remove"]', form);
		if (img) { img.src = logo; img.hidden = !logo; }
		if (rm) { rm.hidden = !logo; }
	}

	var logoInput = $('[data-logo-input]', form);
	if (logoInput) {
		logoInput.addEventListener('change', function () {
			var file = logoInput.files && logoInput.files[0];
			if (!file) { return; }
			if (!/^image\/(png|jpeg|webp)$/.test(file.type)) { status('Please choose a PNG, JPG or WebP image.', true); return; }
			var reader = new FileReader();
			reader.onload = function () {
				var img = new Image();
				img.onload = function () {
					// Shrink to at most 600 × 200 so it prints sharp and stays small.
					var scale = Math.min(1, 600 / img.width, 200 / img.height);
					var canvas = document.createElement('canvas');
					canvas.width = Math.max(1, Math.round(img.width * scale));
					canvas.height = Math.max(1, Math.round(img.height * scale));
					canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
					setLogo(canvas.toDataURL('image/png'));
					remember();
				};
				img.src = reader.result;
			};
			reader.readAsDataURL(file);
			logoInput.value = '';
		});
	}

	/* ---------- payslip ---------- */

	function local(key) {
		var el = $('[data-local="' + key + '"]', form);
		return el ? el.value.trim() : '';
	}

	function formatDate(iso) {
		if (!iso) { return ''; }
		var d = new Date(iso + 'T00:00:00');
		return isNaN(d) ? iso : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
	}

	function buildPayslip() {
		if (!last) { return ''; }
		var f = last.formatted;
		var pro = !!window.NHP.pro;
		var company = local('company') || window.NHP.site || '';
		var address = pro ? local('address') : '';
		var payDate = pro ? formatDate(local('paydate')) : '';
		var emp = local('employee');
		var no = local('staffno');
		var type = form.elements.type.value === 'parttime' ? 'Part-time' : 'Monthly';

		function block(title, group, total, totalLabel) {
			return '<div class="nhp-ps-card">' +
				'<div class="nhp-ps-card-head"><span>' + esc(title) + '</span><span>RM</span></div>' +
				items(group, f).map(function (it) {
					return '<div class="nhp-ps-line"><span>' + esc(it.label) +
						(it.sub ? '<small>' + esc(it.sub) + '</small>' : '') +
						'</span><span class="nhp-ps-amt">' + esc(it.value) + '</span></div>';
				}).join('') +
				'<div class="nhp-ps-line nhp-ps-total"><span>' + esc(totalLabel) + '</span><span class="nhp-ps-amt">' + esc(total) + '</span></div>' +
			'</div>';
		}

		function info(label, value) {
			return value ? '<div class="nhp-ps-info-item"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong></div>' : '';
		}

		var employer = items('employer', f).map(function (it) {
			return '<div class="nhp-ps-tile"><span>' + esc(it.label) + '</span><strong>' + esc(it.value) + '</strong></div>';
		}).join('') + '<div class="nhp-ps-tile nhp-ps-tile-total"><span>Total</span><strong>' + esc(f.employer.total) + '</strong></div>';

		var today = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
		var logoHtml = pro && logo ? '<img class="nhp-ps-logo" src="' + esc(logo) + '" alt="">' : '';

		return '' +
			'<div class="nhp-ps">' +
				'<div class="nhp-ps-top">' +
					'<div class="nhp-ps-brand">' + logoHtml +
						'<div><div class="nhp-ps-company">' + esc(company) + '</div>' +
						(address ? '<div class="nhp-ps-address">' + esc(address).replace(/\n/g, '<br>') + '</div>' : '') +
						'<div class="nhp-ps-kicker">Payslip</div></div>' +
					'</div>' +
					'<div class="nhp-ps-period"><span>Pay period</span><strong>' + esc(periodLabel()) + '</strong>' +
						(payDate ? '<span class="nhp-ps-paydate">Paid on ' + esc(payDate) + '</span>' : '') +
					'</div>' +
				'</div>' +
				'<div class="nhp-ps-info">' +
					info('Employee', emp) + info('Employee no. / IC', no) + info('Employment', type) +
				'</div>' +
				'<div class="nhp-ps-cols">' +
					block('Earnings', 'earnings', f.earnings.gross, 'Gross pay') +
					block('Deductions', 'employee', f.employee.total, 'Total deductions') +
				'</div>' +
				'<div class="nhp-ps-net"><span>Net pay</span><strong>RM ' + esc(f.net) + '</strong></div>' +
				'<div class="nhp-ps-employer"><div class="nhp-ps-card-head"><span>Employer contributions</span><span>RM</span></div>' +
					'<div class="nhp-ps-tiles">' + employer + '</div></div>' +
				'<div class="nhp-ps-foot"><span>' + esc(window.NHP.note || '') + '</span><span>Generated ' + esc(today) + '</span></div>' +
			'</div>';
	}

	function printPayslip() {
		if (!last) { return; }
		payslip.innerHTML = buildPayslip();
		document.documentElement.classList.add('nhp-printing');
		track('print');
		var img = payslip.querySelector('img');
		if (img && !img.complete) {
			img.onload = img.onerror = function () { window.print(); };
		} else {
			window.print();
		}
	}

	window.addEventListener('afterprint', function () {
		document.documentElement.classList.remove('nhp-printing');
	});

	/* ---------- events ---------- */

	function onEdit(e) {
		if (e.target.hasAttribute('data-logo-input')) { return; }
		remember();
		if (e.target.hasAttribute('data-local') || e.target.hasAttribute('data-remember')) { return; }
		syncVisibility();
		schedule();
	}
	form.addEventListener('input', onEdit);
	form.addEventListener('change', onEdit);
	form.addEventListener('submit', function (e) { e.preventDefault(); });

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-action]');
		if (!btn) { return; }
		var action = btn.getAttribute('data-action');
		if (action === 'print') { printPayslip(); }
		if (action === 'next') { nextMonth(); }
		if (action === 'logo-remove') { setLogo(''); remember(); }
		if (action === 'reset') {
			form.reset();
			setLogo('');
			var st = storage();
			try { if (st) { st.removeItem(window.NHP.store); } } catch (err) { /* ignore */ }
			status('');
			syncVisibility();
			calculate();
		}
	});

	restore();
	syncVisibility();
	calculate();
})();
