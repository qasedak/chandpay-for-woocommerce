(function () {
	if (!window.wc || !window.wc.wcSettings || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
		return;
	}

	var settings = window.wc.wcSettings.getSetting('chandpay_data', {});
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decode = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
		? window.wp.htmlEntities.decodeEntities
		: function (value) { return value; };
	var label = decode(settings.title || 'Chandpay');
	var description = decode(settings.description || '');
	var providers = Array.isArray(settings.providers) ? settings.providers : [];

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

	function Content(props) {
		var eventRegistration = props && props.eventRegistration ? props.eventRegistration : {};
		var emitResponse = props && props.emitResponse ? props.emitResponse : {};
		var onPaymentSetup = eventRegistration.onPaymentSetup;
		var providerState = useState(providers.length ? providers[0].id : '');
		var amountState = useState('');
		var provider = providerState[0];
		var setProvider = providerState[1];
		var amount = amountState[0];
		var setAmount = amountState[1];
		var total = parseFloat(settings.total);
		var entered = parseFloat(digits(amount));
		var left = isNaN(total) ? null : Math.max(0, total - (isNaN(entered) ? 0 : entered));

		useEffect(function () {
			if (typeof onPaymentSetup !== 'function') {
				return undefined;
			}
			var unsubscribe = onPaymentSetup(function () {
				var success = emitResponse.responseTypes ? emitResponse.responseTypes.SUCCESS : 'success';
				var error = emitResponse.responseTypes ? emitResponse.responseTypes.ERROR : 'error';
				if (!provider || !amount) {
					return {
						type: error,
						message: 'روش و مبلغ بخش اول را انتخاب کنید.'
					};
				}
				return {
					type: success,
					meta: {
						paymentMethodData: {
							chandpay_provider: provider,
							chandpay_amount: amount
						}
					}
				};
			});
			return unsubscribe;
		}, [onPaymentSetup, emitResponse, provider, amount]);

		var choices = providers.map(function (item) {
			return el('label', { key: item.id, className: 'chandpay-provider-choice' },
				el('input', {
					type: 'radio',
					name: 'chandpay_provider_block',
					value: item.id,
					checked: provider === item.id,
					onChange: function () { setProvider(item.id); }
				}),
				' ' + item.label + (item.minLabel ? ' (حداقل ' + item.minLabel + ')' : '')
			);
		});

		return el('div', { className: 'chandpay-checkout-fields' },
			description ? el('p', null, description) : null,
			choices,
			el('label', { htmlFor: 'chandpay-block-amount' }, 'مبلغ این بخش'),
			el('input', {
				id: 'chandpay-block-amount',
				type: 'text',
				inputMode: 'decimal',
				value: amount,
				onChange: function (event) { setAmount(event.target.value); }
			}),
			left === null ? null : el('p', null, 'باقی‌مانده بعد از این مبلغ: ' + left.toLocaleString('fa-IR'))
		);
	}

	window.wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'chandpay',
		label: label,
		content: el(Content, null),
		edit: el(Content, null),
		canMakePayment: function () { return providers.length > 0; },
		ariaLabel: label,
		supports: {
			features: settings.supports || ['products']
		}
	});
}());
