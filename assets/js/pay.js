(function () {
	function digits(value) {
		var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
		var arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
		var text = String(value || '');
		var i;
		for (i = 0; i < 10; i++) {
			text = text.split(persian[i]).join(String(i));
			text = text.split(arabic[i]).join(String(i));
		}
		return text.replace(/,/g, '').replace(/٬/g, '').replace(/٫/g, '.');
	}

	function rootOf(node) {
		if (node && node.closest) {
			var found = node.closest('#chandpay-start');
			if (found) {
				return found;
			}
		}
		return document.getElementById('chandpay-start');
	}

	function format(value) {
		return value.toLocaleString('fa-IR', { maximumFractionDigits: 4 });
	}

	function render(input) {
		var root = rootOf(input);
		if (!root || !input) {
			return;
		}
		var out = root.querySelector('#chandpay-live-remaining');
		var remainder = root.querySelector('#chandpay-remainder');
		var label = root.querySelector('.chandpay-remainder-label');
		var remaining = parseFloat(root.getAttribute('data-remaining'));
		if (!out || isNaN(remaining)) {
			return;
		}
		var raw = digits(input.value).trim();
		var value = parseFloat(raw);
		var empty = raw === '' || isNaN(value) || value < 0;
		if (empty) {
			value = 0;
		}
		var left = remaining - value;
		var over = !empty && left < -0.0000001;
		if (over) {
			out.textContent = '';
			if (label) {
				label.textContent = format(Math.abs(left)) + ' بیشتر از باقی‌مانده است';
			}
		} else {
			out.textContent = format(Math.max(0, left));
			if (label) {
				label.textContent = 'باقی‌مانده بعد از این مبلغ';
			}
		}
		if (remainder) {
			remainder.classList.toggle('is-over', over);
		}
		input.setAttribute('aria-invalid', over ? 'true' : 'false');
	}

	document.addEventListener('input', function (event) {
		var target = event.target;
		if (!target || target.id !== 'chandpay-amount') {
			return;
		}
		render(target);
	}, true);

	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('#chandpay-fill-remaining') : null;
		if (!button) {
			return;
		}
		var root = rootOf(button);
		if (!root) {
			return;
		}
		var input = root.querySelector('#chandpay-amount');
		if (!input) {
			return;
		}
		input.value = root.getAttribute('data-remaining') || '';
		render(input);
		input.focus();
	}, true);

	function boot() {
		var input = document.getElementById('chandpay-amount');
		if (input) {
			render(input);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
