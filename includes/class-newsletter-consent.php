<?php
/**
 * Newsletter consent — single source of truth.
 *
 * Records every newsletter opt-in / opt-out / decline across three surfaces
 * (Gravity Forms newsletter form, WooCommerce checkout, personal area) into one
 * audit-trail table, and syncs the contact to ActiveTrail (MyMarketing).
 *
 * Design goals:
 *  - One helper (`log_consent()`) that every surface calls, so consent logic is
 *    never forked.
 *  - No secret in the codebase: the ActiveTrail auth token is read from the
 *    `HARUV_ACTIVETRAIL_TOKEN` constant (defined per-environment in wp-config.php),
 *    with an option + filter fallback.
 *  - Non-blocking: an API failure or timeout is logged (WP_DEBUG-gated) and never
 *    breaks the user's form submission / checkout / preference toggle.
 *
 * @package ATWooGFIntegration
 * @since   2.14.0
 */

defined( 'ABSPATH' ) || exit;

class AT_Newsletter_Consent {

	/** @var AT_Newsletter_Consent|null */
	private static $instance = null;

	/**
	 * Audit-trail schema version. Bump to trigger dbDelta on the next request.
	 */
	const DB_VERSION = '1.0.0';

	/** Option key that stores the installed schema version. */
	const DB_VERSION_OPTION = 'at_newsletter_consent_db_version';

	/**
	 * Consent-text version stamped on every logged row when no per-field
	 * Gravity Forms consent version is available.
	 */
	const CONSENT_VERSION = '1.0';

	/**
	 * Gravity Forms newsletter form id (page "/הצטרפות-לניוזלטר/").
	 * Filterable via `haruv_newsletter_form_id`.
	 */
	const NEWSLETTER_FORM_ID = 21;

	/**
	 * Default ActiveTrail group / list id. Mirrors the legacy site default.
	 * Override with the `HARUV_ACTIVETRAIL_GROUP` constant, the
	 * `at_activetrail_group` option, or the `haruv_activetrail_group` filter.
	 */
	const DEFAULT_GROUP = 106435;

	/** ActiveTrail (MyMarketing) contact import endpoint. */
	const ENDPOINT = 'https://webapi.mymarketing.co.il/api/contacts/Import';

	/** Consent actions. */
	const ACTION_OPT_IN   = 'opt_in';
	const ACTION_OPT_OUT  = 'opt_out';
	const ACTION_DECLINED = 'declined';

	/**
	 * @return AT_Newsletter_Consent
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Ensure the audit-trail table exists (version-checked, cheap).
		add_action( 'init', array( $this, 'maybe_create_table' ), 1 );

		// Surface 1 — Gravity Forms newsletter form.
		add_action( 'gform_after_submission', array( $this, 'handle_newsletter_form' ), 20, 2 );

		// Surface 2 — WooCommerce checkout opt-in.
		// Block checkout (wp:woocommerce/checkout) — register an additional field
		// and process it when the Store API order is created.
		add_action( 'woocommerce_init', array( $this, 'register_checkout_field' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'process_block_checkout_optin' ), 20 );
		// Classic (shortcode) checkout fallback.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_optin' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_checkout_optin' ), 10, 2 );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Storage — audit-trail table + current-state mirror
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return string Fully-qualified audit-trail table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'at_newsletter_consent_log';
	}

	/**
	 * Create / upgrade the audit-trail table when the schema version changes.
	 */
	public function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Note: dbDelta requires two spaces after PRIMARY KEY and specific formatting.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(191) NOT NULL DEFAULT '',
			action VARCHAR(20) NOT NULL DEFAULT '',
			source VARCHAR(40) NOT NULL DEFAULT '',
			consent_text TEXT NULL,
			consent_version VARCHAR(20) NOT NULL DEFAULT '',
			ip VARCHAR(100) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY email (email),
			KEY user_id (user_id),
			KEY action (action),
			KEY source (source)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Record a consent event — the single entry point every surface calls.
	 *
	 * @param string|int $identity Email address or numeric user id.
	 * @param string     $action   One of ACTION_OPT_IN / ACTION_OPT_OUT / ACTION_DECLINED.
	 * @param string     $source   Origin key: 'newsletter_form' | 'checkout' | 'user_area'.
	 * @param array      $args {
	 *     @type string $consent_text    The exact consent wording shown to the user.
	 *     @type string $consent_version Version of that wording. Defaults to CONSENT_VERSION.
	 *     @type int    $user_id         Explicit user id (optional).
	 *     @type string $email           Explicit email (optional).
	 * }
	 * @return int|false Inserted row id, or false on failure.
	 */
	public static function log_consent( $identity, $action, $source, $args = array() ) {
		global $wpdb;

		$user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		$email   = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';

		// Resolve identity → email / user_id.
		if ( is_numeric( $identity ) && ! $user_id ) {
			$user_id = absint( $identity );
		} elseif ( is_string( $identity ) && '' === $email && is_email( $identity ) ) {
			$email = sanitize_email( $identity );
		}
		if ( $user_id && '' === $email ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$email = $user->user_email;
			}
		}
		if ( ! $user_id && $email ) {
			$maybe = get_user_by( 'email', $email );
			if ( $maybe ) {
				$user_id = $maybe->ID;
			}
		}

