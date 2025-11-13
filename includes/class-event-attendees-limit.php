<?php
/**
 * Event Attendees Limit Handler
 *
 * @package WooGFIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Attendees Limit Handler Class.
 */
class WooGF_Event_Attendees_Limit {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Check stock before adding to cart
		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'check_event_availability' ), 10, 2 );
		add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'get_event_stock' ), 10, 2 );
		add_filter( 'woocommerce_product_get_manage_stock', array( $this, 'manage_event_stock' ), 10, 2 );
		
		// Update stock message
		add_filter( 'woocommerce_get_availability_text', array( $this, 'get_availability_text' ), 10, 2 );
		add_filter( 'woocommerce_get_availability_class', array( $this, 'get_availability_class' ), 10, 2 );
		
		// Validate quantity
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_event_capacity' ), 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_update' ), 10, 4 );
	}

	/**
	 * Check if event has available spots.
	 */
	public function check_event_availability( $is_in_stock, $product ) {
		if ( ! $product->is_type( 'event' ) ) {
			return $is_in_stock;
		}

		$max_attendees = $product->get_meta( '_max_attendees', true );
		if ( empty( $max_attendees ) || $max_attendees <= 0 ) {
			return true; // No limit
		}

		$current_attendees = $this->get_current_attendees_count( $product->get_id() );
		return $current_attendees < $max_attendees;
	}

	/**
	 * Get event stock quantity.
	 */
	public function get_event_stock( $stock_quantity, $product ) {
		if ( ! $product->is_type( 'event' ) ) {
			return $stock_quantity;
		}

		$max_attendees = $product->get_meta( '_max_attendees', true );
		if ( empty( $max_attendees ) || $max_attendees <= 0 ) {
			return null; // No limit
		}

		$current_attendees = $this->get_current_attendees_count( $product->get_id() );
		$available = $max_attendees - $current_attendees;
		
		return max( 0, $available );
	}

	/**
	 * Set manage stock for event products.
	 */
	public function manage_event_stock( $manage_stock, $product ) {
		if ( ! $product->is_type( 'event' ) ) {
			return $manage_stock;
		}

		$max_attendees = $product->get_meta( '_max_attendees', true );
		return ! empty( $max_attendees ) && $max_attendees > 0;
	}

	/**
	 * Get availability text.
	 */
	public function get_availability_text( $availability, $product ) {
		if ( ! $product->is_type( 'event' ) ) {
			return $availability;
		}

		$max_attendees = $product->get_meta( '_max_attendees', true );
		if ( empty( $max_attendees ) || $max_attendees <= 0 ) {
			return __( 'מקומות פנויים', 'woo-gf-integration' );
		}

		$current_attendees = $this->get_current_attendees_count( $product->get_id() );
		$available = $max_attendees - $current_attendees;

		if ( $available <= 0 ) {
			return __( 'האירוע מלא', 'woo-gf-integration' );
		} elseif ( $available <= 5 ) {
			/* translators: %d: number of available spots */
			return sprintf( __( 'נותרו %d מקומות אחרונים!', 'woo-gf-integration' ), $available );
		} else {
			/* translators: %d: number of available spots */
			return sprintf( __( '%d מקומות פנויים', 'woo-gf-integration' ), $available );
		}
	}

	/**
	 * Get availability class.
	 */
	public function get_availability_class( $class, $product ) {
		if ( ! $product->is_type( 'event' ) ) {
			return $class;
		}

		$max_attendees = $product->get_meta( '_max_attendees', true );
		if ( empty( $max_attendees ) || $max_attendees <= 0 ) {
			return 'in-stock';
		}

		$current_attendees = $this->get_current_attendees_count( $product->get_id() );
		$available = $max_attendees - $current_attendees;

		if ( $available <= 0 ) {
			return 'out-of-stock';
		} elseif ( $available <= 5 ) {
			return 'low-stock';
		} else {
			return 'in-stock';
		}
	}

	/**
	 * Validate event capacity before adding to cart.
	 */
	public function validate_event_capacity( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		$product = wc_get_product( $product_id );
		
		if ( ! $product || ! $product->is_type( 'event' ) ) {
			return $passed;
		}

		$max_attendees = $product->get_meta( '_max_attendees', true );
		if ( empty( $max_attendees ) || $max_attendees <= 0 ) {
			return $passed; // No limit
		}

		$current_attendees = $this->get_current_attendees_count( $product_id );
		$cart_quantity = $this->get_product_quantity_in_cart( $product_id );
		$total_requested = $current_attendees + $cart_quantity + $quantity;

		if ( $total_requested > $max_attendees ) {
			$available = max( 0, $max_attendees - $current_attendees - $cart_quantity );
			if ( $available > 0 ) {
				wc_add_notice( 
					sprintf( 
						/* translators: 1: product name, 2: available quantity */
						__( 'מצטערים, ניתן להוסיף רק %2$d כרטיסים נוספים ל"%1$s".', 'woo-gf-integration' ), 
						$product->get_name(),
						$available
					), 
					'error' 
				);
			} else {
				wc_add_notice( 
					sprintf( 
						/* translators: %s: product name */
						__( 'מצטערים, אין מקומות פנויים ב"%s".', 'woo-gf-integration' ), 
						$product->get_name()
					), 
					'error' 
				);
			}
			return false;
		}

		return $passed;
	}

	/**
	 * Validate cart update.
	 */
	public function validate_cart_update( $passed, $cart_item_key, $values, $quantity ) {
		$product = $values['data'];
		
		if ( ! $product || ! $product->is_type( 'event' ) ) {
			return $passed;
		}

		$product_id = $product->get_id();
		$max_attendees = $product->get_meta( '_max_attendees', true );
		
		if ( empty( $max_attendees ) || $max_attendees <= 0 ) {
			return $passed; // No limit
		}

		$current_attendees = $this->get_current_attendees_count( $product_id );
		$old_quantity = $values['quantity'];
		$cart_quantity = $this->get_product_quantity_in_cart( $product_id ) - $old_quantity;
		$total_requested = $current_attendees + $cart_quantity + $quantity;

		if ( $total_requested > $max_attendees ) {
			$available = max( 0, $max_attendees - $current_attendees - $cart_quantity );
			wc_add_notice( 
				sprintf( 
					/* translators: 1: product name, 2: available quantity */
					__( 'מצטערים, ניתן להזמין רק %2$d כרטיסים ל"%1$s".', 'woo-gf-integration' ), 
					$product->get_name(),
					$available
				), 
				'error' 
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Get current attendees count.
	 */
	private function get_current_attendees_count( $product_id ) {
		$product = wc_get_product( $product_id );
		$form_id = $product ? $product->get_meta( '_gravity_form_id', true ) : '';
		
		if ( ! $form_id || ! class_exists( 'GFAPI' ) ) {
			return 0;
		}

		$search_criteria = array(
			'status' => 'active',
			'field_filters' => array(
				array(
					'key'   => 'woo_gf_product_id',
					'value' => $product_id,
				),
			),
		);

		return GFAPI::count_entries( $form_id, $search_criteria );
	}

	/**
	 * Get product quantity in cart.
	 */
	private function get_product_quantity_in_cart( $product_id ) {
		$quantity = 0;
		
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( $cart_item['product_id'] == $product_id ) {
					$quantity += $cart_item['quantity'];
				}
			}
		}
		
		return $quantity;
	}
} 