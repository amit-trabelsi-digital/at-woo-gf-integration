<?php
/**
 * Event Product Type
 *
 * @package WooGFIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Product Type Class.
 *
 * @class WC_Product_Event
 * @extends WC_Product
 */
class WC_Product_Event extends WC_Product {

    /**
     * Constructor.
     *
     * @param int|WC_Product|object $product Product object.
     */
    public function __construct( $product = 0 ) {
        parent::__construct( $product );

        // Set defaults for event products
        $this->set_defaults();
    }

	/**
	 * Stores product data.
	 *
	 * IMPORTANT: every key here becomes a WooCommerce CRUD prop, and the CPT data
	 * store reads/writes it from/to the post meta key `_{key}` automatically
	 * (see WC_Product_Data_Store_CPT::read_extra_data() / ::update_post_meta()).
	 * The key MUST therefore match the meta key the admin UI saves, otherwise the
	 * prop is always read as empty and every $product->save() writes that empty value
	 * back over the real meta. That is exactly what happened with the old
	 * `inquiries_email` key (meta is `_event_inquiries_email`, not
	 * `_inquiries_email`) — see HRV-DOUBLE-SAVE.
	 *
	 * @var array
	 */
	protected $extra_data = array(
		'event_date'             => '',
		'event_end_date'         => '',
		'event_location'         => '',
		'max_attendees'          => 0,
		'event_type'             => 'physical', // physical, virtual, hybrid.
		'event_duration'         => '',
		'event_inquiries_email'  => '',
	);

    /**
     * Set default properties for event products.
     */
    public function set_defaults() {
        $this->set_virtual( true );
        $this->set_manage_stock( 'yes' );
        $this->set_sold_individually( true );
    }

	/**
	 * Get internal type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'event';
	}

	/**
	 * Get event date.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_event_date( $context = 'view' ) {
		return $this->get_prop( 'event_date', $context );
	}

	/**
	 * Get event end date.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_event_end_date( $context = 'view' ) {
		return $this->get_prop( 'event_end_date', $context );
	}

	/**
	 * Get event location.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_event_location( $context = 'view' ) {
		return $this->get_prop( 'event_location', $context );
	}

	/**
	 * Get max attendees.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return int
	 */
	public function get_max_attendees( $context = 'view' ) {
		return $this->get_prop( 'max_attendees', $context );
	}

	/**
	 * Get event type.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_event_type( $context = 'view' ) {
		return $this->get_prop( 'event_type', $context );
	}

	/**
	 * Get event duration.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_event_duration( $context = 'view' ) {
		return $this->get_prop( 'event_duration', $context );
	}

	/**
	 * Get inquiries email (stored in `_event_inquiries_email`).
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_event_inquiries_email( $context = 'view' ) {
		return $this->get_prop( 'event_inquiries_email', $context );
	}

	/**
	 * Backwards-compatible alias for get_event_inquiries_email().
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_inquiries_email( $context = 'view' ) {
		return $this->get_event_inquiries_email( $context );
	}

	/**
	 * Set event date.
	 *
	 * @param string $date Event date.
	 */
	public function set_event_date( $date ) {
		$this->set_prop( 'event_date', $date );
	}

	/**
	 * Set event end date.
	 *
	 * @param string $date Event end date.
	 */
	public function set_event_end_date( $date ) {
		$this->set_prop( 'event_end_date', $date );
	}

	/**
	 * Set event location.
	 *
	 * @param string $location Event location.
	 */
	public function set_event_location( $location ) {
		$this->set_prop( 'event_location', $location );
	}

	/**
	 * Set max attendees.
	 *
	 * @param int $max Max attendees.
	 */
	public function set_max_attendees( $max ) {
		$this->set_prop( 'max_attendees', absint( $max ) );
	}

	/**
	 * Set event type.
	 *
	 * @param string $type Event type.
	 */
	public function set_event_type( $type ) {
		// The data store feeds this straight from post meta, which may be empty on
		// products that were never saved through the event panel — keep the default.
		$this->set_prop( 'event_type', $type ? $type : 'physical' );
	}

	/**
	 * Set event duration.
	 *
	 * @param string $duration Event duration.
	 */
	public function set_event_duration( $duration ) {
		$this->set_prop( 'event_duration', $duration );
	}

	/**
	 * Set inquiries email (stored in `_event_inquiries_email`).
	 *
	 * @param string $email Inquiries email.
	 */
	public function set_event_inquiries_email( $email ) {
		$this->set_prop( 'event_inquiries_email', $email );
	}

	/**
	 * Backwards-compatible alias for set_event_inquiries_email().
	 *
	 * @param string $email Inquiries email.
	 */
	public function set_inquiries_email( $email ) {
		$this->set_event_inquiries_email( $email );
	}

	/**
	 * Returns false if the event cannot be bought.
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		return apply_filters( 'woocommerce_is_purchasable', $this->exists() && ( $this->is_in_stock() || $this->backorders_allowed() ) && '' !== $this->get_price(), $this );
	}

	/**
	 * Get the add to cart button text.
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		return apply_filters( 'woocommerce_product_add_to_cart_text', __( 'הרשמה לאירוע', 'at-woo-gf-integration' ), $this );
	}

	/**
	 * Get the add to cart button text for the single page.
	 *
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', __( 'הירשם לאירוע', 'at-woo-gf-integration' ), $this );
	}

	/**
	 * Events are always virtual.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_virtual( $context = 'view' ) {
		return true;
	}

	/*
	 * NOTE — no save() / read_product_data() overrides here on purpose.
	 *
	 * They used to exist and were the root cause of the "you have to press Update
	 * twice" bug:
	 *
	 * - read_product_data() is a *data store* method (WC_Product_Data_Store_CPT),
	 *   not a WC_Product method. The override was dead code and was never called,
	 *   so the props were always populated by WooCommerce core instead, which maps
	 *   each extra_data key to the `_{key}` post meta.
	 *
	 * - save() then wrote every event meta key back from those props on *every*
	 *   $product->save(), unconditionally. Because `inquiries_email` mapped to
	 *   `_inquiries_email` while the admin saves `_event_inquiries_email`, the prop
	 *   was always empty and the override wiped the field. Worse, WooCommerce calls
	 *   $product->save() again from WC_Meta_Box_Product_Images::save() (priority 20)
	 *   — i.e. AFTER woocommerce_process_product_meta_event — so the stale values
	 *   always had the last word.
	 *
	 * WooCommerce core already persists every extra_data prop to `_{key}` meta in
	 * WC_Product_Data_Store_CPT::update_post_meta(), and the authoritative admin
	 * write path is WooGF_Event_Product_Type::save_event_data(). Do not reintroduce
	 * these overrides.
	 */
} 