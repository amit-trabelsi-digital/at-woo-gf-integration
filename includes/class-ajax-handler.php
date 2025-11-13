<?php
/**
 * AJAX Handler Class
 * 
 * @package WooGFIntegration
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class to handle AJAX requests
 */
class Woo_GF_Ajax_Handler {

    /**
     * Instance of this class.
     *
     * @var Woo_GF_Ajax_Handler
     */
    private static $instance = null;

    /**
     * Get the singleton instance of this class.
     *
     * @return Woo_GF_Ajax_Handler
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Register AJAX handlers
        add_action( 'wp_ajax_woo_gf_get_entries', array( $this, 'get_entries' ) );
        add_action( 'wp_ajax_woo_gf_get_entry_details', array( $this, 'get_entry_details' ) );
        add_action( 'wp_ajax_haruv_create_gf_form_for_event', array( $this, 'create_form' ) );
    }

    /**
     * Get entries for a form
     */
    public function get_entries() {
        // Check nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'at_woo_gf_integration_nonce' ) ) {
            wp_die( __( 'Security check failed', 'at-woo-gf-integration' ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( __( 'Insufficient permissions', 'woo-gf-integration' ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
        $page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
        $per_page = 10;

        if ( ! $form_id ) {
            wp_send_json_error( __( 'Invalid form ID', 'woo-gf-integration' ) );
        }

        // Get form
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) {
            wp_send_json_error( __( 'Form not found', 'woo-gf-integration' ) );
        }

        // Calculate column count for table
        $column_count = 3; // ID, Date, Actions
        foreach ( $form['fields'] as $field ) {
            if ( in_array( $field->type, array( 'name', 'email', 'phone', 'text' ) ) ) {
                $column_count++;
            }
        }

        // Set up search criteria - include ALL entries, not just active
        // This will show entries regardless of payment status
        $search_criteria = array();
        
        // Try to filter by product if provided, but if no entries found, show all
        $entries_found_with_product_filter = false;
        if ( $product_id ) {
            // First try with product filter
            $test_criteria = array(
                'field_filters' => array(
                    array(
                        'key' => 'woo_gf_product_id',
                        'value' => $product_id,
                        'operator' => '=',
                    ),
                ),
            );
            $test_count = GFAPI::count_entries( $form_id, $test_criteria );
            
            if ( $test_count > 0 ) {
                // Found entries with product filter
                $search_criteria = $test_criteria;
                $entries_found_with_product_filter = true;
            }
        }
        
        // If no product filter or no entries found with product filter, show ALL entries from this form
        if ( ! $entries_found_with_product_filter ) {
            $search_criteria = array(); // Empty = show all
        }

        // Set up paging
        $paging = array(
            'offset' => ( $page - 1 ) * $per_page,
            'page_size' => $per_page,
        );

        // Set up sorting
        $sorting = array(
            'key' => 'date_created',
            'direction' => 'DESC',
        );

        // Get entries
        $total_count = 0;
        $entries = GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging, $total_count );

        if ( is_wp_error( $entries ) ) {
            wp_send_json_error( $entries->get_error_message() );
        }

        // Build response HTML
        ob_start();
        ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'מזהה', 'woo-gf-integration' ); ?></th>
                    <?php foreach ( $form['fields'] as $field ) : ?>
                        <?php if ( in_array( $field->type, array( 'name', 'email', 'phone', 'text' ) ) ) : ?>
                            <th><?php echo esc_html( $field->label ); ?></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <th><?php esc_html_e( 'תאריך', 'woo-gf-integration' ); ?></th>
                    <th><?php esc_html_e( 'פעולות', 'woo-gf-integration' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $entries ) ) : ?>
                    <tr>
                        <td colspan="<?php echo $column_count; ?>"><?php esc_html_e( 'לא נמצאו הרשמות', 'woo-gf-integration' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( $entry['id'] ); ?></td>
                            <?php foreach ( $form['fields'] as $field ) : ?>
                                <?php if ( in_array( $field->type, array( 'name', 'email', 'phone', 'text' ) ) ) : ?>
                                    <td>
                                        <?php 
                                        if ( 'name' === $field->type ) {
                                            $name_parts = array();
                                            if ( ! empty( $entry[ $field->id . '.3' ] ) ) {
                                                $name_parts[] = $entry[ $field->id . '.3' ];
                                            }
                                            if ( ! empty( $entry[ $field->id . '.6' ] ) ) {
                                                $name_parts[] = $entry[ $field->id . '.6' ];
                                            }
                                            echo esc_html( implode( ' ', $name_parts ) );
                                        } else {
                                            echo esc_html( isset( $entry[ $field->id ] ) ? $entry[ $field->id ] : '' );
                                        }
                                        ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $entry['date_created'] ) ) ); ?></td>
                            <td>
                                <a href="#" class="woo-gf-view-entry button button-small" 
                                   data-entry-id="<?php echo esc_attr( $entry['id'] ); ?>"
                                   data-form-id="<?php echo esc_attr( $form_id ); ?>">
                                    <?php esc_html_e( 'צפה', 'woo-gf-integration' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry['id'] ) ); ?>" 
                                   class="button button-small" target="_blank">
                                    <?php esc_html_e( 'ערוך', 'woo-gf-integration' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_count > $per_page ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf( _n( '%s פריט', '%s פריטים', $total_count, 'woo-gf-integration' ), number_format_i18n( $total_count ) ); ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        $total_pages = ceil( $total_count / $per_page );
                        
                        if ( $page > 1 ) {
                            echo '<a class="prev-page button" href="#" data-page="' . ( $page - 1 ) . '">‹</a> ';
                        }
                        
                        echo '<span class="paging-input">';
                        printf( __( 'עמוד %1$s מתוך %2$s', 'woo-gf-integration' ), $page, $total_pages );
                        echo '</span> ';
                        
                        if ( $page < $total_pages ) {
                            echo '<a class="next-page button" href="#" data-page="' . ( $page + 1 ) . '">›</a>';
                        }
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <?php
        $html = ob_get_clean();

        wp_send_json_success( array(
            'html' => $html,
            'total' => $total_count,
            'pages' => ceil( $total_count / $per_page ),
        ) );
    }

    /**
     * Get entry details
     */
    public function get_entry_details() {
        // Check nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'at_woo_gf_integration_nonce' ) ) {
            wp_die( __( 'Security check failed', 'at-woo-gf-integration' ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( __( 'Insufficient permissions', 'woo-gf-integration' ) );
        }

        $entry_id = isset( $_POST['entry_id'] ) ? intval( $_POST['entry_id'] ) : 0;
        $form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;

        if ( ! $entry_id || ! $form_id ) {
            wp_send_json_error( __( 'Invalid request', 'woo-gf-integration' ) );
        }

        // Get entry
        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) ) {
            wp_send_json_error( $entry->get_error_message() );
        }

        // Get form
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) {
            wp_send_json_error( __( 'Form not found', 'woo-gf-integration' ) );
        }

        // Build response HTML
        ob_start();
        ?>
        <div class="woo-gf-entry-details">
            <h3><?php printf( __( 'הרשמה #%d', 'woo-gf-integration' ), $entry_id ); ?></h3>
            <p><strong><?php esc_html_e( 'תאריך:', 'woo-gf-integration' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $entry['date_created'] ) ) ); ?></p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'שדה', 'woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'ערך', 'woo-gf-integration' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $form['fields'] as $field ) : ?>
                        <?php 
                        $value = $this->get_field_value( $entry, $field );
                        if ( ! empty( $value ) ) :
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( $field->label ); ?></strong></td>
                                <td><?php echo wp_kses_post( $value ); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * Get field value from entry
     */
    private function get_field_value( $entry, $field ) {
        $value = '';

        switch ( $field->type ) {
            case 'name':
                $name_parts = array();
                if ( ! empty( $entry[ $field->id . '.3' ] ) ) {
                    $name_parts[] = $entry[ $field->id . '.3' ];
                }
                if ( ! empty( $entry[ $field->id . '.6' ] ) ) {
                    $name_parts[] = $entry[ $field->id . '.6' ];
                }
                $value = implode( ' ', $name_parts );
                break;

            case 'address':
                $address_parts = array();
                for ( $i = 1; $i <= 6; $i++ ) {
                    if ( ! empty( $entry[ $field->id . '.' . $i ] ) ) {
                        $address_parts[] = $entry[ $field->id . '.' . $i ];
                    }
                }
                $value = implode( ', ', $address_parts );
                break;

            case 'checkbox':
            case 'multiselect':
                $values = array();
                foreach ( $field->inputs as $input ) {
                    if ( ! empty( $entry[ $input['id'] ] ) ) {
                        $values[] = $entry[ $input['id'] ];
                    }
                }
                $value = implode( ', ', $values );
                break;

            case 'fileupload':
                if ( ! empty( $entry[ $field->id ] ) ) {
                    $files = json_decode( $entry[ $field->id ], true );
                    if ( is_array( $files ) ) {
                        $links = array();
                        foreach ( $files as $file ) {
                            $links[] = '<a href="' . esc_url( $file ) . '" target="_blank">' . basename( $file ) . '</a>';
                        }
                        $value = implode( '<br>', $links );
                    } else {
                        $value = '<a href="' . esc_url( $entry[ $field->id ] ) . '" target="_blank">' . basename( $entry[ $field->id ] ) . '</a>';
                    }
                }
                break;

            default:
                $value = isset( $entry[ $field->id ] ) ? $entry[ $field->id ] : '';
                break;
        }

        return $value;
    }

    /**
     * Create a new form and associate it with product
     */
    public function create_form() {
        check_ajax_referer( 'haruv_event_gf_nonce', 'security' );

        if ( ! current_user_can( 'edit_products' ) || ! isset( $_POST['product_id'] ) ) {
            wp_send_json_error( [ 'message' => __( 'אין לך הרשאה לבצע פעולה זו.', 'woo-gf-integration' ) ] );
        }

        if ( ! class_exists( 'GFAPI' ) ) {
            wp_send_json_error( [ 'message' => __( 'Gravity Forms אינו מותקן או פעיל.', 'woo-gf-integration' ) ] );
        }

        $product_id = intval( $_POST['product_id'] );
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [ 'message' => __( 'מוצר לא נמצא.', 'woo-gf-integration' ) ] );
        }

        $form_title = $product->get_name();
        
        // Basic form structure
        $form = [
            'title'        => $form_title,
            'description'  => sprintf( __( 'טופס הרשמה לאירוע: %s', 'woo-gf-integration' ), $form_title ),
            'labelPlacement' => 'top_label',
            'button'       => [
                'type' => 'text',
                'text' => __( 'שלח הרשמה', 'woo-gf-integration' ),
            ],
            'fields'       => [
                [ 'type' => 'name', 'label' => __( 'שם מלא', 'woo-gf-integration' ), 'isRequired' => true, 'inputs' => [
                    [ 'id' => '1.3', 'label' => __( 'שם פרטי', 'woo-gf-integration' ) ],
                    [ 'id' => '1.6', 'label' => __( 'שם משפחה', 'woo-gf-integration' ) ],
                ]],
                [ 'type' => 'email', 'label' => __( 'כתובת אימייל', 'woo-gf-integration' ), 'isRequired' => true ],
                [ 'type' => 'phone', 'label' => __( 'טלפון', 'woo-gf-integration' ), 'isRequired' => true ],
            ],
        ];

        // If product has stock management, set form limit
        if ( $product->get_manage_stock() ) {
            $stock_quantity = $product->get_stock_quantity();
            if ( $stock_quantity > 0 ) {
                $form['limitEntries'] = true;
                $form['limitEntriesCount'] = $stock_quantity;
                $form['limitEntriesMessage'] = __( 'מצטערים, ההרשמה לאירוע זה מלאה.', 'woo-gf-integration' );
            }
        }

        $form_id = GFAPI::add_form( $form );

        if ( is_wp_error( $form_id ) ) {
            wp_send_json_error( [ 'message' => $form_id->get_error_message() ] );
        }

        // Link the new form to the product using the correct meta key
        update_post_meta( $product_id, '_woo_gf_form_id', $form_id );
        $product->update_meta_data( '_woo_gf_form_id', $form_id );
        $product->save();


        wp_send_json_success( [
            'message' => __( 'הטופס נוצר וקושר בהצלחה!', 'woo-gf-integration' ),
            'form_id' => $form_id,
            'form_title' => $form_title,
            'edit_url' => admin_url( 'admin.php?page=gf_edit_forms&id=' . $form_id ),
        ] );
    }
} 