(function (root) {
	function digits(value) {
		var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
		var arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
		var text = String(value || '');
		for (var i = 0; i < 10; i++) {
			text = text.split(persian[i]).join(String(i));
			text = text.split(arabic[i]).join(String(i));
		}
		return text;
	}

	function parseAmount(value) {
		var text = digits(value).split(',').join('').split('٬').join('').split(' ').join('').split('٫').join('.');
		if (text === '' || isNaN(Number(text))) {
			return null;
		}
		var amount = parseFloat(text);
		if (!isFinite(amount) || amount <= 0) {
			return null;
		}
		return amount;
	}

	function toRial(amount, factor) {
		var scaled = amount * factor;
		var nearest = Math.round(scaled);
		if (Math.abs(scaled - nearest) < 1e-8) {
			return nearest;
		}
		return Math.ceil(scaled);
	}

	function formatStore(rial, factor, decimals) {
		return (rial / factor).toFixed(decimals);
	}

	function chargeRial(entered, remainingRial, factor, decimals) {
		var shown = formatStore(remainingRial, factor, decimals);
		var typed = Number(entered).toFixed(decimals);
		if (shown === typed) {
			return remainingRial;
		}
		var rial = toRial(entered, factor);
		if (rial > remainingRial && (rial - remainingRial) < factor) {
			return remainingRial;
		}
		return rial;
	}

	function majorFromTotals(totals) {
		if (!totals || totals.total_price === undefined || totals.total_price === null || totals.total_price === '') {
			return null;
		}
		var minor = parseInt(totals.currency_minor_unit, 10);
		if (isNaN(minor) || minor < 0) {
			minor = 0;
		}
		var major = Number(totals.total_price) / Math.pow(10, minor);
		return isFinite(major) ? major : null;
	}

	function amountProblem(providerId, amount, payable, providerRows, factor, decimals) {
		if (!providerId) {
			return 'روش بخش اول را انتخاب کنید.';
		}
		var parsed = parseAmount(amount);
		if (parsed === null) {
			return 'مبلغ این مرحله را وارد کنید.';
		}
		if (payable.rial === null) {
			return '';
		}
		var charged = chargeRial(parsed, payable.rial, factor, decimals);
		if (charged < 1 || charged > payable.rial) {
			return 'مبلغ را تا سقف باقی‌مانده وارد کنید.';
		}
		var chosen = null;
		var i;
		for (i = 0; i < providerRows.length; i++) {
			if (providerRows[i].id === providerId) {
				chosen = providerRows[i];
			}
		}
		var min = chosen ? Number(chosen.min) : 0;
		if (min > 0 && charged < min) {
			return chosen.minLabel ? 'این درگاه حداقل ' + chosen.minLabel + ' می‌خواهد.' : 'مبلغ این مرحله از حداقل درگاه کمتر است.';
		}
		return '';
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = {
			parseAmount: parseAmount,
			toRial: toRial,
			formatStore: formatStore,
			chargeRial: chargeRial,
			majorFromTotals: majorFromTotals,
			amountProblem: amountProblem
		};
	}

	if (!root.wc || !root.wc.wcSettings || !root.wc.wcBlocksRegistry || !root.wp || !root.wp.element) {
		return;
	}

	var settings = root.wc.wcSettings.getSetting('chandpay_data', {});
	var el = root.wp.element.createElement;
	var useState = root.wp.element.useState;
	var useEffect = root.wp.element.useEffect;
	var decode = root.wp.htmlEntities && root.wp.htmlEntities.decodeEntities
		? root.wp.htmlEntities.decodeEntities
		: function (value) { return value; };
	var label = decode(settings.title || 'Chandpay');
	var description = decode(settings.description || '');
	var providers = Array.isArray(settings.providers) ? settings.providers : [];
	var symbol = decode(settings.symbol || '');
	var decimals = parseInt(settings.decimals, 10);
	var factor = parseInt(settings.factor, 10);
	if (isNaN(decimals) || decimals < 0) {
		decimals = 0;
	}
	if (isNaN(factor) || factor < 1) {
		factor = 1;
	}

	function format(value) {
		return value.toLocaleString('fa-IR', { maximumFractionDigits: 4 });
	}

	function readCartMajor() {
		try {
			var blocksData = root.wc.wcBlocksData;
			var storeKey = blocksData && blocksData.CART_STORE_KEY;
			var select = root.wp.data && root.wp.data.select;
			if (!storeKey || typeof select !== 'function') {
				return null;
			}
			var store = select(storeKey);
			if (!store || typeof store.getCartTotals !== 'function') {
				return null;
			}
			return majorFromTotals(store.getCartTotals());
		} catch (err) {
			return null;
		}
	}

	function payableFromMajor(major) {
		var rial = toRial(major, factor);
		return { store: formatStore(rial, factor, decimals), rial: rial };
	}

	function fallbackPayable() {
		var store = String(settings.total || '');
		if (store === '') {
			return { store: '', rial: null };
		}
		var parsed = parseFloat(store);
		return { store: store, rial: isFinite(parsed) ? toRial(parsed, factor) : null };
	}

	function Content(props) {
		var eventRegistration = props && props.eventRegistration ? props.eventRegistration : {};
		var emitResponse = props && props.emitResponse ? props.emitResponse : {};
		var onPaymentSetup = eventRegistration.onPaymentSetup;
		var providerState = useState('');
		var amountState = useState('');
		var payableState = useState(fallbackPayable);
		var provider = providerState[0];
		var setProvider = providerState[1];
		var amount = amountState[0];
		var setAmount = amountState[1];
		var payable = payableState[0];
		var setPayable = payableState[1];
		var parsed = parseAmount(amount);
		var problem = amountProblem(provider, amount, payable, providers, factor, decimals);
		var totalNumber = parseFloat(payable.store);
		var excess = parsed === null || !isFinite(totalNumber) ? 0 : parsed - totalNumber;
		var belowMin = problem.indexOf('حداقل') !== -1;

		useEffect(function () {
			function pull() {
				var major = readCartMajor();
				if (major === null) {
					return;
				}
				var next = payableFromMajor(major);
				setPayable(function (prev) {
					if (prev.store === next.store && prev.rial === next.rial) {
						return prev;
					}
					return next;
				});
			}
			pull();
			var subscribe = root.wp.data && root.wp.data.subscribe;
			if (typeof subscribe !== 'function') {
				return undefined;
			}
			return subscribe(pull);
		}, []);

		useEffect(function () {
			if (typeof onPaymentSetup !== 'function') {
				return undefined;
			}
			var unsubscribe = onPaymentSetup(function () {
				var success = emitResponse.responseTypes ? emitResponse.responseTypes.SUCCESS : 'success';
				var error = emitResponse.responseTypes ? emitResponse.responseTypes.ERROR : 'error';
				var message = amountProblem(provider, amount, payable, providers, factor, decimals);
				if (message) {
					return {
						type: error,
						message: message
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
		}, [onPaymentSetup, emitResponse, provider, amount, payable]);

		var choices = providers.map(function (item) {
			var selected = provider === item.id;
			var itemParsed = selected ? parsed : null;
			var itemProblem = selected ? problem : '';
			var itemExcess = selected && itemParsed !== null && isFinite(totalNumber) ? itemParsed - totalNumber : 0;
			var itemBelowMin = selected && itemProblem.indexOf('حداقل') !== -1;
			var remainderClass = 'chandpay-remainder';
			var remainderLabel = 'باقی‌مانده بعد از این مبلغ';
			var remainderValue = null;
			if (selected && itemProblem && itemParsed !== null && (itemExcess > 0 || itemProblem.indexOf('سقف') !== -1)) {
				remainderClass += ' is-over';
				remainderLabel = itemExcess > 0 ? format(itemExcess) + ' بیشتر از باقی‌مانده است' : itemProblem;
			} else if (itemBelowMin) {
				remainderClass += ' is-invalid';
				remainderLabel = itemProblem;
			} else if (selected && isFinite(totalNumber)) {
				var left = Math.max(0, totalNumber - (itemParsed === null ? 0 : itemParsed));
				remainderValue = format(left);
			}
			var amountId = 'chandpay-block-amount-' + item.id;

			return el('div', {
				key: item.id,
				className: 'chandpay-choice-item' + (selected ? ' is-selected' : '')
			},
				el('label', { className: 'chandpay-choice' },
					el('input', {
						type: 'radio',
						name: 'chandpay_provider_block',
						value: item.id,
						checked: selected,
						onChange: function () {
							setProvider(item.id);
							setAmount('');
						}
					}),
					item.logo ? el('img', { className: 'chandpay-logo', src: item.logo, alt: '' }) : null,
					el('span', { className: 'chandpay-choice-name' }, item.label)
				),
				selected ? el('div', { className: 'chandpay-choice-amount' },
					el('div', { className: 'chandpay-field' },
						el('div', { className: 'chandpay-field-head' },
							el('label', { htmlFor: amountId }, 'مبلغ این مرحله'),
							el('button', {
								type: 'button',
								className: 'chandpay-text-button chandpay-fill-remaining',
								onClick: function () { setAmount(payable.store || ''); }
							}, 'کل باقی‌مانده')
						),
						el('div', { className: 'chandpay-control' },
							el('input', {
								id: amountId,
								className: 'chandpay-amount-input',
								type: 'text',
								inputMode: 'decimal',
								autoComplete: 'off',
								'aria-invalid': itemProblem && itemParsed !== null ? true : false,
								value: amount,
								onChange: function (event) { setAmount(event.target.value); }
							}),
							symbol ? el('span', { className: 'chandpay-unit' }, symbol) : null
						),
						amount.trim() !== '' ? el('p', { className: 'chandpay-amount-note is-visible' },
							'مبلغ اولیه را پرداخت کنید تا سفارش شما رزرو شود.',
							el('br', null),
							'باقی‌مانده مبلغ را می‌توانید از طریق سایر روش‌های پرداخت اعتباری و آنلاین پرداخت کنید.'
						) : null,
						el('p', { className: remainderClass },
							el('span', { className: 'chandpay-remainder-label' }, remainderLabel),
							remainderValue === null ? null : el('strong', { className: 'chandpay-live-remaining' }, remainderValue)
						)
					)
				) : null
			);
		});

		return el('div', { className: 'chandpay-checkout', dir: 'rtl' },
			description ? el('p', { className: 'chandpay-lead' }, description) : null,
			providers.length ? el('fieldset', { className: 'chandpay-choices' },
				el('legend', null, 'برای شروع اولین درگاه را انتخاب کنید'),
				el('p', { className: 'chandpay-choices-desc' }, 'می‌توانید مبلغ باقی‌مانده را در مراحل بعدی از سایر درگاه‌های اعتباری (اقساطی) پرداخت کنید.'),
				choices
			) : el('p', { className: 'chandpay-note is-error' }, 'هیچ درگاهی تنظیم نشده است.')
		);
	}

	root.wc.wcBlocksRegistry.registerPaymentMethod({
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
}(typeof window !== 'undefined' ? window : global));
