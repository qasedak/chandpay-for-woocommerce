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

	function render(input) {
		var root = input.closest ? input.closest('#chandpay-start') : null;
		if (!root) {
			root = document.getElementById('chandpay-start');
		}
		if (!root) {
			return;
		}
		var out = root.querySelector('#chandpay-live-remaining');
		var remaining = parseFloat(root.getAttribute('data-remaining'));
		if (!out || isNaN(remaining)) {
			return;
		}
		var value = parseFloat(digits(input.value));
		if (isNaN(value) || value < 0) {
			value = 0;
		}
		var left = remaining - value;
		if (left < 0) {
			left = 0;
		}
		out.textContent = left.toLocaleString('fa-IR');
	}

	document.addEventListener('input', function (event) {
		var target = event.target;
		if (!target || target.id !== 'chandpay-amount') {
			return;
		}
		render(target);
	}, true);
}());
