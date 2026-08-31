<?php
/**
 * Public cookie list table — output of [haruv_cookie_list].
 *
 * @var array $ordered      Cookie definitions bucketed by category key, in display order.
 * @var array $categories   Category definitions from the consent settings.
 * @var bool  $has_provider Whether any cookie carries provider data.
 *
 * Provided by AT_Woo_GF_Cookie_Consent::render_cookie_list().
 *
 * @package AT_Woo_GF_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $ordered ) ) :
	?>
	<div class="at-cl" dir="rtl">
		<p class="at-cl__empty"><?php esc_html_e( 'לא הוגדרו עוגיות להצגה.', 'at-woo-gf-integration' ); ?></p>
	</div>
	<?php
	return;
endif;

$at_cl_caption = __( 'רשימת העוגיות שבשימוש באתר, לפי קטגוריה', 'at-woo-gf-integration' );
?>
<div class="at-cl" dir="rtl">
	<div class="at-cl__table-wrap" tabindex="0" role="region" aria-label="<?php echo esc_attr( $at_cl_caption ); ?>">
		<table class="at-cl__table">
			<caption class="at-cl__caption"><?php echo esc_html( $at_cl_caption ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'שם העוגייה', 'at-woo-gf-integration' ); ?></th>
					<th scope="col"><?php esc_html_e( 'קטגוריה', 'at-woo-gf-integration' ); ?></th>
					<?php if ( $has_provider ) : ?>
						<th scope="col"><?php esc_html_e( 'ספק', 'at-woo-gf-integration' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'מטרה', 'at-woo-gf-integration' ); ?></th>
					<th scope="col"><?php esc_html_e( 'תוקף', 'at-woo-gf-integration' ); ?></th>
				</tr>
			</thead>
			<?php
			foreach ( $ordered as $at_cl_key => $at_cl_list ) :
				// An unknown key means the category was removed from the settings
				// after cookies were assigned to it — show the raw key rather
				// than silently dropping the disclosure.
				$at_cl_label = isset( $categories[ $at_cl_key ]['label'] )
					? $categories[ $at_cl_key ]['label']
					: $at_cl_key;
				?>
				<tbody class="at-cl__group">
					<?php foreach ( $at_cl_list as $at_cl_cookie ) : ?>
						<tr>
							<th scope="row" class="at-cl__name">
								<code><?php echo esc_html( $at_cl_cookie['name'] ); ?></code>
							</th>
							<td>
								<span class="at-cl__badge at-cl__badge--<?php echo esc_attr( sanitize_html_class( $at_cl_key ) ); ?>">
									<?php echo esc_html( $at_cl_label ); ?>
								</span>
							</td>
							<?php if ( $has_provider ) : ?>
								<td><?php echo esc_html( $at_cl_cookie['provider'] ); ?></td>
							<?php endif; ?>
							<td><?php echo esc_html( $at_cl_cookie['purpose'] ); ?></td>
							<td class="at-cl__expiry"><?php echo esc_html( $at_cl_cookie['expiry'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>
	</div>
</div>
