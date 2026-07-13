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
	 * @var array
	 */
	protected $extra_data = array(
		'event_date'     => '',
		'event_end_date' => '',
		'event_location' => '',
		'max_attendees'  => 0,
		'event_type'     => 'physical', // physical, virtual, hybrid
        'event_duration' => '',
        'inquiries_email' => '',
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
	 * Get inquiries email.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_inquiries_email( $context = 'view' ) {
		return $this->get_prop( 'inquiries_email', $context );
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
		$this->set_prop( 'event_type', $type );
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
	 * Set inquiries email.
	 *
	 * @param string $email Inquiries email.
	 */
	public function set_inquiries_email( $email ) {
		$this->set_prop( 'inquiries_email', $email );
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

	/**
	 * Save data (override parent to save event data).
	 *
	 * @since 3.0.0
	 * @return int
	 */
	public function save() {
		// Save the parent data first
		$id = parent::save();
		
		if ( $id && ! is_wp_error( $id ) ) {
			// Save event-specific meta using standard WordPress functions to avoid "internal meta key" notices
			// These are already in $this->extra_data but WooCommerce CPT data store doesn't save them automatically
			update_post_meta( $id, '_event_date', $this->get_event_date( 'edit' ) );
			update_post_meta( $id, '_event_end_date', $this->get_event_end_date( 'edit' ) );
			update_post_meta( $id, '_event_location', $this->get_event_location( 'edit' ) );
			update_post_meta( $id, '_max_attendees', $this->get_max_attendees( 'edit' ) );
			update_post_meta( $id, '_event_type', $this->get_event_type( 'edit' ) );
			update_post_meta( $id, '_event_duration', $this->get_event_duration( 'edit' ) );
			update_post_meta( $id, '_event_inquiries_email', $this->get_inquiries_email( 'edit' ) );
		}
		
		return $id;
	}
	
	/**
	 * Read product data.
	 *
	 * @since 3.0.0
	 */
	protected function read_product_data() {
		parent::read_product_data();
		
		// Read event-specific data using get_post_meta to avoid internal meta key notices
		$id = $this->get_id();
		$this->set_props( array(
			'event_date'     => get_post_meta( $id, '_event_date', true ),
			'event_end_date' => get_post_meta( $id, '_event_end_date', true ),
			'event_location' => get_post_meta( $id, '_event_location', true ),
			'max_attendees'  => (int) get_post_meta( $id, '_max_attendees', true ),
			'event_type'     => get_post_meta( $id, '_event_type', true ) ?: 'physical',
			'event_duration' => get_post_meta( $id, '_event_duration', true ),
			'inquiries_email' => get_post_meta( $id, '_event_inquiries_email', true ) ?: get_post_meta( $id, '_inquiries_email', true ), // Fallback to old key
		) );
	}
} 