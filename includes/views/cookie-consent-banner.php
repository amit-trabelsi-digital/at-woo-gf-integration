<?php
/**
 * Frontend cookie-consent banner + preferences modal.
 *
 * @var array $settings  Provided by AT_Woo_GF_Cookie_Consent::render_banner().
 * @package AT_Woo_GF_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$position    = 'top' === $settings['position'] ? 'top' : 'bottom';
$categories  = $settings['categories'];
$cookies     = $settings['cookies'];
$privacy_url = $settings['privacy_url'];

// Group registered cookies by category for the modal disclosure.
$by_cat = array();
foreach ( $cookies as $c ) {
	$cat = isset( $c['category'] ) ? $c['category'] : 'necessary';
	$by_cat[ $cat ][] = $c;
}
?>
<div class="at-cc" dir="rtl" data-position="<?php echo esc_attr( $position ); ?>" hidden>

	<!-- Banner -->
	<div class="at-cc__banner" role="dialog" aria-modal="false" aria-label="<?php esc_attr_e( 'הודעת עוגיות', 'at-woo-gf-integration' ); ?>" data-at-cc-banner hidden>
		<div class="at-cc__banner-inner">
			<p class="at-cc__text">
				<?php echo wp_kses_post( wpautop( $settings['banner_text'] ) ); ?>
				<?php if ( $privacy_url ) : ?>
					<a class="at-cc__policy-link" href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'מדיניות פרטיות', 'at-woo-gf-integration' ); ?></a>
				<?php endif; ?>
			</p>
			<div class="at-cc__actions">
				<button type="button" class="at-cc__btn at-cc__btn--ghost" data-at-cc="customize"><?php esc_html_e( 'התאמה אישית', 'at-woo-gf-integration' ); ?></button>
				<button type="button" class="at-cc__btn at-cc__btn--ghost" data-at-cc="reject"><?php esc_html_e( 'דחה הכל', 'at-woo-gf-integration' ); ?></button>
				<button type="button" class="at-cc__btn at-cc__btn--primary" data-at-cc="accept"><?php esc_html_e( 'אשר הכל', 'at-woo-gf-integration' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Preferences modal -->
	<div class="at-cc__modal" data-at-cc-modal hidden>
		<div class="at-cc__overlay" data-at-cc="close" tabindex="-1"></div>
		<div class="at-cc__dialog" role="dialog" aria-modal="true" aria-labelledby="at-cc-modal-title">
			<div class="at-cc__dialog-head">
				<h2 id="at-cc-modal-title" class="at-cc__title"><?php esc_html_e( 'הגדרות עוגיות', 'at-woo-gf-integration' ); ?></h2>
				<button type="button" class="at-cc__close" data-at-cc="close" aria-label="<?php esc_attr_e( 'סגירה', 'at-woo-gf-integration' ); ?>">&times;</button>
			</div>

			<div class="at-cc__dialog-body">
				<?php foreach ( $categories as $key => $cat ) :
					$locked = ! empty( $cat['locked'] );
					$list   = isset( $by_cat[ $key ] ) ? $by_cat[ $key ] : array();
					?>
					<section class="at-cc__cat">
						<header class="at-cc__cat-head">
							<label class="at-cc__switch">
								<input type="checkbox"
									class="at-cc__cat-input"
									data-cat="<?php echo esc_attr( $key ); ?>"
									<?php echo $locked ? 'checked disabled' : ''; ?>>
								<span class="at-cc__switch-track" aria-hidden="true"></span>
								<span class="at-cc__cat-name"><?php echo esc_html( $cat['label'] ); ?><?php echo $locked ? ' (' . esc_html__( 'תמיד פעיל', 'at-woo-gf-integration' ) . ')' : ''; ?></span>
							</label>
						</header>
						<?php if ( ! empty( $cat['description'] ) ) : ?>
							<p class="at-cc__cat-desc"><?php echo esc_html( $cat['description'] ); ?></p>
						<?php endif; ?>

						<?php if ( ! empty( $list ) ) : ?>
							<details class="at-cc__cookies">
								<summary><?php echo esc_html( sprintf( _n( '%d עוגייה', '%d עוגיות', count( $list ), 'at-woo-gf-integration' ), count( $list ) ) ); ?></summary>
								<div class="at-cc__table-wrap">
									<table class="at-cc__table">
										<thead>
											<tr>
												<th><?php esc_html_e( 'שם', 'at-woo-gf-integration' ); ?></th>
												<th><?php esc_html_e( 'ספק', 'at-woo-gf-integration' ); ?></th>
												<th><?php esc_html_e( 'מטרה', 'at-woo-gf-integration' ); ?></th>
												<th><?php esc_html_e( 'תפוגה', 'at-woo-gf-integration' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $list as $c ) : ?>
												<tr>
													<td><code><?php echo esc_html( $c['name'] ); ?></code></td>
													<td><?php echo esc_html( $c['provider'] ); ?></td>
													<td><?php echo esc_html( $c['purpose'] ); ?></td>
													<td><?php echo esc_html( $c['expiry'] ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							</details>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>

			<div class="at-cc__dialog-foot">
				<button type="button" class="at-cc__btn at-cc__btn--ghost" data-at-cc="reject"><?php esc_html_e( 'דחה הכל', 'at-woo-gf-integration' ); ?></button>
				<button type="button" class="at-cc__btn at-cc__btn--ghost" data-at-cc="save"><?php esc_html_e( 'שמור העדפות', 'at-woo-gf-integration' ); ?></button>
				<button type="button" class="at-cc__btn at-cc__btn--primary" data-at-cc="accept"><?php esc_html_e( 'אשר הכל', 'at-woo-gf-integration' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Re-open / withdraw button (shown after a choice is made) -->
	<button type="button" class="at-cc__reopen" data-at-cc="customize" aria-label="<?php esc_attr_e( 'הגדרות עוגיות', 'at-woo-gf-integration' ); ?>" hidden>
		<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Z"/>
			<circle cx="8.5" cy="12" r="1" fill="currentColor" stroke="none"/>
			<circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/>
			<circle cx="15.5" cy="13" r="1" fill="currentColor" stroke="none"/>
		</svg>
		<span class="screen-reader-text"><?php esc_html_e( 'הגדרות עוגיות', 'at-woo-gf-integration' ); ?></span>
	</button>
</div>
