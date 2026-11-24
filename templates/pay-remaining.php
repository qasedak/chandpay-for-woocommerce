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
?>
<main class="chandpay-wrap" dir="rtl">
	<h1><?php echo esc_html( sprintf( 'پرداخت باقی‌مانده سفارش %s', $view['order_number'] ) ); ?></h1>

	<?php if ( ! empty( $view['flash']['message'] ) ) : ?>
		<p class="<?php echo 'error' === $view['flash']['type'] ? 'chandpay-error' : 'chandpay-info'; ?>">
			<?php echo esc_html( $view['flash']['message'] ); ?>
		</p>
	<?php endif; ?>

	<section class="chandpay-card">
		<div class="chandpay-row">
			<span>مبلغ سفارش</span>
			<strong><?php echo wp_kses_post( $view['total_html'] ); ?></strong>
		</div>
		<div class="chandpay-row">
			<span>تأیید شده</span>
			<strong><?php echo wp_kses_post( $view['captured_html'] ); ?></strong>
		</div>
		<div class="chandpay-row chandpay-remaining">
			<span>باقی‌مانده</span>
			<strong><?php echo wp_kses_post( $view['remaining_html'] ); ?></strong>
		</div>
	</section>

	<?php if ( $view['done'] ) : ?>
		<section class="chandpay-card">
			<h2>بخش‌های تأیید شده</h2>
			<ul>
				<?php foreach ( $view['done'] as $part ) : ?>
					<li><?php echo esc_html( $part['label'] ); ?> — <?php echo wp_kses_post( $part['amount'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( $view['closed'] ) : ?>
		<p class="chandpay-info">این سفارش دیگر پرداخت نمی‌پذیرد.</p>
	<?php elseif ( $view['open'] ) : ?>
		<section class="chandpay-card">
			<h2>یک پرداخت در جریان است</h2>
			<p>
				<?php echo esc_html( $view['open']['label'] ); ?>
				—
				<?php echo wp_kses_post( $view['open']['amount'] ); ?>
			</p>
			<div class="chandpay-actions">
				<?php if ( $view['open']['url'] ) : ?>
					<a class="chandpay-button" href="<?php echo esc_url( $view['open']['url'] ); ?>">ادامه همین پرداخت</a>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>">
					<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
					<input type="hidden" name="chandpay_action" value="abandon" />
					<input type="hidden" name="chandpay_part" value="<?php echo esc_attr( $view['open']['id'] ); ?>" />
					<button type="submit">انصراف و آزاد کردن مبلغ</button>
				</form>
			</div>
			<p>اگر پرداخت را رها کنید، مبلغ تأییدنشده نزد درگاه خودش برمی‌گردد.</p>
		</section>
	<?php elseif ( $view['failed'] ) : ?>
		<section class="chandpay-card">
			<h2>این پرداخت ناموفق بود</h2>
			<p>
				<?php echo esc_html( $view['failed']['label'] ); ?>
				—
				<?php echo wp_kses_post( $view['failed']['amount'] ); ?>
			</p>
			<?php if ( $view['failed']['error'] ) : ?>
				<p class="chandpay-error"><?php echo esc_html( $view['failed']['error'] ); ?></p>
			<?php endif; ?>
			<div class="chandpay-actions">
				<form method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>">
					<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
					<input type="hidden" name="chandpay_action" value="retry" />
					<input type="hidden" name="chandpay_part" value="<?php echo esc_attr( $view['failed']['id'] ); ?>" />
					<button type="submit">تلاش دوباره</button>
				</form>
				<form method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>">
					<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
					<input type="hidden" name="chandpay_action" value="change" />
					<input type="hidden" name="chandpay_part" value="<?php echo esc_attr( $view['failed']['id'] ); ?>" />
					<button type="submit">تغییر روش</button>
				</form>
			</div>
		</section>
	<?php elseif ( $view['can_start'] ) : ?>
		<form id="chandpay-start" class="chandpay-card" method="post" action="<?php echo esc_url( $view['pay_url'] ); ?>" data-remaining="<?php echo esc_attr( $view['remaining_store'] ); ?>">
			<h2>روش و مبلغ این بخش</h2>
			<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field(). ?>
			<input type="hidden" name="chandpay_action" value="start" />
			<fieldset>
				<legend>درگاه</legend>
				<?php foreach ( $view['providers'] as $provider ) : ?>
					<label>
						<input type="radio" name="chandpay_provider" value="<?php echo esc_attr( $provider['id'] ); ?>" required />
						<?php echo esc_html( $provider['label'] ); ?>
						<?php if ( $provider['min'] ) : ?>
							<small>حداقل <?php echo wp_kses_post( $provider['min'] ); ?></small>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<label for="chandpay-amount">مبلغ این بخش</label>
			<input id="chandpay-amount" name="chandpay_amount" type="text" inputmode="decimal" required />
			<p>باقی‌مانده بعد از این مبلغ: <strong id="chandpay-live-remaining"><?php echo esc_html( $view['remaining_store'] ); ?></strong></p>
			<button type="submit">پرداخت این بخش</button>
		</form>
	<?php elseif ( ! empty( $view['waiting'] ) ) : ?>
		<p class="chandpay-info">همه بخش‌ها ثبت شده‌اند. اگر به صفحه تشکر نرفتید، صفحه را یک‌بار تازه کنید.</p>
	<?php elseif ( ! $view['providers'] ) : ?>
		<p class="chandpay-error">هیچ درگاه اقساطی برای چندپرداخت تنظیم نشده است.</p>
	<?php endif; ?>
</main>
