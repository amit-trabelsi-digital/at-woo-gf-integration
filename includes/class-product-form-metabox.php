<?php
/**
 * Product Form Metabox Class
 * 
 * @package WooGFIntegration
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class to handle product form metabox
 */
class Woo_GF_Product_Form_Metabox {

    /**
     * Instance of this class.
     *
     * @var Woo_GF_Product_Form_Metabox
     */
    private static $instance = null;

    /**
     * Get the singleton instance of this class.
     *
     * @return Woo_GF_Product_Form_Metabox
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
        // Remove old plugin tabs to prevent conflicts
        add_action( 'admin_init', array( $this, 'remove_old_plugin_tabs' ), 1 );
        
        // Add metabox
        add_action( 'add_meta_boxes', array( $this, 'add_product_form_metabox' ) );
        
        // Save metabox data
        add_action( 'save_post_product', array( $this, 'save_product_form_data' ), 10, 3 );
        
        // Add tab to product data metabox
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'add_product_data_panel' ) );
        
        // Hook into form submission to save product ID
        add_action( 'gform_after_submission', array( $this, 'save_product_id_to_entry' ), 10, 2 );

        // Add event icon to product title
        add_action( 'edit_form_top', array( $this, 'add_event_icon_to_title' ) );
    }

    /**
     * Remove old woocommerce-gravityforms-product-addons plugin tabs to prevent conflicts
     */
    public function remove_old_plugin_tabs() {
        // Check if old plugin is active
        if ( class_exists( 'WC_GFPA_Admin_Controller' ) ) {
            // Add CSS and JS to hide the old tab
            add_action( 'admin_head', array( $this, 'hide_old_plugin_tab' ) );
        }
    }
    
    /**
     * Hide old plugin tab with CSS and JavaScript
     */
    public function hide_old_plugin_tab() {
        global $post;
        
        // Only on product edit page
        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }
        
