<?php
/**
 * Pay-remaining screen.
 *
 * Variables: $view.
 *
 * @package Chandpay
 */

defined( 'ABSPATH' ) || exit;

$view = isset( $view ) && is_array( $view ) ? $view : array();

/**
 * Provider logo and name.
 *
 * @param array $part Provider row.
 * @return void
 */
$chandpay_party = static function ( $part ) {
	if ( ! empty( $part['logo'] ) ) {
		echo '<img class="chandpay-logo" src="' . esc_url( $part['logo'] ) . '" alt="" />';
	}
	echo '<span class="chandpay-party-name">' . esc_html( $part['label'] ) . '</span>';
};
?>
<main class="chandpay" dir="rtl" lang="fa">
	<article class="chandpay-sheet">
		<header class="chandpay-hero">
			<p class="chandpay-kicker"><?php echo esc_html__( 'پرداخت باقی‌مانده', 'chandpay-for-woocommerce' ); ?></p>
			<h1><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'سفارش %s', 'chandpay-for-woocommerce' ), $view['order_number'] ) ); ?></h1>
		</header>

		<?php if ( ! empty( $view['flash']['message'] ) ) : ?>
			<p class="chandpay-note <?php echo 'error' === $view['flash']['type'] ? 'is-error' : 'is-info'; ?>" <?php echo 'error' === $view['flash']['type'] ? 'role="alert"' : 'role="status"'; ?>>
				<?php echo esc_html( $view['flash']['message'] ); ?>
			</p>
		<?php endif; ?>

		<section class="chandpay-summary" aria-label="<?php echo esc_attr__( 'خلاصه سفارش', 'chandpay-for-woocommerce' ); ?>">
			<div class="chandpay-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $view['progress'] ); ?>" aria-label="<?php echo esc_attr__( 'سهم پرداخت‌شده', 'chandpay-for-woocommerce' ); ?>">
				<span style="width: <?php echo esc_attr( (string) (int) $view['progress'] ); ?>%"></span>
			</div>
			<p class="chandpay-meter-label">
				<span><?php echo esc_html__( 'پرداخت‌شده', 'chandpay-for-woocommerce' ); ?></span>
				<span><?php echo esc_html( number_format_i18n( (int) $view['progress'] ) ); ?>٪</span>
			</p>
			<div class="chandpay-row">
				<span><?php echo esc_html__( 'مبلغ سفارش', 'chandpay-for-woocommerce' ); ?></span>
				<strong><?php echo wp_kses_post( $view['total_html'] ); ?></strong>
			</div>
			<div class="chandpay-row">
				<span><?php echo esc_html__( 'تأیید شده', 'chandpay-for-woocommerce' ); ?></span>
				<strong><?php echo wp_kses_post( $view['captured_html'] ); ?></strong>
			</div>
			<div class="chandpay-row chandpay-remaining">
				<span><?php echo esc_html__( 'باقی‌مانده', 'chandpay-for-woocommerce' ); ?></span>
				<strong><?php echo wp_kses_post( $view['remaining_html'] ); ?></strong>
			</div>
		</section>

		<?php if ( $view['done'] ) : ?>
			<section class="chandpay-done-wrap">
				<h2><?php echo esc_html__( 'بخش‌های تأیید شده', 'chandpay-for-woocommerce' ); ?></h2>
				<ul class="chandpay-done">
					<?php foreach ( $view['done'] as $part ) : ?>
						<li>
							<?php $chandpay_party( $part ); ?>
							<strong><?php echo wp_kses_post( $part['amount'] ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( $view['closed'] ) : ?>
			<p class="chandpay-note is-info"><?php echo esc_html__( 'این سفارش دیگر پرداخت نمی‌پذیرد.', 'chandpay-for-woocommerce' ); ?></p>
		<?php elseif ( $view['open'] ) : ?>
			<section class="chandpay-state is-open">
				<h2><?php echo esc_html__( 'یک پرداخت در جریان است', 'chandpay-for-woocommerce' ); ?></h2>
				<div class="chandpay-party">
					<?php $chandpay_party( $view['open'] ); ?>
					<strong><?php echo wp_kses_post( $view['open']['amount'] ); ?></strong>
				</div>
				<div class="chandpay-actions<?php echo $view['open']['url'] ? ' is-split' : ''; ?>">
					<?php if ( $view['open']['url'] ) : ?>
						<a class="chandpay-button" href="<?php echo esc_url( $view['open']['url'] ); ?>"><?php echo esc_html__( 'ادامه همین پرداخت', 'chandpay-for-woocommerce' ); ?></a>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>">
						<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
						<input type="hidden" name="chandpay_action" value="abandon" />
						<input type="hidden" name="chandpay_part" value="<?php echo esc_attr( $view['open']['id'] ); ?>" />
						<button class="chandpay-button chandpay-button-ghost" type="submit"><?php echo esc_html__( 'انصراف و آزاد کردن مبلغ', 'chandpay-for-woocommerce' ); ?></button>
					</form>
				</div>
				<p class="chandpay-hint"><?php echo esc_html__( 'اگر پرداخت را رها کنید، مبلغ تأییدنشده نزد درگاه خودش برمی‌گردد.', 'chandpay-for-woocommerce' ); ?></p>
			</section>
		<?php elseif ( $view['failed'] ) : ?>
			<section class="chandpay-state is-failed">
				<h2><?php echo esc_html__( 'این پرداخت ناموفق بود', 'chandpay-for-woocommerce' ); ?></h2>
				<div class="chandpay-party">
					<?php $chandpay_party( $view['failed'] ); ?>
					<strong><?php echo wp_kses_post( $view['failed']['amount'] ); ?></strong>
				</div>
				<?php if ( $view['failed']['error'] ) : ?>
					<p class="chandpay-note is-error" role="alert"><?php echo esc_html( $view['failed']['error'] ); ?></p>
				<?php endif; ?>
				<div class="chandpay-actions is-split">
					<form method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>">
						<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
						<input type="hidden" name="chandpay_action" value="retry" />
						<input type="hidden" name="chandpay_part" value="<?php echo esc_attr( $view['failed']['id'] ); ?>" />
						<button class="chandpay-button" type="submit"><?php echo esc_html__( 'تلاش دوباره', 'chandpay-for-woocommerce' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>">
						<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
						<input type="hidden" name="chandpay_action" value="change" />
						<input type="hidden" name="chandpay_part" value="<?php echo esc_attr( $view['failed']['id'] ); ?>" />
						<button class="chandpay-button chandpay-button-ghost" type="submit"><?php echo esc_html__( 'تغییر روش', 'chandpay-for-woocommerce' ); ?></button>
					</form>
				</div>
			</section>
		<?php elseif ( $view['can_start'] ) : ?>
			<form id="chandpay-start" class="chandpay-state" method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>" data-remaining="<?php echo esc_attr( $view['remaining_store'] ); ?>">
				<h2><?php echo esc_html__( 'روش و مبلغ این بخش', 'chandpay-for-woocommerce' ); ?></h2>
				<p class="chandpay-hint"><?php echo esc_html__( 'بعد از پرداخت همین بخش، اگر مبلغی بماند به همین صفحه برمی‌گردید.', 'chandpay-for-woocommerce' ); ?></p>
				<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
				<input type="hidden" name="chandpay_action" value="start" />
				<fieldset class="chandpay-choices">
					<legend><?php echo esc_html__( 'برای شروع اولین درگاه را انتخاب کنید', 'chandpay-for-woocommerce' ); ?></legend>
					<?php foreach ( $view['providers'] as $provider ) : ?>
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
						<?php if ( ! empty( $view['symbol'] ) ) : ?>
							<span class="chandpay-unit"><?php echo wp_kses_post( $view['symbol'] ); ?></span>
						<?php endif; ?>
					</div>
					<p class="chandpay-remainder" id="chandpay-remainder">
						<span class="chandpay-remainder-label"><?php echo esc_html__( 'باقی‌مانده بعد از این مبلغ', 'chandpay-for-woocommerce' ); ?></span>
						<strong id="chandpay-live-remaining"><?php echo esc_html( $view['remaining_store'] ); ?></strong>
					</p>
				</div>
				<button class="chandpay-button" type="submit"><?php echo esc_html__( 'پرداخت این بخش', 'chandpay-for-woocommerce' ); ?></button>
			</form>
		<?php elseif ( ! empty( $view['waiting'] ) ) : ?>
			<p class="chandpay-note is-ok" role="status"><?php echo esc_html__( 'همه بخش‌ها ثبت شده‌اند. اگر به صفحه تشکر نرفتید، صفحه را یک‌بار تازه کنید.', 'chandpay-for-woocommerce' ); ?></p>
		<?php elseif ( ! $view['providers'] ) : ?>
			<p class="chandpay-note is-error"><?php echo esc_html__( 'هیچ درگاه اقساطی برای چندپرداخت تنظیم نشده است.', 'chandpay-for-woocommerce' ); ?></p>
		<?php endif; ?>
	</article>
</main>
