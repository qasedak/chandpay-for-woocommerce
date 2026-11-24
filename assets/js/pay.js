(function () {
	var form = document.getElementById('chandpay-start');
	if (!form) {
		return;
	}
	var input = document.getElementById('chandpay-amount');
	var out = document.getElementById('chandpay-live-remaining');
	var remaining = parseFloat(form.getAttribute('data-remaining'));
	if (!input || !out || isNaN(remaining)) {
		return;
	}

	function digits(value) {
		var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
		var arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
		var text = String(value || '');
		for (var i = 0; i < 10; i++) {
			text = text.split(persian[i]).join(String(i));
			text = text.split(arabic[i]).join(String(i));
		}
		return text.replace(/,/g, '').replace(/٬/g, '').replace(/٫/g, '.');
	}

	function render() {
		var raw = digits(input.value);
		var value = parseFloat(raw);
		if (isNaN(value) || value < 0) {
			value = 0;
		}
		var left = remaining - value;
		if (left < 0) {
			left = 0;
		}
		out.textContent = left.toLocaleString('fa-IR');
	}

	input.addEventListener('input', render);
}());
