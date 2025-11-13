<?php
/**
 * Event Product Type Handler
 *
 * @package WooGFIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Product Type Handler Class.
 */
class WooGF_Event_Product_Type {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_event_product_type' ), 10 );
		add_action( 'init', array( $this, 'register_taxonomies' ), 11 );
		add_filter( 'product_type_selector', array( $this, 'add_event_product_type' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_event_fields' ) );
		add_action( 'woocommerce_product_data_tabs', array( $this, 'add_event_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'add_event_panel' ) );
		add_action( 'woocommerce_process_product_meta_event', array( $this, 'save_event_data' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_event_data_fallback' ), 10, 1 );
		add_filter( 'woocommerce_product_class', array( $this, 'set_product_class' ), 10, 4 );
		add_action( 'admin_footer', array( $this, 'event_admin_scripts' ) );
		add_filter( 'woocommerce_product_type_query', array( $this, 'fix_product_type_query' ), 10, 2 );
		
		// Remove irrelevant tabs for event products
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'modify_product_tabs' ), 99 );
		
		// Initialize attendees limit handler
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-event-attendees-limit.php';
		new WooGF_Event_Attendees_Limit();
	}

    /**
     * Register custom taxonomies for products.
     */
    public function register_taxonomies() {
        // Note: Target Audience (target_audiences) and Event Type (event_type) taxonomies
        // are already registered in the theme's custom-taxonomies.php
        // This method is kept for future custom taxonomies if needed
    }

	/**
	 * Register the event product type.
	 */
	public function register_event_product_type() {
		// Check if the term already exists
		$term = term_exists( 'event', 'product_type' );
		
		if ( ! $term ) {
			wp_insert_term( 'event', 'product_type' );
		}
	}

	/**
	 * Add event to product type selector.
	 */
	public function add_event_product_type( $types ) {
		$types['event'] = __( 'אירוע', 'woo-gf-integration' );
		return $types;
	}

	/**
	 * Add event tab.
	 */
	public function add_event_tab( $tabs ) {
		$tabs['event_options'] = array(
			'label'    => __( 'פרטי אירוע', 'woo-gf-integration' ),
			'target'   => 'event_product_data',
			'class'    => array( 'show_if_event' ),
			'priority' => 21,
		);
		return $tabs;
	}

	/**
	 * Add event panel.
	 */
	public function add_event_panel() {
		global $post;
		$product = wc_get_product( $post->ID );
		
		if ( ! $product ) {
			return;
		}
		?>
		<div id="event_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'          => '_event_date',
						'label'       => '<span class="dashicons dashicons-calendar-alt"></span> ' . __( 'תאריך האירוע', 'woo-gf-integration' ),
						'placeholder' => 'YYYY-MM-DD HH:MM',
						'desc_tip'    => true,
						'description' => __( 'תאריך ושעת התחלת האירוע', 'woo-gf-integration' ),
						'type'        => 'datetime-local',
						'value'       => $product->is_type( 'event' ) ? str_replace( ' ', 'T', get_post_meta( $product->get_id(), '_event_date', true ) ) : '',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_event_end_date',
						'label'       => '<span class="dashicons dashicons-clock"></span> ' . __( 'תאריך סיום האירוע', 'woo-gf-integration' ),
						'placeholder' => 'YYYY-MM-DD HH:MM',
						'desc_tip'    => true,
						'description' => __( 'תאריך ושעת סיום האירוע (אופציונלי)', 'woo-gf-integration' ),
						'type'        => 'datetime-local',
						'value'       => $product->is_type( 'event' ) ? str_replace( ' ', 'T', get_post_meta( $product->get_id(), '_event_end_date', true ) ) : '',
					)
				);

				woocommerce_wp_textarea_input(
					array(
						'id'          => '_event_location',
						'label'       => '<span class="dashicons dashicons-location"></span> ' . __( 'מיקום האירוע', 'woo-gf-integration' ),
						'placeholder' => __( 'כתובת או קישור לזום', 'woo-gf-integration' ),
						'desc_tip'    => true,
						'description' => __( 'מיקום פיזי או קישור לאירוע מקוון', 'woo-gf-integration' ),
						'value'       => $product->is_type( 'event' ) ? get_post_meta( $product->get_id(), '_event_location', true ) : '',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_max_attendees',
						'label'             => '<span class="dashicons dashicons-groups"></span> ' . __( 'מספר משתתפים מקסימלי', 'woo-gf-integration' ),
						'desc_tip'          => true,
						'description'       => __( 'השאר ריק או 0 לאירוע ללא הגבלה (יסתנכרן עם Gravity Forms)', 'woo-gf-integration' ),
						'type'              => 'number',
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '0',
						),
						'value'             => $product->get_meta( '_max_attendees', true ) ? $product->get_meta( '_max_attendees', true ) : '',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_event_duration',
						'label'       => '<span class="dashicons dashicons-backup"></span> ' . __( 'משך זמן (בשעות)', 'at-woo-gf-integration' ),
						'desc_tip'    => true,
						'description' => __( 'הזן את משך האירוע בשעות. לדוגמה: 2.5', 'at-woo-gf-integration' ),
						'type'        => 'number',
						'custom_attributes' => array(
							'step' => '0.5',
							'min'  => '0',
						),
						'value'       => $product->get_meta( '_event_duration', true ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_event_inquiries_email',
						'label'       => '<span class="dashicons dashicons-email-alt"></span> ' . __( 'מייל לבירורים', 'at-woo-gf-integration' ),
						'placeholder' => __( 'לדוגמה: info@example.com', 'at-woo-gf-integration' ),
						'desc_tip'    => true,
						'description' => __( 'כתובת המייל אליה יגיעו פניות בנוגע לאירוע', 'at-woo-gf-integration' ),
						'type'        => 'email',
						'value'       => $product->get_meta( '_event_inquiries_email', true ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_event_manager',
						'label'       => '<span class="dashicons dashicons-businessman"></span> ' . __( 'פרוייקטור אחראי', 'at-woo-gf-integration' ),
						'placeholder' => __( 'לדוגמה: אמיר כהן', 'at-woo-gf-integration' ),
						'desc_tip'    => true,
						'description' => __( 'שם האדם האחראי על האירוע', 'at-woo-gf-integration' ),
						'type'        => 'text',
						'value'       => $product->get_meta( '_event_manager', true ),
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_event_type',
						'label'       => '<span class="dashicons dashicons-admin-site-alt3"></span> ' . __( 'סוג אירוע', 'woo-gf-integration' ),
						'options'     => array(
							'physical' => __( '📍 פיזי', 'woo-gf-integration' ),
							'virtual'  => __( '💻 מקוון', 'woo-gf-integration' ),
							'hybrid'   => __( '🔄 משולב', 'woo-gf-integration' ),
						),
						'desc_tip'    => true,
						'description' => __( 'בחר את סוג האירוע', 'woo-gf-integration' ),
						'value'       => $product->get_meta( '_event_type', true ) ?: 'physical',
					)
				);
				?>
			</div>

			<div class="options_group">
				<p class="form-field">
					<label><span class="dashicons dashicons-id-alt"></span> <?php esc_html_e( 'משתתפים רשומים', 'woo-gf-integration' ); ?></label>
					<?php
					$form_id = $product->get_meta( '_woo_gf_form_id', true );
					if ( $form_id && class_exists( 'GFAPI' ) ) {
						$search_criteria = array(
							'status' => 'active',
							'field_filters' => array(
								array(
									'key'   => 'woo_gf_product_id',
									'value' => $post->ID,
								),
							),
						);
						$entry_count = GFAPI::count_entries( $form_id, $search_criteria );
						$max_attendees = $product->get_meta( '_max_attendees', true );
						
						echo '<span class="event-attendees-count">';
						/* translators: %d: number of attendees */
						echo sprintf( esc_html__( '🎫 %d משתתפים רשומים', 'woo-gf-integration' ), $entry_count );
						
						if ( $max_attendees > 0 ) {
							/* translators: %d: maximum number of attendees */
							echo ' ' . sprintf( esc_html__( '(מתוך %d)', 'woo-gf-integration' ), $max_attendees );
							
							$percentage = ( $entry_count / $max_attendees ) * 100;
							echo '<br><progress value="' . esc_attr( $entry_count ) . '" max="' . esc_attr( $max_attendees ) . '" style="width: 100%; margin-top: 5px;"></progress>';
							echo '<br><small>' . esc_html( round( $percentage, 1 ) ) . '% ' . esc_html__( 'תפוסה', 'woo-gf-integration' ) . '</small>';
						}
						echo '</span>';
					} else {
						echo '<span class="description">';
						echo '<span class="dashicons dashicons-warning"></span> ';
						esc_html_e( 'יש לחבר טופס Gravity Forms כדי לעקוב אחר משתתפים', 'woo-gf-integration' );
						echo '</span>';
					}
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Fallback save for all product types - checks if it's an event product
	 */
	public function save_event_data_fallback( $post_id ) {
		// Check if this is an event product
		$product_type = isset( $_POST['product-type'] ) ? sanitize_text_field( $_POST['product-type'] ) : '';
		
		if ( 'event' === $product_type ) {
			$this->save_event_data( $post_id );
		}
	}

	/**
	 * Save event data.
	 */
	public function save_event_data( $post_id ) {
		// Verify nonce if present
		if ( isset( $_POST['woocommerce_meta_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
				return;
			}
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		// Initialize max_attendees variable
		$max_attendees = 0;

		// Save event date
		if ( isset( $_POST['_event_date'] ) ) {
			$event_date = ! empty( $_POST['_event_date'] ) ? str_replace( 'T', ' ', wc_clean( wp_unslash( $_POST['_event_date'] ) ) ) : '';
			$product->update_meta_data( '_event_date', $event_date );
		} else {
			$product->update_meta_data( '_event_date', '' );
		}

		// Save event end date
		if ( isset( $_POST['_event_end_date'] ) ) {
			$event_end_date = ! empty( $_POST['_event_end_date'] ) ? str_replace( 'T', ' ', wc_clean( wp_unslash( $_POST['_event_end_date'] ) ) ) : '';
			$product->update_meta_data( '_event_end_date', $event_end_date );
		} else {
			$product->update_meta_data( '_event_end_date', '' );
		}

		// Save event location
		if ( isset( $_POST['_event_location'] ) ) {
			$location = ! empty( $_POST['_event_location'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_event_location'] ) ) : '';
			$product->update_meta_data( '_event_location', $location );
		}

		// Save max attendees - allow empty/0 for unlimited
		$max_attendees = 0;
		if ( isset( $_POST['_max_attendees'] ) && ! empty( $_POST['_max_attendees'] ) ) {
			$max_attendees = intval( $_POST['_max_attendees'] );
		}
		$product->update_meta_data( '_max_attendees', $max_attendees );

		// Save event type
		if ( isset( $_POST['_event_type'] ) ) {
			$product->update_meta_data( '_event_type', sanitize_text_field( wp_unslash( $_POST['_event_type'] ) ) );
		}

		// Save event duration
		if ( isset( $_POST['_event_duration'] ) ) {
			$duration = ! empty( $_POST['_event_duration'] ) ? wc_clean( wp_unslash( $_POST['_event_duration'] ) ) : '';
			$product->update_meta_data( '_event_duration', $duration );
		} else {
			$product->update_meta_data( '_event_duration', '' );
		}

		// Save inquiries email
		if ( isset( $_POST['_event_inquiries_email'] ) ) {
			$email = ! empty( $_POST['_event_inquiries_email'] ) ? sanitize_email( wp_unslash( $_POST['_event_inquiries_email'] ) ) : '';
			$product->update_meta_data( '_event_inquiries_email', $email );
		} else {
			$product->update_meta_data( '_event_inquiries_email', '' );
		}

		// Save event manager
		if ( isset( $_POST['_event_manager'] ) ) {
			$manager = ! empty( $_POST['_event_manager'] ) ? sanitize_text_field( wp_unslash( $_POST['_event_manager'] ) ) : '';
			$product->update_meta_data( '_event_manager', $manager );
		} else {
			$product->update_meta_data( '_event_manager', '' );
		}

		// Events are always virtual
		$product->update_meta_data( '_virtual', 'yes' );
		$product->update_meta_data( '_downloadable', 'no' );
		
		// Enable stock management for events
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $max_attendees );
		
		// Save the product
		$product->save();

		// Sync with Gravity Forms form limit if connected
		$form_id = $product->get_meta( '_woo_gf_form_id', true );
		if ( $form_id && class_exists( 'GFAPI' ) && $max_attendees > 0 ) {
			$this->sync_with_gravity_forms( $form_id, $max_attendees );
		}
	}

	/**
	 * Sync max attendees with Gravity Forms form limit.
	 *
	 * @param int $form_id   The Gravity Forms form ID.
	 * @param int $max_limit The maximum number of attendees.
	 */
	private function sync_with_gravity_forms( $form_id, $max_limit ) {
		try {
			$form = GFAPI::get_form( $form_id );
			if ( ! $form ) {
				return;
			}

			// Update form limit if different
			if ( isset( $form['limitEntries'] ) && isset( $form['limitEntriesCount'] ) ) {
				if ( ( ! $form['limitEntries'] || $form['limitEntriesCount'] != $max_limit ) ) {
					$form['limitEntries'] = true;
					$form['limitEntriesCount'] = $max_limit;
					
					$result = GFAPI::update_form( $form );
					if ( is_wp_error( $result ) ) {
						error_log( 'Error syncing form limit: ' . $result->get_error_message() );
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'Exception syncing with Gravity Forms: ' . $e->getMessage() );
		}
	}

	/**
	 * Set product class for event products.
	 */
	public function set_product_class( $classname, $product_type, $post_type, $product_id ) {
		if ( 'event' === $product_type ) {
			$classname = 'WC_Product_Event';
		}
		return $classname;
	}

	/**
	 * Fix product type query for event products.
	 */
	public function fix_product_type_query( $override, $product_id ) {
		if ( ! $override ) {
			$terms = wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) && in_array( 'event', $terms ) ) {
				return 'event';
			}
		}
		return $override;
	}

	/**
	 * Add general fields for events.
	 */
	public function add_event_fields() {
		echo '<div class="options_group show_if_event clear"></div>';
	}

	/**
	 * Modify product tabs for event products.
	 */
	public function modify_product_tabs( $tabs ) {
		// Remove shipping tab for events
		$tabs['shipping']['class'][] = 'hide_if_event';
		
		// Show inventory tab for events (to manage stock/attendees)
		if ( isset( $tabs['inventory']['class'] ) ) {
			$key = array_search( 'hide_if_event', $tabs['inventory']['class'] );
			if ( false !== $key ) {
				unset( $tabs['inventory']['class'][ $key ] );
			}
		}
		
		// Make sure general tab is always visible for events
		if ( isset( $tabs['general']['class'] ) ) {
			$tabs['general']['class'][] = 'show_if_event';
		}
		
		return $tabs;
	}

	/**
	 * Admin scripts for event products.
	 */
	public function event_admin_scripts() {
		global $pagenow, $post;
		
		if ( ( 'post.php' === $pagenow || 'post-new.php' === $pagenow ) && 'product' === get_post_type() ) {
			?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Show/hide event fields
				function show_hide_event_fields() {
					var product_type = $('#product-type').val();
					
					if ('event' === product_type) {
						$('.show_if_event').show();
						$('.hide_if_event').hide();
						
						// Events are always virtual
						$('#_virtual').prop('checked', true).prop('disabled', true);
						$('#_downloadable').prop('checked', false).prop('disabled', true);
						
						// Show pricing and other essential fields
						$('.pricing').show();
						$('._regular_price_field').show();
						$('._sale_price_field').show();
						$('.sales_price_dates_fields').show();
						$('._tax_status_field').show();
						$('._tax_class_field').show();
						
						// Hide only non-relevant simple product options
						$('.show_if_simple').not('.pricing').not('.show_if_event').hide();
						$('._sku_field').parent('.options_group').show();
						$('._manage_stock_field').parent('.options_group').show();
					} else {
						$('.show_if_event').hide();
						$('.hide_if_event').show();
						$('#_virtual').prop('disabled', false);
						$('#_downloadable').prop('disabled', false);
					}
				}
				
				// On page load
				show_hide_event_fields();
				
				// On product type change
				$('#product-type').change(function() {
					show_hide_event_fields();
				});
			});
			</script>
			<style>
				.show_if_event {
					display: none;
				}
				.event-attendees-count {
					display: inline-block;
					padding: 5px 0;
				}
				.event-attendees-count progress {
					height: 20px;
					border-radius: 3px;
				}
				.event-attendees-count small {
					color: #666;
				}
				
				/* Icon styles */
				#event_product_data .form-field label .dashicons,
				#gravity_forms_product_data .form-field .dashicons {
					font-size: 16px;
					width: 16px;
					height: 16px;
					line-height: 1;
					vertical-align: text-bottom;
					margin-left: 4px;
					color: #646970;
				}
				
				#event_product_data .form-field label .dashicons-calendar-alt { color: #0073aa; }
				#event_product_data .form-field label .dashicons-clock { color: #f39c12; }
				#event_product_data .form-field label .dashicons-location { color: #e74c3c; }
				#event_product_data .form-field label .dashicons-groups { color: #27ae60; }
				#event_product_data .form-field label .dashicons-admin-site-alt3 { color: #8e44ad; }
				#event_product_data .form-field label .dashicons-backup { color: #1e8cbe; }
				#event_product_data .form-field label .dashicons-email-alt { color: #3498db; }
				#event_product_data .form-field label .dashicons-id-alt { color: #2980b9; }
				#event_product_data .form-field .dashicons-warning { color: #f39c12; }
				
				/* Event panel styling */
				#event_product_data .options_group {
					background: #f8f8f8;
					border-top: 1px solid #eee;
				}
				
				#event_product_data .options_group:first-child {
					border-top: none;
				}
				
				#event_product_data progress {
					background-color: #f0f0f0;
					border: 1px solid #ccc;
				}
				
				#event_product_data progress::-webkit-progress-bar {
					background-color: #f0f0f0;
					border-radius: 3px;
				}
				
				#event_product_data progress::-webkit-progress-value {
					background-color: #0073aa;
					border-radius: 3px;
				}
				
				#event_product_data progress::-moz-progress-bar {
					background-color: #0073aa;
					border-radius: 3px;
				}
			</style>
			<?php
		}
	}
} 