<?php
/**
 * Event Waitlist storage and helpers.
 *
 * @package WooGFIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta key for waitlist entries.
 */
define( 'WOO_GF_EVENT_WAITLIST_META_KEY', '_event_waitlist_entries' );

/**
 * Whether waitlist is enabled for a product.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function woo_gf_is_waitlist_enabled( $product_id ) {
	$enabled = function_exists( 'get_field' )
		? get_field( 'enable_waitlist', $product_id )
		: get_post_meta( $product_id, 'enable_waitlist', true );

	return filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );
}

/**
 * Get waitlist entries.
 *
 * @param int $product_id Product ID.
 * @return array<int,array<string,mixed>>
 */
function woo_gf_get_waitlist_entries( $product_id ) {
	$entries = get_post_meta( absint( $product_id ), WOO_GF_EVENT_WAITLIST_META_KEY, true );
	return is_array( $entries ) ? $entries : array();
}

/**
 * Get waitlist count.
 *
 * @param int $product_id Product ID.
 * @return int
 */
function woo_gf_get_waitlist_count( $product_id ) {
	return count( woo_gf_get_waitlist_entries( $product_id ) );
}

/**
 * Get registration count for an event product.
 *
 * @param int $product_id Product ID.
 * @return int
 */
function woo_gf_get_registration_count( $product_id ) {
	$product_id = absint( $product_id );
	$form_id    = get_post_meta( $product_id, '_woo_gf_form_id', true );

	if ( ! $form_id || ! class_exists( 'GFAPI' ) ) {
		return 0;
	}

	$cache_key = 'woo_gf_reg_count_' . $product_id . '_' . $form_id;
	$count     = get_transient( $cache_key );

	if ( false !== $count ) {
		return (int) $count;
	}

	$search_criteria = array(
		'status'        => 'active',
		'field_filters' => array(
			array(
				'key'   => 'woo_gf_product_id',
				'value' => $product_id,
			),
		),
	);

	$count = (int) GFAPI::count_entries( $form_id, $search_criteria );
	set_transient( $cache_key, $count, 2 * MINUTE_IN_SECONDS );

	return $count;
}

/**
 * Add waitlist entry.
 *
 * @param int   $product_id Product ID.
 * @param array $data       Entry data.
 * @return true|WP_Error
 */
function woo_gf_add_waitlist_entry( $product_id, $data ) {
	$product_id = absint( $product_id );
	$name       = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
	$email      = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
	$phone      = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '';

	if ( ! $product_id || ! is_email( $email ) || empty( $name ) ) {
		return new WP_Error( 'invalid_data', __( 'נא למלא שם וכתובת דוא"ל תקינה.', 'at-woo-gf-integration' ) );
	}

	if ( ! woo_gf_is_waitlist_enabled( $product_id ) ) {
		return new WP_Error( 'waitlist_disabled', __( 'רשימת המתנה אינה פעילה לאירוע זה.', 'at-woo-gf-integration' ) );
	}

	$max_attendees = (int) get_post_meta( $product_id, '_max_attendees', true );
	if ( $max_attendees > 0 && woo_gf_get_registration_count( $product_id ) < $max_attendees ) {
		return new WP_Error( 'not_full', __( 'האירוע עדיין פתוח להרשמה.', 'at-woo-gf-integration' ) );
	}

	foreach ( woo_gf_get_waitlist_entries( $product_id ) as $entry ) {
		if ( isset( $entry['email'] ) && strtolower( $entry['email'] ) === strtolower( $email ) ) {
			return new WP_Error( 'duplicate', __( 'כתובת הדוא"ל כבר רשומה לרשימת ההמתנה.', 'at-woo-gf-integration' ) );
		}
	}

	$entries   = woo_gf_get_waitlist_entries( $product_id );
	$entries[] = array(
		'id'         => wp_generate_uuid4(),
		'name'       => $name,
		'email'      => $email,
		'phone'      => $phone,
		'created_at' => current_time( 'mysql' ),
	);

	update_post_meta( $product_id, WOO_GF_EVENT_WAITLIST_META_KEY, $entries );

	return true;
}

/**
 * Admin waitlist AJAX + dashboard integration.
 */
class WooGF_Event_Waitlist {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_woo_gf_get_event_waitlist', array( $this, 'ajax_get_event_waitlist' ) );
		add_action( 'wp_ajax_woo_gf_export_waitlist', array( $this, 'ajax_export_waitlist' ) );
	}

	/**
	 * AJAX: return waitlist table HTML for sidepeek.
	 */
	public function ajax_get_event_waitlist() {
		check_ajax_referer( 'woo_gf_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'at-woo-gf-integration' ) );
		}

		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$event_title  = isset( $_POST['event_title'] ) ? sanitize_text_field( wp_unslash( $_POST['event_title'] ) ) : '';
		$entries      = woo_gf_get_waitlist_entries( $product_id );

		ob_start();
		?>
		<div class="woo-gf-registrations-sidepeek">
			<div class="woo-gf-registrations-header">
				<h4><?php echo esc_html( $event_title ); ?></h4>
				<p><?php esc_html_e( 'רשימת המתנה', 'at-woo-gf-integration' ); ?> (<?php echo esc_html( count( $entries ) ); ?>)</p>
			</div>
			<?php if ( empty( $entries ) ) : ?>
				<div class="woo-gf-no-registrations">
					<div class="woo-gf-no-registrations-icon">⏳</div>
					<p><?php esc_html_e( 'אין נרשמים לרשימת ההמתנה', 'at-woo-gf-integration' ); ?></p>
				</div>
			<?php else : ?>
				<div class="woo-gf-registrations-table">
					<table>
						<thead>
							<tr>
								<th><?php esc_html_e( 'תאריך', 'at-woo-gf-integration' ); ?></th>
								<th><?php esc_html_e( 'שם', 'at-woo-gf-integration' ); ?></th>
								<th><?php esc_html_e( 'דוא"ל', 'at-woo-gf-integration' ); ?></th>
								<th><?php esc_html_e( 'טלפון', 'at-woo-gf-integration' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<tr class="woo-gf-registration-row">
									<td class="woo-gf-registration-date">
										<?php
										echo esc_html(
											! empty( $entry['created_at'] )
												? date_i18n( 'j.n.Y H:i', strtotime( $entry['created_at'] ) )
												: '—'
										);
										?>
									</td>
									<td class="woo-gf-registration-name"><?php echo esc_html( $entry['name'] ?? '' ); ?></td>
									<td class="woo-gf-registration-email" dir="ltr"><?php echo esc_html( $entry['email'] ?? '' ); ?></td>
									<td class="woo-gf-registration-phone" dir="ltr"><?php echo esc_html( $entry['phone'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * AJAX: export waitlist as CSV.
	 */
	public function ajax_export_waitlist() {
		check_ajax_referer( 'woo_gf_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'at-woo-gf-integration' ) );
		}

		$product_id  = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$event_title = isset( $_GET['event_title'] ) ? sanitize_text_field( wp_unslash( $_GET['event_title'] ) ) : 'event';
		$entries     = woo_gf_get_waitlist_entries( $product_id );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="waitlist-' . sanitize_file_name( $event_title ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		fputcsv( $output, array( 'Date', 'Name', 'Email', 'Phone' ) );

		foreach ( $entries as $entry ) {
			fputcsv(
				$output,
				array(
					$entry['created_at'] ?? '',
					$entry['name'] ?? '',
					$entry['email'] ?? '',
					$entry['phone'] ?? '',
				)
			);
		}

		fclose( $output );
		exit;
	}
}

new WooGF_Event_Waitlist();
