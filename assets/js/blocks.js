(function () {
	if (!window.wc || !window.wc.wcSettings || !window.wc.wcBlocksRegistry || !window.wp) {
		return;
	}
	var settings = window.wc.wcSettings.getSetting('chandpay_data', {});
	var decode = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
		? window.wp.htmlEntities.decodeEntities
		: function (value) { return value; };
	var label = decode(settings.title || 'Chandpay');
	var description = decode(settings.description || '');

	window.wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'chandpay',
		label: label,
		content: window.wp.element.createElement('div', null, description),
		edit: window.wp.element.createElement('div', null, description),
		canMakePayment: function () { return true; },
		ariaLabel: label,
		supports: {
			features: settings.supports || ['products']
		}
	});
}());
