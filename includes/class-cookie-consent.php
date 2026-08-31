<?php
/**
 * GDPR Cookie Consent
 *
 * A self-contained, GDPR-compliant cookie consent manager:
 * - Admin settings page: categories + per-cookie registry, banner text/links.
 * - Frontend banner (wp_footer) with Accept all / Reject all / Customize.
 * - Preferences modal (granular per-category toggles) — re-openable to withdraw.
 * - Automatic scanner: reports cookies not in the registry so the admin can
 *   classify them.
 * - `[haruv_cookie_list]` shortcode: renders the same registry as a public
 *   table (for the privacy / cookie-policy page).
 *
 * No non-necessary scripts are fired by this module; other scripts should gate
 * themselves on the `at-cookie-consent` DOM event / window.atCookieConsent.choice.
 *
 * @package AT_Woo_GF_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AT_Woo_GF_Cookie_Consent {

	/** @var AT_Woo_GF_Cookie_Consent|null */
	private static $instance = null;

	const OPTION      = 'at_woo_gf_cookie_settings';
	const DETECTED    = 'at_woo_gf_cookie_detected';
	const COOKIE_NAME = 'at_cookie_consent';
	const NONCE       = 'at_woo_gf_integration_nonce';
	const SHORTCODE   = 'haruv_cookie_list';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// Frontend.
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_banner' ) );

		// Public cookie-list table. Registered unconditionally so the shortcode
		// still resolves when the banner itself is switched off — a cookie
		// policy page must keep working either way.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_list_assets' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_cookie_list' ) );

		// AJAX (visitors are logged-out → need nopriv too).
		add_action( 'wp_ajax_at_cookie_consent_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_nopriv_at_cookie_consent_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_at_cookie_consent_report', array( $this, 'ajax_report' ) );
		add_action( 'wp_ajax_nopriv_at_cookie_consent_report', array( $this, 'ajax_report' ) );
	}

	/* ── Settings ──────────────────────────────────────────────────────── */

	/**
	 * Default settings, merged over saved values.
	 *
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'enabled'     => 1,
			'banner_text' => __( 'אנחנו משתמשים בעוגיות כדי לשפר את חוויית הגלישה שלך. חלקן חיוניות לתפקוד האתר, ואחרות עוזרות לנו להבין כיצד משתמשים באתר. באפשרותך לבחור אילו עוגיות לאשר.', 'at-woo-gf-integration' ),
			'privacy_url' => '',
			'position'    => 'bottom',
			'categories'  => $this->default_categories(),
			'cookies'     => array(),
		);
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings               = array_merge( $defaults, $saved );
		$settings['categories'] = ( isset( $saved['categories'] ) && is_array( $saved['categories'] ) ) ? $saved['categories'] : $defaults['categories'];
		// Seed a sensible default registry until the admin saves their own, so
		// the preferences modal always lists real cookies with details.
		if ( isset( $saved['cookies'] ) && is_array( $saved['cookies'] ) && ! empty( $saved['cookies'] ) ) {
			$settings['cookies'] = $saved['cookies'];
		} else {
			$settings['cookies'] = $this->default_cookies();
		}
		return $settings;
	}

	/**
	 * A starter cookie registry covering the cookies this WordPress/WooCommerce
	 * site commonly sets. Used until the admin defines their own list.
	 *
	 * @return array
	 */
	public function default_cookies() {
		return array(
			array(
				'name'     => 'at_cookie_consent',
				'category' => 'necessary',
				'provider' => __( 'אתר זה', 'at-woo-gf-integration' ),
				'purpose'  => __( 'שומרת את בחירת הסכמת העוגיות שלך.', 'at-woo-gf-integration' ),
				'expiry'   => __( '6 חודשים', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => 'PHPSESSID',
				'category' => 'necessary',
				'provider' => __( 'אתר זה', 'at-woo-gf-integration' ),
				'purpose'  => __( 'מזהה הפעלה (session) לשמירת מצב בזמן הגלישה.', 'at-woo-gf-integration' ),
				'expiry'   => __( 'עד סגירת הדפדפן', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => 'woocommerce_cart_hash',
				'category' => 'necessary',
				'provider' => 'WooCommerce',
				'purpose'  => __( 'שומרת את תוכן עגלת הקניות בין עמודים.', 'at-woo-gf-integration' ),
				'expiry'   => __( 'סשן', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => 'woocommerce_items_in_cart',
				'category' => 'necessary',
				'provider' => 'WooCommerce',
				'purpose'  => __( 'מציינת אם יש פריטים בעגלה.', 'at-woo-gf-integration' ),
				'expiry'   => __( 'סשן', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => 'wp_woocommerce_session_',
				'category' => 'necessary',
				'purpose'  => __( 'מקשרת בין המבקר לנתוני העגלה/ההזמנה שלו.', 'at-woo-gf-integration' ),
				'provider' => 'WooCommerce',
				'expiry'   => __( 'יומיים', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => 'pll_language',
				'category' => 'functional',
				'provider' => 'Polylang',
				'purpose'  => __( 'זוכרת את שפת האתר שבחרת.', 'at-woo-gf-integration' ),
				'expiry'   => __( 'שנה', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => '_ga',
				'category' => 'analytics',
				'provider' => 'Google Analytics',
				'purpose'  => __( 'מבחינה בין משתמשים לצורך מדידת שימוש באתר.', 'at-woo-gf-integration' ),
				'expiry'   => __( 'שנתיים', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => '_gid',
				'category' => 'analytics',
				'provider' => 'Google Analytics',
				'purpose'  => __( 'מבחינה בין משתמשים לצורך מדידת שימוש באתר.', 'at-woo-gf-integration' ),
				'expiry'   => __( '24 שעות', 'at-woo-gf-integration' ),
			),
			array(
				'name'     => 'sbjs_current',
				'category' => 'marketing',
				'provider' => 'Sourcebuster',
				'purpose'  => __( 'עוקבת אחר מקור ההגעה לאתר (שיוך שיווקי).', 'at-woo-gf-integration' ),
				'expiry'   => __( '6 חודשים', 'at-woo-gf-integration' ),
			),
		);
	}

	/**
	 * The four fixed GDPR categories. `necessary` is always on / locked.
	 *
	 * @return array
	 */
	public function default_categories() {
		return array(
			'necessary'  => array(
				'label'       => __( 'הכרחיות', 'at-woo-gf-integration' ),
				'description' => __( 'עוגיות חיוניות לתפקוד האתר. תמיד פעילות.', 'at-woo-gf-integration' ),
				'locked'      => true,
			),
			'functional' => array(
				'label'       => __( 'תפקודיות', 'at-woo-gf-integration' ),
				'description' => __( 'מאפשרות פונקציונליות מתקדמת והעדפות אישיות.', 'at-woo-gf-integration' ),
				'locked'      => false,
			),
			'analytics'  => array(
				'label'       => __( 'אנליטיקה', 'at-woo-gf-integration' ),
				'description' => __( 'עוזרות לנו להבין כיצד המבקרים משתמשים באתר.', 'at-woo-gf-integration' ),
				'locked'      => false,
			),
			'marketing'  => array(
				'label'       => __( 'שיווק', 'at-woo-gf-integration' ),
				'description' => __( 'משמשות להצגת פרסום מותאם אישית.', 'at-woo-gf-integration' ),
				'locked'      => false,
			),
		);
	}

	public function register_settings() {
		register_setting(
			'at_woo_gf_cookie_group',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Sanitize the whole settings blob before save.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$out                = array();
		$out['enabled']     = ! empty( $input['enabled'] ) ? 1 : 0;
		$out['banner_text'] = isset( $input['banner_text'] ) ? wp_kses_post( $input['banner_text'] ) : '';
		$out['privacy_url'] = isset( $input['privacy_url'] ) ? esc_url_raw( $input['privacy_url'] ) : '';
		$out['position']    = ( isset( $input['position'] ) && 'top' === $input['position'] ) ? 'top' : 'bottom';

		// Categories: keep the fixed set, only descriptions/labels editable.
		$out['categories'] = $this->default_categories();
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			foreach ( $out['categories'] as $key => &$cat ) {
				if ( isset( $input['categories'][ $key ]['label'] ) ) {
					$cat['label'] = sanitize_text_field( $input['categories'][ $key ]['label'] );
				}
				if ( isset( $input['categories'][ $key ]['description'] ) ) {
					$cat['description'] = sanitize_textarea_field( $input['categories'][ $key ]['description'] );
				}
			}
			unset( $cat );
		}

		// Cookies registry.
		$out['cookies'] = array();
		if ( isset( $input['cookies'] ) && is_array( $input['cookies'] ) ) {
			$valid_cats = array_keys( $out['categories'] );
			foreach ( $input['cookies'] as $cookie ) {
				$name = isset( $cookie['name'] ) ? sanitize_text_field( $cookie['name'] ) : '';
				if ( '' === $name ) {
					continue;
				}
				$cat = isset( $cookie['category'] ) ? sanitize_key( $cookie['category'] ) : 'necessary';
				if ( ! in_array( $cat, $valid_cats, true ) ) {
					$cat = 'necessary';
				}
				$out['cookies'][] = array(
					'name'     => $name,
					'category' => $cat,
					'provider' => isset( $cookie['provider'] ) ? sanitize_text_field( $cookie['provider'] ) : '',
					'purpose'  => isset( $cookie['purpose'] ) ? sanitize_text_field( $cookie['purpose'] ) : '',
					'expiry'   => isset( $cookie['expiry'] ) ? sanitize_text_field( $cookie['expiry'] ) : '',
				);
			}
		}

		return $out;
	}

	/* ── Admin page ────────────────────────────────────────────────────── */

	public function add_admin_menu() {
		add_menu_page(
			__( 'הסכמת עוגיות', 'at-woo-gf-integration' ),
			__( 'הסכמת עוגיות', 'at-woo-gf-integration' ),
			'manage_options',
			'at-cookie-consent',
			array( $this, 'render_admin_page' ),
			'dashicons-privacy',
			58
		);
	}

	public function admin_assets( $hook ) {
		if ( 'toplevel_page_at-cookie-consent' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'at-cookie-consent-admin',
			AT_WOO_GF_INTEGRATION_URL . 'assets/css/cookie-consent-admin.css',
			array(),
			AT_WOO_GF_INTEGRATION_VERSION
		);
		wp_enqueue_script(
			'at-cookie-consent-admin',
			AT_WOO_GF_INTEGRATION_URL . 'assets/js/cookie-consent-admin.js',
			array( 'jquery' ),
			AT_WOO_GF_INTEGRATION_VERSION,
			true
		);
		wp_localize_script(
			'at-cookie-consent-admin',
			'atCookieAdmin',
			array(
				'categories' => $this->get_settings()['categories'],
				'strings'    => array(
					'confirmRemove' => __( 'להסיר עוגייה זו?', 'at-woo-gf-integration' ),
				),
			)
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->get_settings();
		$detected = get_option( self::DETECTED, array() );
		$detected = is_array( $detected ) ? $detected : array();
		// Hide already-registered names from the "detected" list.
		$known = wp_list_pluck( $settings['cookies'], 'name' );
		foreach ( array_keys( $detected ) as $name ) {
			if ( in_array( $name, $known, true ) || self::COOKIE_NAME === $name ) {
				unset( $detected[ $name ] );
			}
		}
		include AT_WOO_GF_INTEGRATION_PATH . 'includes/views/cookie-consent-admin.php';
	}

	/* ── Frontend ──────────────────────────────────────────────────────── */

	public function frontend_assets() {
		if ( is_admin() ) {
			return;
		}
		$settings = $this->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		wp_enqueue_style(
			'at-cookie-consent',
			AT_WOO_GF_INTEGRATION_URL . 'assets/css/cookie-consent.css',
			array(),
			AT_WOO_GF_INTEGRATION_VERSION
		);
		wp_enqueue_script(
			'at-cookie-consent',
			AT_WOO_GF_INTEGRATION_URL . 'assets/js/cookie-consent.js',
			array(),
			AT_WOO_GF_INTEGRATION_VERSION,
			true
		);
		wp_localize_script(
			'at-cookie-consent',
			'atCookieConsent',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'cookieName' => self::COOKIE_NAME,
				'categories' => $settings['categories'],
				'cookies'    => array_values( $settings['cookies'] ),
			)
		);
	}

	public function render_banner() {
		if ( is_admin() ) {
			return;
		}
		$settings = $this->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		include AT_WOO_GF_INTEGRATION_PATH . 'includes/views/cookie-consent-banner.php';
	}

	/* ── [haruv_cookie_list] shortcode ─────────────────────────────────── */

	/**
	 * Register the list stylesheet, and enqueue it up-front when the shortcode
	 * is present in the queried post's content.
	 *
	 * The callback enqueues it too (covering widgets, blocks and template
	 * calls), but a stylesheet enqueued mid-`the_content` only prints in the
	 * footer — catching the common case here avoids that flash.
	 */
	public function register_list_assets() {
		if ( is_admin() ) {
			return;
		}

		wp_register_style(
			'at-cookie-list',
			AT_WOO_GF_INTEGRATION_URL . 'assets/css/cookie-list.css',
			array(),
			AT_WOO_GF_INTEGRATION_VERSION
		);

		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			wp_enqueue_style( 'at-cookie-list' );
		}
	}

	/**
	 * The cookie definitions rendered by the shortcode.
	 *
	 * Reads the *same* source of truth as the banner and preferences modal —
	 * the saved `at_woo_gf_cookie_settings` registry, falling back to
	 * `default_cookies()` — so the published list can never drift away from
	 * what the consent manager actually governs.
	 *
	 * @param array $atts Raw shortcode attributes, forwarded to the filter.
	 * @return array<int,array<string,string>> Cookie definitions.
	 */
	public function get_cookie_list_items( $atts = array() ) {
		$cookies = array_values( $this->get_settings()['cookies'] );

		/**
		 * Filter the cookie definitions rendered by [haruv_cookie_list].
		 *
		 * Lets a theme or site-fixes plugin append, remove or relabel entries
		 * without editing this plugin. Each item is an array with the keys
		 * `name`, `category`, `provider`, `purpose` and `expiry`; `category`
		 * should match a key from `default_categories()` (`necessary`,
		 * `functional`, `analytics`, `marketing`).
		 *
		 * @param array $cookies Cookie definitions from the registry.
		 * @param array $atts    Raw shortcode attributes.
		 */
		$cookies = apply_filters( 'haruv_cookie_list_items', $cookies, $atts );

		return is_array( $cookies ) ? $cookies : array();
	}

	/**
	 * Normalize a cookie definition to the five keys the table renders.
	 *
	 * @param mixed $cookie Raw registry entry.
	 * @return array|null Normalized entry, or null when unusable.
	 */
	private function normalize_cookie( $cookie ) {
		if ( ! is_array( $cookie ) || empty( $cookie['name'] ) ) {
			return null;
		}
		return array(
			'name'     => (string) $cookie['name'],
			'category' => isset( $cookie['category'] ) ? (string) $cookie['category'] : 'necessary',
			'provider' => isset( $cookie['provider'] ) ? (string) $cookie['provider'] : '',
			'purpose'  => isset( $cookie['purpose'] ) ? (string) $cookie['purpose'] : '',
			'expiry'   => isset( $cookie['expiry'] ) ? (string) $cookie['expiry'] : '',
		);
	}

	/**
	 * Render `[haruv_cookie_list]` — the public cookie table.
	 *
	 * Attributes are intentionally left free-form: they are not consumed here,
	 * only forwarded to the `haruv_cookie_list_items` filter so an extension
	 * can define its own (e.g. `category="analytics"`).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Table markup.
	 */
	public function render_cookie_list( $atts = array() ) {
		$atts = is_array( $atts ) ? $atts : array();

		wp_enqueue_style( 'at-cookie-list' );

		$settings   = $this->get_settings();
		$categories = is_array( $settings['categories'] ) ? $settings['categories'] : array();

		// Bucket by category so the table reads in a stable, meaningful order
		// (necessary first) rather than registry insertion order.
		$by_cat = array();
		foreach ( $this->get_cookie_list_items( $atts ) as $cookie ) {
			$cookie = $this->normalize_cookie( $cookie );
			if ( null === $cookie ) {
				continue;
			}
			$by_cat[ $cookie['category'] ][] = $cookie;
		}

		// Known categories in their declared order, then any stragglers whose
		// category was removed from the settings (they must still be disclosed).
		$ordered = array();
		foreach ( array_keys( $categories ) as $key ) {
			if ( ! empty( $by_cat[ $key ] ) ) {
				$ordered[ $key ] = $by_cat[ $key ];
				unset( $by_cat[ $key ] );
			}
		}
		foreach ( $by_cat as $key => $list ) {
			$ordered[ $key ] = $list;
		}

		// Only show the provider column when the data actually exists.
		$has_provider = false;
		foreach ( $ordered as $list ) {
			foreach ( $list as $cookie ) {
				if ( '' !== $cookie['provider'] ) {
					$has_provider = true;
					break 2;
				}
			}
		}

		ob_start();
		include AT_WOO_GF_INTEGRATION_PATH . 'includes/views/cookie-list.php';
		return ob_get_clean();
	}

	/* ── AJAX ──────────────────────────────────────────────────────────── */

	/**
	 * Save the visitor's granular choice. The client also writes the cookie for
	 * immediate effect; this endpoint mirrors it server-side and is the audit
	 * point should logging be added later.
	 */
	public function ajax_save() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$choice_raw = isset( $_POST['choice'] ) ? wp_unslash( $_POST['choice'] ) : array();
		$choice_raw = is_array( $choice_raw ) ? $choice_raw : array();

		$cats   = array_keys( $this->get_settings()['categories'] );
		$choice = array( 'necessary' => true );
		foreach ( $cats as $cat ) {
			if ( 'necessary' === $cat ) {
				continue;
			}
			$choice[ $cat ] = ! empty( $choice_raw[ $cat ] );
		}

		// Persist server-side (6 months), matching what the JS sets.
		$value = wp_json_encode( array( 'v' => 1, 'choice' => $choice, 't' => time() ) );
		// Only set if headers not sent yet (AJAX → fine).
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$value,
				array(
					'expires'  => time() + 6 * MONTH_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}

		wp_send_json_success( array( 'choice' => $choice ) );
	}

	/**
	 * Receive scanner report of observed cookie names not in the registry.
	 * Merge-store (deduped, capped) for the admin "detected" panel.
	 */
	public function ajax_report() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$names = isset( $_POST['cookies'] ) ? wp_unslash( $_POST['cookies'] ) : array();
		if ( ! is_array( $names ) ) {
			$names = array();
		}

		// Light rate-limit so a chatty page can't hammer the option.
		$rl_key = 'at_cc_report_' . md5( self::client_ip() );
		if ( get_transient( $rl_key ) ) {
			wp_send_json_success( array( 'throttled' => true ) );
		}
		set_transient( $rl_key, 1, MINUTE_IN_SECONDS );

		$detected = get_option( self::DETECTED, array() );
		$detected = is_array( $detected ) ? $detected : array();
		$known    = wp_list_pluck( $this->get_settings()['cookies'], 'name' );

		$added = 0;
		foreach ( $names as $name ) {
			$name = sanitize_text_field( $name );
			if ( '' === $name || self::COOKIE_NAME === $name ) {
				continue;
			}
			if ( in_array( $name, $known, true ) || isset( $detected[ $name ] ) ) {
				continue;
			}
			$detected[ $name ] = time();
			$added++;
			if ( count( $detected ) >= 200 ) {
				break; // hard cap.
			}
		}

		if ( $added > 0 ) {
			update_option( self::DETECTED, $detected, false );
		}

		wp_send_json_success( array( 'added' => $added ) );
	}

	private static function client_ip() {
		if ( class_exists( 'WC_Geolocation' ) ) {
			return WC_Geolocation::get_ip_address();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
