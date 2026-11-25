<?php
/**
 * First payment fields inside the WooCommerce payment box.
 *
 * Variables: $description, $providers, $remaining, $symbol.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

$description = isset( $description ) ? $description : '';
$providers   = isset( $providers ) && is_array( $providers ) ? $providers : array();
$remaining   = isset( $remaining ) ? (string) $remaining : '';
$symbol      = isset( $symbol ) ? $symbol : '';
?>
<div class="chandpay-checkout" dir="rtl">
	<?php if ( $description ) : ?>
		<p class="chandpay-lead"><?php echo wp_kses_post( $description ); ?></p>
	<?php endif; ?>

	<?php if ( ! $providers ) : ?>
		<p class="chandpay-note is-error"><?php esc_html_e( 'No installment provider is configured.', 'chandpay-for-woocommerce' ); ?></p>
	<?php else : ?>
		<div id="chandpay-start" class="chandpay-fields" data-remaining="<?php echo esc_attr( $remaining ); ?>">
			<fieldset class="chandpay-choices">
				<legend><?php echo esc_html__( 'برای شروع اولین درگاه را انتخاب کنید', 'chandpay-for-woocommerce' ); ?></legend>
				<p class="chandpay-choices-desc"><?php echo esc_html__( 'می‌توانید مبلغ باقی‌مانده را در مراحل بعدی از سایر درگاه‌های اعتباری (اقساطی) پرداخت کنید.', 'chandpay-for-woocommerce' ); ?></p>
				<?php foreach ( $providers as $provider ) : ?>
					<?php
					$amount_id = 'chandpay-amount-' . $provider['id'];
					?>
					<div class="chandpay-choice-item">
						<label class="chandpay-choice">
							<input type="radio" name="chandpay_provider" value="<?php echo esc_attr( $provider['id'] ); ?>" required />
							<?php if ( ! empty( $provider['logo'] ) ) : ?>
								<img class="chandpay-logo" src="<?php echo esc_url( $provider['logo'] ); ?>" alt="" />
							<?php endif; ?>
							<span class="chandpay-choice-name"><?php echo esc_html( $provider['label'] ); ?></span>
						</label>
						<div class="chandpay-choice-amount">
							<div class="chandpay-field">
								<div class="chandpay-field-head">
									<label for="<?php echo esc_attr( $amount_id ); ?>"><?php echo esc_html__( 'مبلغ این مرحله', 'chandpay-for-woocommerce' ); ?></label>
									<button type="button" class="chandpay-text-button chandpay-fill-remaining"><?php echo esc_html__( 'کل باقی‌مانده', 'chandpay-for-woocommerce' ); ?></button>
								</div>
								<div class="chandpay-control">
									<input
										id="<?php echo esc_attr( $amount_id ); ?>"
										class="chandpay-amount-input"
										type="text"
										inputmode="decimal"
										autocomplete="off"
									/>
									<?php if ( $symbol ) : ?>
										<span class="chandpay-unit"><?php echo wp_kses_post( $symbol ); ?></span>
									<?php endif; ?>
								</div>
								<p class="chandpay-amount-note" hidden>
									<?php echo esc_html__( 'مبلغ اولیه را پرداخت کنید تا سفارش شما رزرو شود.', 'chandpay-for-woocommerce' ); ?>
									<br />
									<?php echo esc_html__( 'باقی‌مانده مبلغ را می‌توانید از طریق سایر روش‌های پرداخت اعتباری و آنلاین پرداخت کنید.', 'chandpay-for-woocommerce' ); ?>
								</p>
								<p class="chandpay-remainder">
									<span class="chandpay-remainder-label"><?php echo esc_html__( 'باقی‌مانده بعد از این مبلغ', 'chandpay-for-woocommerce' ); ?></span>
									<strong class="chandpay-live-remaining"><?php echo esc_html( $remaining ); ?></strong>
								</p>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</fieldset>
		</div>
	<?php endif; ?>
</div>