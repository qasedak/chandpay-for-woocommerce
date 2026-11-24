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
				<?php foreach ( $providers as $provider ) : ?>
					<label class="chandpay-choice">
						<input type="radio" name="chandpay_provider" value="<?php echo esc_attr( $provider['id'] ); ?>" required />
						<?php if ( ! empty( $provider['logo'] ) ) : ?>
							<img class="chandpay-logo" src="<?php echo esc_url( $provider['logo'] ); ?>" alt="" />
						<?php endif; ?>
						<span class="chandpay-choice-body">
							<span class="chandpay-choice-name"><?php echo esc_html( $provider['label'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="chandpay-field">
				<div class="chandpay-field-head">
					<label for="chandpay-amount"><?php echo esc_html__( 'مبلغ این بخش', 'chandpay-for-woocommerce' ); ?></label>
					<button type="button" class="chandpay-text-button" id="chandpay-fill-remaining"><?php echo esc_html__( 'کل باقی‌مانده', 'chandpay-for-woocommerce' ); ?></button>
				</div>
				<div class="chandpay-control">
					<input id="chandpay-amount" name="chandpay_amount" type="text" inputmode="decimal" autocomplete="off" required />
					<?php if ( $symbol ) : ?>
						<span class="chandpay-unit"><?php echo wp_kses_post( $symbol ); ?></span>
					<?php endif; ?>
				</div>
				<p class="chandpay-remainder" id="chandpay-remainder">
					<span class="chandpay-remainder-label"><?php echo esc_html__( 'باقی‌مانده بعد از این مبلغ', 'chandpay-for-woocommerce' ); ?></span>
					<strong id="chandpay-live-remaining"><?php echo esc_html( $remaining ); ?></strong>
				</p>
			</div>
		</div>
	<?php endif; ?>
</div>
