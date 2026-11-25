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

	function activeInput(root) {
		if (!root) {
			return null;
		}
		var checked = root.querySelector('input[name="chandpay_provider"]:checked');
		if (!checked) {
			return null;
		}
		var item = checked.closest('.chandpay-choice-item');
		return item ? item.querySelector('.chandpay-amount-input') : null;
	}

	function syncAmountFields(root, value) {
		if (!root) {
			return;
		}
		var inputs = root.querySelectorAll('.chandpay-amount-input');
		var i;
		for (i = 0; i < inputs.length; i++) {
			inputs[i].value = value;
		}
	}

	function syncSubmission(root) {
		if (!root) {
			return;
		}
		var inputs = root.querySelectorAll('.chandpay-amount-input');
		var i;
		for (i = 0; i < inputs.length; i++) {
			var item = inputs[i].closest('.chandpay-choice-item');
			var selected = item && item.querySelector('input[name="chandpay_provider"]:checked');
			if (selected) {
				inputs[i].setAttribute('name', 'chandpay_amount');
				inputs[i].required = true;
			} else {
				inputs[i].removeAttribute('name');
				inputs[i].required = false;
			}
		}
	}

	function toggleAmountNote(input) {
		var item = input && input.closest ? input.closest('.chandpay-choice-item') : null;
		if (!item) {
			return;
		}
		var note = item.querySelector('.chandpay-amount-note');
		if (!note) {
			return;
		}
		var show = digits(input.value).trim() !== '';
		note.hidden = !show;
		note.classList.toggle('is-visible', show);
	}

	function refreshAmountNotes(root) {
		if (!root) {
			return;
		}
		var inputs = root.querySelectorAll('.chandpay-amount-input');
		var i;
		for (i = 0; i < inputs.length; i++) {
			toggleAmountNote(inputs[i]);
		}
	}

	function render(input) {
		var root = rootOf(input);
		var item = input && input.closest ? input.closest('.chandpay-choice-item') : null;
		if (!root || !input) {
			return;
		}
		toggleAmountNote(input);
		var out = item ? item.querySelector('.chandpay-live-remaining') : null;
		var remainder = item ? item.querySelector('.chandpay-remainder') : null;
		var label = item ? item.querySelector('.chandpay-remainder-label') : null;
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

	function refreshAll(root) {
		if (!root) {
			return;
		}
		var inputs = root.querySelectorAll('.chandpay-amount-input');
		var i;
		for (i = 0; i < inputs.length; i++) {
			render(inputs[i]);
		}
	}

	document.addEventListener('input', function (event) {
		var target = event.target;
		if (!target || !target.classList || !target.classList.contains('chandpay-amount-input')) {
			return;
		}
		var root = rootOf(target);
		syncAmountFields(root, target.value);
		refreshAll(root);
	}, true);

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (!target || target.name !== 'chandpay_provider') {
			return;
		}
		var root = rootOf(target);
		syncAmountFields(root, '');
		syncSubmission(root);
		refreshAll(root);
		refreshAmountNotes(root);
		var carry = activeInput(root);
		if (carry) {
			render(carry);
			carry.focus();
		}
	}, true);

	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('.chandpay-fill-remaining') : null;
		if (!button) {
			return;
		}
		var root = rootOf(button);
		if (!root) {
			return;
		}
		var input = activeInput(root);
		if (!input) {
			return;
		}
		var value = root.getAttribute('data-remaining') || '';
		syncAmountFields(root, value);
		refreshAll(root);
		input.focus();
	}, true);

	function boot() {
		var root = document.getElementById('chandpay-start');
		if (!root) {
			return;
		}
		syncSubmission(root);
		refreshAll(root);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());