        ?>
        <style type="text/css">
            /* הסתר את הטאב הישן של woocommerce-gravityforms-product-addons */
            .wc-tabs li.gravityforms_addons_tab,
            .wc-tabs li.gravityforms_addons,
            #gravityforms_addons_data {
                display: none !important;
            }
        </style>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // הסר את הטאב הישן מה-DOM לגמרי
                $('.wc-tabs li.gravityforms_addons_tab, .wc-tabs li.gravityforms_addons').remove();
                $('#gravityforms_addons_data').remove();
                
                // אם הטאב הישן היה פעיל, עבור לטאב החדש שלנו או ל-General
                if ($('.wc-tabs li.gravityforms_addons_tab').hasClass('active') || 
                    $('.wc-tabs li.gravityforms_addons').hasClass('active')) {
                    if ($('.wc-tabs li.gravity_forms_options').length) {
                        $('.wc-tabs li.gravity_forms_options a').click();
                    } else {
                        $('.wc-tabs li:first a').click();
                    }
                }
            });
        </script>
        <?php
    }

    /**
     * Add event icon to product title in edit screen
     */
    public function add_event_icon_to_title( $post ) {
        if ( 'product' !== $post->post_type ) {
            return;
        }

        $product = wc_get_product( $post->ID );
        if ( $product && $product->is_type( 'event' ) ) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('h1.wp-heading-inline').each(function() {
                        if ( ! $(this).find('.event-icon').length ) {
                            $(this).prepend('<span class="dashicons dashicons-calendar-alt event-icon" style="color: #9b59b6; font-size: 30px; vertical-align: middle; margin-right: 10px;" title="<?php esc_attr_e( 'מוצר מסוג אירוע', 'at-woo-gf-integration' ); ?>"></span>');
                        }
                    });
                });
            </script>
            <?php
        }
    }

    /**
     * Add metabox to product edit page
     */
    public function add_product_form_metabox() {
        add_meta_box(
            'woo_gf_product_form_entries',
            __( 'הרשמות טופס Gravity Forms', 'at-woo-gf-integration' ),
            array( $this, 'render_entries_metabox' ),
            'product',
            'normal',
            'default'
        );
    }

    /**
     * Add tab to product data metabox
     */
    public function add_product_data_tab( $tabs ) {
        $tabs['gravity_forms'] = array(
            'label'    => __( 'Gravity Forms', 'at-woo-gf-integration' ),
            'target'   => 'gravity_forms_product_data',
            'class'    => array(),
            'priority' => 80,
        );
        return $tabs;
    }

    /**
     * Add panel content to product data metabox
     */
    public function add_product_data_panel() {
        global $post;

        // Add a nonce field for security
        wp_nonce_field( 'haruv_event_gf_nonce', 'haruv_event_gf_nonce_field' );

        ?>
        <div id="gravity_forms_product_data" class="panel woocommerce_options_panel" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
            <div class="options_group">
                <?php
                // Get saved form ID
                $product = wc_get_product( $post->ID );
                $selected_form = $product ? $product->get_meta( '_woo_gf_form_id', true ) : '';

                // No form is auto-selected. A new event product starts with an empty
                // select and the site manager must explicitly pick an existing form or
                // press "צור טופס חדש", which duplicates the *default* form chosen in
                // "דשבורד הרשמות → הגדרות טפסים". That default form is deliberately NOT
                // pre-selected here, because pre-selecting it silently linked every new
                // event to the same shared form. See HRV-E5, HRV-F137 and
                // AT_Woo_GF_Event_Form_Template::get_template_form_id().
                //
                // Products that already have `_woo_gf_form_id` saved keep their value —
                // it is read straight from meta above and never overridden.

                // Get all forms
                $forms = GFAPI::get_forms();
                
                woocommerce_wp_select( array(
                    'id'          => '_woo_gf_form_id',
                    'label'       => '<span class="dashicons dashicons-forms"></span> ' . __( 'בחר טופס', 'at-woo-gf-integration' ),
                    'description' => __( 'בחר טופס Gravity Forms לשייך למוצר זה', 'at-woo-gf-integration' ),
                    'desc_tip'    => true,
                    'value'       => $selected_form,
                    'options'     => $this->get_forms_options( $forms ),
                ) );
                ?>
                
                <p class="form-field">
                    <button type="button" class="button" id="woo_gf_view_entries">
                        <span class="dashicons dashicons-visibility" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e( 'צפה בהרשמות', 'at-woo-gf-integration' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="woo_gf_create_form">
                        <span class="dashicons dashicons-plus-alt" style="vertical-align: text-bottom;"></span>
                        <?php 
                        echo esc_html( 
                            $selected_form ? 
                            __( 'החלף בטופס חדש', 'at-woo-gf-integration' ) : 
                            __( 'צור טופס חדש', 'at-woo-gf-integration' ) 
                        ); 
                        ?>
                    </button>
                    <?php if ( $selected_form ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms&id=' . $selected_form ) ); ?>" 
                           class="button" target="_blank">
                            <span class="dashicons dashicons-edit" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e( 'ערוך טופס', 'at-woo-gf-integration' ); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>

            <!-- תזמון וסטטוס טופס -->
            <div class="options_group" id="woo_gf_form_schedule_settings">
                <h4 style="margin: 15px 12px 10px; font-size: 13px; color: #23282d;">
                    <span class="dashicons dashicons-clock" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e( 'תזמון וסטטוס טופס', 'at-woo-gf-integration' ); ?>
                </h4>
                
                <?php
                // Get form scheduling data.
                // The product post meta is the authoritative source for this admin UI
                // (it is written on every product save). The linked Gravity Forms form is
                // only a fallback source. Therefore the saved schedule values must be read
                // unconditionally and must NOT be gated behind the presence of the GF form
                // object: if the linked form was deleted, replaced, trashed, or GFAPI is
                // unavailable, the previously-saved dates would otherwise fail to load into
                // the edit fields. See HRV-C8.
                $form = $selected_form ? GFAPI::get_form( $selected_form ) : null;

                // Form active status: prefer the live GF form, then saved meta, else active.
                $meta_is_active = get_post_meta( $post->ID, '_woo_gf_form_is_active', true );
                if ( $form ) {
                    $is_form_active = ! isset( $form['is_active'] ) || $form['is_active'] !== false;
                } elseif ( '' !== $meta_is_active ) {
                    $is_form_active = 'yes' === $meta_is_active;
                } else {
                    $is_form_active = true;
                }

                // Schedule enabled: prefer saved meta, fall back to the GF form flag.
                $meta_schedule_enabled = get_post_meta( $post->ID, '_woo_gf_enable_form_schedule', true );
                if ( '' !== $meta_schedule_enabled ) {
                    $schedule_enabled = 'yes' === $meta_schedule_enabled;
                } else {
                    $schedule_enabled = $form && ! empty( $form['scheduleForm'] );
                }

                // Schedule start/end dates: prefer saved meta, fall back to the GF form values.
                $meta_start = get_post_meta( $post->ID, '_woo_gf_schedule_start', true );
                $schedule_start = ! empty( $meta_start ) ? $meta_start : ( $form && isset( $form['scheduleStart'] ) ? $form['scheduleStart'] : '' );

                $meta_end = get_post_meta( $post->ID, '_woo_gf_schedule_end', true );
                $schedule_end = ! empty( $meta_end ) ? $meta_end : ( $form && isset( $form['scheduleEnd'] ) ? $form['scheduleEnd'] : '' );
                
                woocommerce_wp_checkbox( array(
                    'id'          => '_woo_gf_form_is_active',
                    'label'       => __( 'הטופס פעיל', 'at-woo-gf-integration' ),
                    'description' => __( 'סמן כדי שהטופס יהיה זמין להרשמות. בטל סימון כדי לסגור את הטופס ידנית', 'at-woo-gf-integration' ),
                    'desc_tip'    => true,
                    'value'       => $is_form_active ? 'yes' : 'no',
                    'cbvalue'     => 'yes',
                ) );
                
                woocommerce_wp_checkbox( array(
                    'id'          => '_woo_gf_enable_form_schedule',
                    'label'       => __( 'הפעל תזמון אוטומטי', 'at-woo-gf-integration' ),
                    'description' => __( 'הגדר תאריכי פתיחה וסגירה אוטומטית של הטופס', 'at-woo-gf-integration' ),
                    'desc_tip'    => true,
                    'value'       => $schedule_enabled ? 'yes' : 'no',
                    'cbvalue'     => 'yes',
                ) );
                
                woocommerce_wp_text_input( array(
                    'id'          => '_woo_gf_schedule_start',
                    'label'       => '<span class="dashicons dashicons-unlock"></span> ' . __( 'תאריך פתיחה', 'at-woo-gf-integration' ),
                    'description' => __( 'הטופס ייפתח אוטומטית בתאריך ושעה זו', 'at-woo-gf-integration' ),
                    'desc_tip'    => true,
                    'type'        => 'datetime-local',
                    'value'       => $schedule_start ? date('Y-m-d\TH:i', strtotime($schedule_start)) : '',
                    'wrapper_class' => 'show_if_schedule_enabled',
                ) );
                
                woocommerce_wp_text_input( array(
                    'id'          => '_woo_gf_schedule_end',
                    'label'       => '<span class="dashicons dashicons-lock"></span> ' . __( 'תאריך סגירה', 'at-woo-gf-integration' ),
                    'description' => __( 'הטופס ייסגר אוטומטית בתאריך ושעה זו', 'at-woo-gf-integration' ),
                    'desc_tip'    => true,
                    'type'        => 'datetime-local',
                    'value'       => $schedule_end ? date('Y-m-d\TH:i', strtotime($schedule_end)) : '',
                    'wrapper_class' => 'show_if_schedule_enabled',
                ) );
                ?>
                
                <p class="form-field" style="margin: 10px 12px; padding: 10px; background: #f0f6fc; border-right: 4px solid #0073aa;">
                    <strong><span class="dashicons dashicons-info" style="color: #0073aa;"></span> <?php esc_html_e( 'חשוב לדעת:', 'at-woo-gf-integration' ); ?></strong><br>
                    <small><?php esc_html_e( 'השינויים האלה ישפיעו ישירות על הטופס ב-Gravity Forms. לאחר שמירה, הטופס יתעדכן אוטומטית עם ההגדרות החדשות.', 'at-woo-gf-integration' ); ?></small>
                </p>
                
                <style>
                    .show_if_schedule_enabled { display: none; }
                    #woo_gf_form_schedule_settings .dashicons {
                        font-size: 16px;
                        width: 16px;
                        height: 16px;
                        vertical-align: text-bottom;
                        margin-left: 4px;
                    }
                </style>
                <script>
                    jQuery(document).ready(function($) {
                        function toggleScheduleFields() {
                            if ($('#_woo_gf_enable_form_schedule').is(':checked')) {
                                $('.show_if_schedule_enabled').show();
                            } else {
                                $('.show_if_schedule_enabled').hide();
                            }
                        }
                        
                        // Show/hide on page load
                        toggleScheduleFields();
                        
                        // Toggle on checkbox change
                        $('#_woo_gf_enable_form_schedule').on('change', toggleScheduleFields);
                        
                        // Show/hide entire schedule section if no form is selected
                        function toggleScheduleSection() {
                            var selectedForm = $('#_woo_gf_form_id').val();
                            if (selectedForm && selectedForm !== '') {
                                $('#woo_gf_form_schedule_settings').show();
                            } else {
                                $('#woo_gf_form_schedule_settings').hide();
                            }
                        }
                        
                        toggleScheduleSection();
                        $('#_woo_gf_form_id').on('change', toggleScheduleSection);
                    });
                </script>
            </div>

            <div class="options_group">
                <h4 style="margin-bottom: 10px;"><?php esc_html_e( 'מעקב הרשמות', 'at-woo-gf-integration' ); ?></h4>
                <?php
                woocommerce_wp_checkbox( array(
                    'id'          => '_woo_gf_enable_registration_email',
                    'label'       => __( 'הפעל שליחת עדכון הרשמות במייל', 'at-woo-gf-integration' ),
                    'description' => __( 'שלח מייל תקופתי עם קובץ CSV של הנרשמים', 'at-woo-gf-integration' ),
                    'desc_tip'    => true,
                    'value'       => $product->get_meta( '_woo_gf_enable_registration_email', true ),
                ) );

                woocommerce_wp_select( array(
                    'id'          => '_woo_gf_email_frequency',
                    'label'       => __( 'תדירות שליחה', 'at-woo-gf-integration' ),
                    'options'     => array(
                        'hourly'  => __( 'שעתי', 'at-woo-gf-integration' ),
                        'daily'   => __( 'יומי', 'at-woo-gf-integration' ),
                        'weekly'  => __( 'שבועי', 'at-woo-gf-integration' ),
                        'monthly' => __( 'חודשי', 'at-woo-gf-integration' ),
                    ),
                    'value'       => $product->get_meta( '_woo_gf_email_frequency', true ),
                    'wrapper_class' => 'show_if_registration_email_enabled',
                ) );

                woocommerce_wp_text_input( array(
                    'id'          => '_woo_gf_notification_email',
                    'label'       => __( 'כתובת מייל לקבלת העדכון', 'at-woo-gf-integration' ),
                    'placeholder' => 'email@example.com',
                    'type'        => 'email',
                    'value'       => $product->get_meta( '_woo_gf_notification_email', true ),
                    'wrapper_class' => 'show_if_registration_email_enabled',
                ) );
                ?>
                 <style>
                    .show_if_registration_email_enabled { display: none; }
                </style>
                <script>
                    jQuery(document).ready(function($) {
                        function toggleEmailFields() {
                            if ($('#_woo_gf_enable_registration_email').is(':checked')) {
                                $('.show_if_registration_email_enabled').show();
                            } else {
                                $('.show_if_registration_email_enabled').hide();
                            }
                        }
                        toggleEmailFields();
                        $('#_woo_gf_enable_registration_email').on('change', toggleEmailFields);
                    });
                </script>
            </div>
            
            <?php
            // Show event-specific information
            if ( function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $post->ID );
                if ( $product && $product->is_type( 'event' ) ) {
                    ?>
                    <div class="options_group">
                        <p class="form-field">
                            <strong><span class="dashicons dashicons-calendar" style="color: #0073aa;"></span> <?php esc_html_e( 'פרטי אירוע:', 'at-woo-gf-integration' ); ?></strong><br>
                            <?php
                            // Typed CRUD getters — $product->get_meta( '_event_*' ) is not
                            // reliable for these keys, they are WC_Product_Event props and are
                            // stripped from meta_data by the data store. See HRV-DOUBLE-SAVE.
                            $event_date = $product->get_event_date( 'edit' );
                            $event_location = $product->get_event_location( 'edit' );
                            $max_attendees = $product->get_max_attendees( 'edit' );
                            
                            if ( $event_date ) {
                                echo '<span class="dashicons dashicons-calendar-alt" style="color: #646970;"></span> ';
                                echo esc_html( date_i18n( 'j בF Y בשעה H:i', strtotime( $event_date ) ) ) . '<br>';
                            }
                            
                            if ( $event_location ) {
                                echo '<span class="dashicons dashicons-location" style="color: #646970;"></span> ';
                                echo esc_html( $event_location ) . '<br>';
                            }
                            
                            if ( $max_attendees > 0 && $selected_form ) {
                                // Count ALL entries (not just active) for this product.
                                // Entries live on the canonical translation, so every
                                // language's edit screen shows the shared total.
                                $search_criteria_with_meta = array(
                                    'field_filters' => array(
                                        array(
                                            'key'   => 'woo_gf_product_id',
                                            'value' => $this->canonical_product_id( $post->ID ),
                                        ),
                                    ),
                                );
                                
                                // Try counting with meta first
                                $entry_count = GFAPI::count_entries( $selected_form, $search_criteria_with_meta );
                                
                                // If no entries found with meta, count ALL entries from the form
                                if ( $entry_count == 0 ) {
                                    $entry_count = GFAPI::count_entries( $selected_form, array() );
                                }
                                $available = $max_attendees - $entry_count;
                                $percentage = ( $entry_count / $max_attendees ) * 100;
                                
                                echo '<span class="dashicons dashicons-groups" style="color: #646970;"></span> ';
                                echo sprintf( 
                                    esc_html__( '%1$d/%2$d משתתפים רשומים (%3$s%% תפוסה)', 'at-woo-gf-integration' ),
                                    $entry_count,
                                    $max_attendees,
                                    round( $percentage, 1 )
                                );
                                
                                if ( $available <= 5 && $available > 0 ) {
                                    echo '<br><span style="color: #d63638;"><span class="dashicons dashicons-warning"></span> ';
                                    echo sprintf( esc_html__( 'נותרו %d מקומות בלבד!', 'at-woo-gf-integration' ), $available );
                                    echo '</span>';
                                } elseif ( $available <= 0 ) {
                                    echo '<br><span style="color: #d63638;"><span class="dashicons dashicons-no"></span> ';
                                    echo esc_html__( 'האירוע מלא', 'at-woo-gf-integration' );
                                    echo '</span>';
                                }
                            }
                            ?>
                        </p>
                    </div>
                    <?php
                }
            }
            ?>
        </div>
        <?php
    }

    /**
     * Get forms options for select field
     */
    private function get_forms_options( $forms ) {
        $options = array( '' => __( '-- בחר טופס --', 'at-woo-gf-integration' ) );
        
        if ( ! empty( $forms ) ) {
            foreach ( $forms as $form ) {
                $options[ $form['id'] ] = $form['title'];
            }
        }
        
        return $options;
    }

    /**
     * Render entries metabox
     */
    public function render_entries_metabox( $post ) {
        // Get saved form ID
        $product = wc_get_product( $post->ID );
        $form_id = $product ? $product->get_meta( '_woo_gf_form_id', true ) : '';
        
        if ( empty( $form_id ) ) {
            echo '<p>' . esc_html__( 'לא נבחר טופס עבור מוצר זה. בחר טופס בכרטיסיית Gravity Forms בתיבת נתוני המוצר.', 'at-woo-gf-integration' ) . '</p>';
            return;
        }
        
        // Get form
        $form = GFAPI::get_form( $form_id );
        
        if ( ! $form ) {
            echo '<p>' . esc_html__( 'הטופס שנבחר לא נמצא.', 'at-woo-gf-integration' ) . '</p>';
            return;
        }
        
        echo '<h4>' . sprintf( esc_html__( 'הרשמות לטופס: %s', 'at-woo-gf-integration' ), esc_html( $form['title'] ) ) . '</h4>';
        
        // Container for entries table
        echo '<div id="woo_gf_entries_container" data-form-id="' . esc_attr( $form_id ) . '" data-product-id="' . esc_attr( $post->ID ) . '">';
        echo '<p>' . esc_html__( 'טוען הרשמות...', 'at-woo-gf-integration' ) . '</p>';
        echo '</div>';
    }

    /**
     * Save product form data
     */
    public function save_product_form_data( $post_id, $post, $update ) {
        // Check if our nonce is set
        if ( ! isset( $_POST['woocommerce_meta_nonce'] ) ) {
            return;
        }

        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
            return;
        }

        // Check if user has permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Check autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Save form ID
        if ( isset( $_POST['_woo_gf_form_id'] ) ) {
            $old_form_id = get_post_meta( $post_id, '_woo_gf_form_id', true );
            $new_form_id = sanitize_text_field( $_POST['_woo_gf_form_id'] );

            // Update product meta
            update_post_meta( $post_id, '_woo_gf_form_id', $new_form_id );

            // Update form meta
            if ( class_exists( 'GFAPI' ) ) {
                // If there was an old form, remove the link from it
                if ( ! empty( $old_form_id ) && $old_form_id !== $new_form_id ) {
                    $old_form = GFAPI::get_form( $old_form_id );
                    if ( $old_form ) {
                        $old_form['woo_gf_linked_product_id'] = '';
                        GFAPI::update_form( $old_form );
                    }
                }
                // If a new form is selected, add the link to it
                if ( ! empty( $new_form_id ) ) {
                    $new_form = GFAPI::get_form( $new_form_id );
                    if ( $new_form ) {
                        $new_form['woo_gf_linked_product_id'] = $post_id;
                        GFAPI::update_form( $new_form );
                    }
                }

                // Save form schedule settings.
                //
                // The product post meta is the authoritative source for this admin UI, so
                // it is written unconditionally. Only the mirroring into the Gravity Forms
                // form object is gated behind the form actually existing — otherwise a
                // deleted/trashed form (or GFAPI being unavailable) silently drops the
                // admin's schedule settings on every save. Counterpart of the read-side
                // fix in add_product_data_panel(). See HRV-C8.
                if ( ! empty( $new_form_id ) ) {
                    $form = GFAPI::get_form( $new_form_id );

                    // Form status (active/inactive).
                    $is_active = isset( $_POST['_woo_gf_form_is_active'] );
                    update_post_meta( $post_id, '_woo_gf_form_is_active', $is_active ? 'yes' : 'no' );

                    // Schedule settings.
                    $schedule_enabled = isset( $_POST['_woo_gf_enable_form_schedule'] );
                    update_post_meta( $post_id, '_woo_gf_enable_form_schedule', $schedule_enabled ? 'yes' : 'no' );

                    $start_val = isset( $_POST['_woo_gf_schedule_start'] )
                        ? str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST['_woo_gf_schedule_start'] ) ) )
                        : null;
                    $end_val = isset( $_POST['_woo_gf_schedule_end'] )
                        ? str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST['_woo_gf_schedule_end'] ) ) )
                        : null;

                    if ( $schedule_enabled ) {
                        if ( null !== $start_val ) {
                            update_post_meta( $post_id, '_woo_gf_schedule_start', $start_val );
                        }
                        if ( null !== $end_val ) {
                            update_post_meta( $post_id, '_woo_gf_schedule_end', $end_val );
                        }
                    }

                    if ( $form ) {
                        $form['is_active']    = $is_active;
                        $form['scheduleForm'] = $schedule_enabled;

                        if ( $schedule_enabled ) {
                            if ( null !== $start_val ) {
                                $form['scheduleStart'] = $start_val;
                            }
                            if ( null !== $end_val ) {
                                $form['scheduleEnd'] = $end_val;
                            }
                            // Default message if not set
                            if ( empty( $form['scheduleMessage'] ) ) {
                                $form['scheduleMessage'] = __( 'מצטערים, ההרשמה לאירוע זה נסגרה.', 'at-woo-gf-integration' );
                            }
                        }

                        GFAPI::update_form( $form );
                    }
                }
            }
        }
        
        // Save registration tracking settings
        if ( isset( $_POST['_woo_gf_enable_registration_email'] ) || isset( $_POST['_woo_gf_form_id'] ) ) {
            $enable_email = isset( $_POST['_woo_gf_enable_registration_email'] ) ? 'yes' : 'no';
            update_post_meta( $post_id, '_woo_gf_enable_registration_email', $enable_email );

            if ( 'yes' === $enable_email ) {
                if ( isset( $_POST['_woo_gf_email_frequency'] ) ) {
                    update_post_meta( $post_id, '_woo_gf_email_frequency', sanitize_text_field( $_POST['_woo_gf_email_frequency'] ) );
                }
                if ( isset( $_POST['_woo_gf_notification_email'] ) ) {
                    update_post_meta( $post_id, '_woo_gf_notification_email', sanitize_email( $_POST['_woo_gf_notification_email'] ) );
                }
            }
        }
    }

    /**
     * Save product ID to entry meta when form is submitted
     *
     * The stored ID is normalised to the translation group's canonical product
     * ( woo_gf_get_canonical_product_id() ), so a registration made on the
     * Arabic or English page counts against the same capacity pool as the
     * Hebrew one. The Polylang language the form was actually submitted in is
     * stamped separately on `woo_gf_entry_lang` so notifications can still be
     * sent in the registrant's own language.
     */
    public function save_product_id_to_entry( $entry, $form ) {
        // Check if we're on a product page
        if ( is_product() ) {
            global $post;

            if ( $post && 'product' === $post->post_type ) {
                // Check if this form is associated with the current product
                $product = wc_get_product( $post->ID );
                $form_id = $product ? $product->get_meta( '_woo_gf_form_id', true ) : '';

                if ( $form_id == $form['id'] ) {
                    // Save product ID to entry meta
                    gform_update_meta( $entry['id'], 'woo_gf_product_id', $this->canonical_product_id( $post->ID ) );
                    $this->save_entry_language( $entry );
                }
            }
        }

        // Also check if form was submitted via AJAX with product ID parameter
        if ( isset( $_POST['woo_gf_product_id'] ) ) {
            $product_id = intval( $_POST['woo_gf_product_id'] );

            // Verify this product has this form associated
            $product = wc_get_product( $product_id );
            $form_id = $product ? $product->get_meta( '_woo_gf_form_id', true ) : '';

            if ( $form_id == $form['id'] ) {
                gform_update_meta( $entry['id'], 'woo_gf_product_id', $this->canonical_product_id( $product_id ) );
                $this->save_entry_language( $entry );
            }
        }
    }

    /**
     * Normalise a product ID to its translation group's canonical product.
     *
     * Falls back to the raw ID when the waitlist include (which owns the
     * helper) is unavailable, so the metabox never fatals on its own.
     *
     * @param int $product_id Product ID.
     * @return int
     */
    private function canonical_product_id( $product_id ) {
        if ( function_exists( 'woo_gf_get_canonical_product_id' ) ) {
            return woo_gf_get_canonical_product_id( $product_id );
        }

        return absint( $product_id );
    }

    /**
     * Stamp the Polylang language the entry was submitted in.
     *
     * Consumed by the notification layer to reply in the registrant's language.
     * Stores the language slug ( he / en / ar ). No-op without Polylang.
     *
     * @param array $entry Gravity Forms entry.
     * @return void
     */
    private function save_entry_language( $entry ) {
        if ( empty( $entry['id'] ) || ! function_exists( 'pll_current_language' ) ) {
            return;
        }

        $lang = pll_current_language();

        if ( ! $lang ) {
            return;
        }

        gform_update_meta( $entry['id'], 'woo_gf_entry_lang', sanitize_key( $lang ) );
    }
} 