		$allowed_actions = array( self::ACTION_OPT_IN, self::ACTION_OPT_OUT, self::ACTION_DECLINED );
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return false;
		}

		$row = array(
			'user_id'         => $user_id,
			'email'           => $email,
			'action'          => $action,
			'source'          => sanitize_key( $source ),
			'consent_text'    => isset( $args['consent_text'] ) ? wp_kses_post( $args['consent_text'] ) : '',
			'consent_version' => isset( $args['consent_version'] ) ? sanitize_text_field( $args['consent_version'] ) : self::CONSENT_VERSION,
			'ip'              => self::get_ip(),
			'created_at'      => current_time( 'mysql' ),
		);

		$inserted = $wpdb->insert( self::table_name(), $row );

		// Mirror the current state onto user meta so existing UI keeps working.
		if ( $user_id ) {
			if ( self::ACTION_OPT_IN === $action ) {
				update_user_meta( $user_id, 'newsletter_subscription', '1' );
			} elseif ( self::ACTION_OPT_OUT === $action ) {
				update_user_meta( $user_id, 'newsletter_subscription', '0' );
			}
			// ACTION_DECLINED does not change an existing subscription state.
		}

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * @return string Client IP, sanitized. Mirrors class-cookie-consent.php.
	 */
	private static function get_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// ActiveTrail (MyMarketing) client
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Resolve the ActiveTrail auth token WITHOUT hard-coding it.
	 *
	 * Priority: HARUV_ACTIVETRAIL_TOKEN constant → at_activetrail_token option →
	 * haruv_activetrail_token filter.
	 *
	 * @return string
	 */
	private function get_token() {
		$token = '';
		if ( defined( 'HARUV_ACTIVETRAIL_TOKEN' ) && HARUV_ACTIVETRAIL_TOKEN ) {
			$token = (string) HARUV_ACTIVETRAIL_TOKEN;
		} else {
			$token = (string) get_option( 'at_activetrail_token', '' );
		}
		return (string) apply_filters( 'haruv_activetrail_token', $token );
	}

	/**
	 * Resolve the ActiveTrail group / list id.
	 *
	 * @param string $lang Optional Polylang language slug (routing hook only).
	 * @return int
	 */
	private function get_group( $lang = '' ) {
		$group = self::DEFAULT_GROUP;
		if ( defined( 'HARUV_ACTIVETRAIL_GROUP' ) && HARUV_ACTIVETRAIL_GROUP ) {
			$group = (int) HARUV_ACTIVETRAIL_GROUP;
		} else {
			$opt = get_option( 'at_activetrail_group', '' );
			if ( '' !== $opt ) {
				$group = (int) $opt;
			}
		}
		// Language routing is intentionally left to integrators — no invented list ids.
		return (int) apply_filters( 'haruv_activetrail_group', $group, $lang );
	}

	/**
	 * Send a contact to ActiveTrail. Never throws; never blocks the caller.
	 *
	 * @param array  $contact  first_name, last_name, email, phone, occupation, area, workplace.
	 * @param string $action   ACTION_OPT_IN → subscribe; ACTION_OPT_OUT → mark do-not-mail.
	 * @return bool True on an accepted request, false otherwise.
	 */
	public function activetrail_sync( $contact, $action = self::ACTION_OPT_IN ) {
		$email = isset( $contact['email'] ) ? sanitize_email( $contact['email'] ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			return false;
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			// No token configured — record the misconfiguration, don't attempt a call.
			$this->log_debug( 'ActiveTrail token is not configured (define HARUV_ACTIVETRAIL_TOKEN).' );
			return false;
		}

		$is_opt_out = ( self::ACTION_OPT_OUT === $action );

		$body = array(
			'group'    => $this->get_group( $this->current_lang() ),
			'contacts' => array(
				array(
					'email'         => $email,
					'first_name'    => isset( $contact['first_name'] ) ? (string) $contact['first_name'] : '',
					'last_name'     => isset( $contact['last_name'] ) ? (string) $contact['last_name'] : '',
					'phone1'        => isset( $contact['phone'] ) ? (string) $contact['phone'] : '',
					'ext1'          => isset( $contact['occupation'] ) ? (string) $contact['occupation'] : '',
					'ext2'          => isset( $contact['area'] ) ? (string) $contact['area'] : '',
					'ext3'          => isset( $contact['workplace'] ) ? (string) $contact['workplace'] : '',
					// Opt-out is expressed on the same Import endpoint by flagging
					// the contact as do-not-mail (MyMarketing has no separate public
					// unsubscribe endpoint documented for this integration).
					'is_do_not_mail' => $is_opt_out,
					'is_deleted'     => false,
				),
			),
		);

		/**
		 * Filter the outgoing ActiveTrail payload (e.g. add custom ext fields).
		 *
		 * @param array  $body
		 * @param array  $contact
		 * @param string $action
		 */
		$body = apply_filters( 'haruv_activetrail_payload', $body, $contact, $action );

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout'  => 15,
			'blocking' => true,
			'headers'  => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $token,
			),
			'body'     => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'ActiveTrail request failed: ' . $response->get_error_message() . ' | action=' . $action );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->log_debug( 'ActiveTrail returned HTTP ' . $code . ' | action=' . $action );
			return false;
		}

		return true;
	}

	/**
	 * @return string Current Polylang / WPML language slug, or ''.
	 */
	private function current_lang() {
		if ( function_exists( 'pll_current_language' ) ) {
			return (string) pll_current_language( 'slug' );
		}
		$wpml = apply_filters( 'wpml_current_language', null );
		return $wpml ? (string) $wpml : '';
	}

	/**
	 * Log to the PHP error log only when WP_DEBUG is on. Never logs PII
	 * (no email / phone), only the operational outcome.
	 *
	 * @param string $message
	 */
	private function log_debug( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AT Newsletter Consent] ' . $message );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Surface 1 — Gravity Forms newsletter form (form 21)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * On newsletter-form submission: log opt-in + push to ActiveTrail.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 */
	public function handle_newsletter_form( $entry, $form ) {
		$target_id = (int) apply_filters( 'haruv_newsletter_form_id', self::NEWSLETTER_FORM_ID );
		if ( (int) rgar( $form, 'id' ) !== $target_id ) {
			return;
		}

		// Name field (id 1): sub-inputs 1.3 = first (ראשון), 1.6 = last (אחרון).
		$first_name = trim( (string) rgar( $entry, '1.3' ) );
		$last_name  = trim( (string) rgar( $entry, '1.6' ) );

		// Two email fields exist on the form (ids 4 and 5 — a known duplicate).
		// Field 4 is the canonical "אימייל"; fall back to 5 only if 4 is empty.
		$email = sanitize_email( trim( (string) rgar( $entry, '4' ) ) );
		if ( ! $email || ! is_email( $email ) ) {
			$email = sanitize_email( trim( (string) rgar( $entry, '5' ) ) );
		}
		if ( ! $email || ! is_email( $email ) ) {
			return; // Nothing actionable without a valid email.
		}

		$phone      = trim( (string) rgar( $entry, '3' ) );
		$occupation = trim( (string) rgar( $entry, '6' ) ); // Values are already human-readable Hebrew.
		$area       = trim( (string) rgar( $entry, '7' ) );

		// Consent wording + version: reuse a Gravity Forms consent field if the
		// form has one; otherwise fall back to a defined default.
		$consent = $this->resolve_gf_consent( $entry, $form );

		self::log_consent( $email, self::ACTION_OPT_IN, 'newsletter_form', array(
			'consent_text'    => $consent['text'],
			'consent_version' => $consent['version'],
			'email'           => $email,
		) );

		$this->activetrail_sync( array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => $email,
			'phone'      => $phone,
			'occupation' => $occupation,
			'area'       => $area,
		), self::ACTION_OPT_IN );
	}

	/**
	 * Pull consent text/version from a GF consent field if present.
	 *
	 * @param array $entry
	 * @param array $form
	 * @return array{text:string,version:string}
	 */
	private function resolve_gf_consent( $entry, $form ) {
		$default = array(
			'text'    => __( 'אני מאשר/ת קבלת דיוור ועדכונים במייל ממכון חרוב.', 'at-woo-gf-integration' ),
			'version' => self::CONSENT_VERSION,
		);

		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $default;
		}

		foreach ( $form['fields'] as $field ) {
			$type = is_object( $field ) ? $field->get_input_type() : rgar( $field, 'type' );
			if ( 'consent' !== $type ) {
				continue;
			}
			$field_id = is_object( $field ) ? $field->id : rgar( $field, 'id' );
			// GF stores the consent description in "{id}.2" and the version in "{id}.3".
			$text    = trim( (string) rgar( $entry, $field_id . '.2' ) );
			$version = trim( (string) rgar( $entry, $field_id . '.3' ) );
			if ( '' !== $text ) {
				return array(
					'text'    => $text,
					'version' => '' !== $version ? $version : self::CONSENT_VERSION,
				);
			}
		}

		return $default;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Surface 2 — WooCommerce checkout opt-in
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Consent wording shown at checkout (with a privacy-policy link when set).
	 *
	 * @return string
	 */
	private function checkout_consent_text() {
		$text = __( 'אני מאשר/ת קבלת דיוור ועדכונים במייל ממכון חרוב', 'at-woo-gf-integration' );

		$privacy_url = apply_filters( 'haruv_newsletter_privacy_url', function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '' );
		if ( $privacy_url ) {
			$text .= ' (' . sprintf(
				/* translators: %s: privacy policy URL */
				__( 'בהתאם ל<a href="%s" target="_blank" rel="noopener">מדיניות הפרטיות</a>', 'at-woo-gf-integration' ),
				esc_url( $privacy_url )
			) . ')';
		}
		return $text;
	}

	/**
	 * Block checkout: register the optional opt-in checkbox as a WooCommerce
	 * additional checkout field (WC 8.9+). Not required for purchase.
	 */
	public function register_checkout_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return; // Older WooCommerce — classic checkout hooks handle it.
		}
		woocommerce_register_additional_checkout_field( array(
			'id'       => 'haruv/newsletter_optin',
			'label'    => wp_strip_all_tags( $this->checkout_consent_text() ),
			'location' => 'contact',
			'type'     => 'checkbox',
			'required' => false,
		) );
	}

	/**
	 * Block checkout: process the opt-in once the Store API order is created.
	 *
	 * @param WC_Order $order
	 */
	public function process_block_checkout_optin( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$opted_in = $this->read_block_optin( $order );
		$this->process_checkout_consent( $order, $opted_in );
	}

	/**
	 * Read the additional-field value from a block-checkout order.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function read_block_optin( $order ) {
		// Additional checkout fields are stored on the order as _wc_other/{namespace}/{key}.
		$val = $order->get_meta( '_wc_other/haruv/newsletter_optin' );

		// Fallback to the canonical CheckoutFields service if the meta is absent.
		if ( '' === $val && class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			try {
				$fields = \Automattic\WooCommerce\Blocks\Package::container()->get(
					\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class
				);
				if ( $fields && method_exists( $fields, 'get_field_from_object' ) ) {
					$val = $fields->get_field_from_object( 'haruv/newsletter_optin', $order, 'other' );
				}
			} catch ( \Throwable $e ) {
				$this->log_debug( 'CheckoutFields read failed: ' . $e->getMessage() );
			}
		}

		return filter_var( $val, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Render the optional opt-in checkbox on the (classic) checkout.
	 * Not required for purchase.
	 */
	public function render_checkout_optin() {
		if ( ! function_exists( 'woocommerce_form_field' ) ) {
			return;
		}
		woocommerce_form_field( 'haruv_newsletter_optin', array(
			'type'     => 'checkbox',
			'class'    => array( 'haruv-newsletter-optin form-row-wide' ),
			'label'    => $this->checkout_consent_text(),
			'required' => false,
		), '' );
	}

	/**
	 * Classic checkout: capture the opt-in choice when the order is created.
	 * WooCommerce validates its own checkout nonce before this fires.
	 *
	 * @param WC_Order $order
	 * @param array    $data
	 */
	public function capture_checkout_optin( $order, $data ) {
		$opted_in = ! empty( $_POST['haruv_newsletter_optin'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC checkout nonce already validated.
		$this->process_checkout_consent( $order, $opted_in );
	}

	/**
	 * Shared checkout consent processor (block + classic).
	 * Checked → opt-in + ActiveTrail subscribe. Unchecked → recorded as declined
	 * (does NOT unsubscribe an existing subscriber). De-duplicated per order.
	 *
	 * @param WC_Order $order
	 * @param bool     $opted_in
	 */
	private function process_checkout_consent( $order, $opted_in ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Avoid double processing when both the block and classic paths fire.
		if ( 'done' === $order->get_meta( '_haruv_newsletter_processed' ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		$order->update_meta_data( '_haruv_newsletter_optin', $opted_in ? 'yes' : 'no' );
		$order->update_meta_data( '_haruv_newsletter_processed', 'done' );
		$order->save();

		$consent_text = wp_strip_all_tags( $this->checkout_consent_text() );

		if ( $opted_in ) {
			self::log_consent( $email, self::ACTION_OPT_IN, 'checkout', array(
				'consent_text' => $consent_text,
				'email'        => $email,
			) );
			$this->activetrail_sync( array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $email,
				'phone'      => $order->get_billing_phone(),
				'workplace'  => $order->get_billing_company(),
			), self::ACTION_OPT_IN );
		} else {
			self::log_consent( $email, self::ACTION_DECLINED, 'checkout', array(
				'consent_text' => $consent_text,
				'email'        => $email,
			) );
		}
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Global wrappers — let the theme (personal area) reuse the same source of truth
// without coupling to the class name. Guarded so they no-op if the plugin is
// deactivated.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'haruv_log_newsletter_consent' ) ) {
	/**
	 * Record a newsletter consent event.
	 *
	 * @param string|int $identity Email or user id.
	 * @param string     $action   'opt_in' | 'opt_out' | 'declined'.
	 * @param string     $source   'newsletter_form' | 'checkout' | 'user_area'.
	 * @param array      $args     See AT_Newsletter_Consent::log_consent().
	 * @return int|false
	 */
	function haruv_log_newsletter_consent( $identity, $action, $source, $args = array() ) {
		if ( ! class_exists( 'AT_Newsletter_Consent' ) ) {
			return false;
		}
		return AT_Newsletter_Consent::log_consent( $identity, $action, $source, $args );
	}
}

if ( ! function_exists( 'haruv_newsletter_activetrail_sync' ) ) {
	/**
	 * Push a contact to ActiveTrail (subscribe on opt_in, do-not-mail on opt_out).
	 *
	 * @param array  $contact first_name, last_name, email, phone, occupation, area, workplace.
	 * @param string $action  'opt_in' | 'opt_out'.
	 * @return bool
	 */
	function haruv_newsletter_activetrail_sync( $contact, $action = 'opt_in' ) {
		if ( ! class_exists( 'AT_Newsletter_Consent' ) ) {
			return false;
		}
		return AT_Newsletter_Consent::get_instance()->activetrail_sync( $contact, $action );
	}
}
