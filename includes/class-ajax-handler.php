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
            wp_die( __( 'Insufficient permissions', 'at-woo-gf-integration' ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
        $page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
        $per_page = 10;

        if ( ! $form_id ) {
            wp_send_json_error( __( 'Invalid form ID', 'at-woo-gf-integration' ) );
        }

        // Get form
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) {
            wp_send_json_error( __( 'Form not found', 'at-woo-gf-integration' ) );
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
                    <th><?php esc_html_e( 'מזהה', 'at-woo-gf-integration' ); ?></th>
                    <?php foreach ( $form['fields'] as $field ) : ?>
                        <?php if ( in_array( $field->type, array( 'name', 'email', 'phone', 'text' ) ) ) : ?>
                            <th><?php echo esc_html( $field->label ); ?></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <th><?php esc_html_e( 'תאריך', 'at-woo-gf-integration' ); ?></th>
                    <th><?php esc_html_e( 'פעולות', 'at-woo-gf-integration' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $entries ) ) : ?>
                    <tr>
                        <td colspan="<?php echo $column_count; ?>"><?php esc_html_e( 'לא נמצאו הרשמות', 'at-woo-gf-integration' ); ?></td>
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
                                    <?php esc_html_e( 'צפה', 'at-woo-gf-integration' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry['id'] ) ); ?>" 
                                   class="button button-small" target="_blank">
                                    <?php esc_html_e( 'ערוך', 'at-woo-gf-integration' ); ?>
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
                        <?php printf( _n( '%s פריט', '%s פריטים', $total_count, 'at-woo-gf-integration' ), number_format_i18n( $total_count ) ); ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        $total_pages = ceil( $total_count / $per_page );
                        
                        if ( $page > 1 ) {
                            echo '<a class="prev-page button" href="#" data-page="' . ( $page - 1 ) . '">‹</a> ';
                        }
                        
                        echo '<span class="paging-input">';
                        printf( __( 'עמוד %1$s מתוך %2$s', 'at-woo-gf-integration' ), $page, $total_pages );
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
            wp_die( __( 'Insufficient permissions', 'at-woo-gf-integration' ) );
        }

        $entry_id = isset( $_POST['entry_id'] ) ? intval( $_POST['entry_id'] ) : 0;
        $form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;

        if ( ! $entry_id || ! $form_id ) {
            wp_send_json_error( __( 'Invalid request', 'at-woo-gf-integration' ) );
        }

        // Get entry
        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) ) {
            wp_send_json_error( $entry->get_error_message() );
        }

        // Get form
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) {
            wp_send_json_error( __( 'Form not found', 'at-woo-gf-integration' ) );
        }

        // Build response HTML
        ob_start();
        ?>
        <div class="woo-gf-entry-details">
            <h3><?php printf( __( 'הרשמה #%d', 'at-woo-gf-integration' ), $entry_id ); ?></h3>
            <p><strong><?php esc_html_e( 'תאריך:', 'at-woo-gf-integration' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $entry['date_created'] ) ) ); ?></p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'שדה', 'at-woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'ערך', 'at-woo-gf-integration' ); ?></th>
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
     * Fallback Gravity Forms form id used as the duplication template.
     *
     * Kept for backwards compatibility only. The template form is now chosen by
     * the site manager in "דשבורד הרשמות → הגדרות טפסים" and stored in the
     * option `at_woo_gf_template_form_id`; this constant is merely the fallback
     * for a site that never opened that screen. See
     * AT_Woo_GF_Event_Form_Template::get_template_form_id().
     *
     * @var int
     */
    const TEMPLATE_FORM_ID = AT_Woo_GF_Event_Form_Template::LEGACY_DEFAULT_FORM_ID;

    /**
     * Resolve the id of the form that acts as the duplication template.
     *
     * @return int
     */
    private function get_template_form_id() {
        return AT_Woo_GF_Event_Form_Template::get_template_form_id();
    }

    /**
     * AJAX wrapper: duplicate the template form and link it to the product.
     *
     * The duplication itself lives in AT_Woo_GF_Event_Form_Template so that the
     * product editor (this endpoint) and the registrations dashboard share one
     * implementation. Everything below is transport: nonce, capabilities, input
     * sanitising and JSON shaping.
     */
    public function create_form() {
        check_ajax_referer( 'haruv_event_gf_nonce', 'security' );

        if ( ! isset( $_POST['product_id'] ) ) {
            wp_send_json_error( [ 'message' => __( 'לא צוין מוצר.', 'at-woo-gf-integration' ) ] );
        }

        $product_id = absint( $_POST['product_id'] );

        // Creating a Gravity Forms form is a privileged action: require the GF
        // create-form capability (or a full administrator), *and* the ability to
        // edit this specific product, since we write the link into its meta.
        if ( ! AT_Woo_GF_Event_Form_Template::current_user_can_create_forms() ) {
            wp_send_json_error( [ 'message' => __( 'אין לך הרשאה ליצור טפסים ב-Gravity Forms.', 'at-woo-gf-integration' ) ] );
        }

        if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( [ 'message' => __( 'אין לך הרשאה לערוך מוצר זה.', 'at-woo-gf-integration' ) ] );
        }

        // Requested title: the admin can override it from the UI; otherwise the
        // shared routine derives "הרשמה: <שם האירוע>". Never fall back to the
        // template's own title.
        $requested_title = isset( $_POST['form_title'] )
            ? sanitize_text_field( wp_unslash( $_POST['form_title'] ) )
            : '';

        // The product editor asks the admin to confirm before replacing an
        // existing form, so this entry point is allowed to overwrite the link.
        $result = AT_Woo_GF_Event_Form_Template::create_form_for_product(
            $product_id,
            array(
                'title' => $requested_title,
                'force' => true,
            )
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => wp_strip_all_tags( $result->get_error_message() ) ] );
        }

        wp_send_json_success( [
            'message'    => sprintf(
                /* translators: %d: template form id. */
                __( 'הטופס שוכפל מטופס ברירת המחדל (מספר %d) וקושר למוצר בהצלחה!', 'at-woo-gf-integration' ),
                $result['template_form_id']
            ),
            'form_id'    => $result['form_id'],
            'form_title' => $result['form_title'],
            'edit_url'   => $result['edit_url'],
        ] );
    }
}
