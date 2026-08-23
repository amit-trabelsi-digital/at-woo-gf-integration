<?php
/**
 * Event registration form template (default form) settings + duplication.
 *
 * Single source of truth for "which Gravity Forms form is copied when an event
 * gets its registration form". Until now that form was hard-coded (form #20)
 * and only overridable through a filter, which meant the site manager could not
 * change it without a developer. This class stores it in an option, exposes a
 * settings screen under the registrations dashboard, and owns the duplication
 * routine that both the product editor (AJAX) and the dashboard action use.
 *
 * @package WooGFIntegration
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AT_Woo_GF_Event_Form_Template
 */
class AT_Woo_GF_Event_Form_Template {

    /**
     * Option holding the id of the Gravity Forms form used as the template.
     *
     * @var string
     */
    const OPTION = 'at_woo_gf_template_form_id';

    /**
     * Settings group for the Settings API.
     *
     * @var string
     */
    const SETTINGS_GROUP = 'at_woo_gf_event_forms_group';

    /**
     * Admin page slug (submenu of the registrations dashboard).
     *
     * @var string
     */
    const PAGE_SLUG = 'at-woo-gf-event-forms';

    /**
     * Fallback template form id for sites that never opened the settings screen.
     *
     * Form #20 on the Haruv site is titled "טופס לברירת מחדל" and exists purely
     * to be copied. Keeping it as the fallback means this release changes nothing
     * for an existing install until an admin picks a different form.
     *
     * @var int
     */
    const LEGACY_DEFAULT_FORM_ID = 20;

    /**
     * Transient prefix for the one-shot admin notice after a redirect.
     *
     * @var string
     */
    const NOTICE_TRANSIENT = 'at_woo_gf_form_notice_';

    /**
     * Instance of this class.
     *
     * @var AT_Woo_GF_Event_Form_Template
     */
    private static $instance = null;

    /**
     * Get the singleton instance of this class.
     *
     * @return AT_Woo_GF_Event_Form_Template
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 20 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_at_woo_gf_create_event_form', array( $this, 'handle_create_form_request' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
    }

    /* ── Template form id ───────────────────────────────────────────────── */

    /**
     * Id of the form that is duplicated when a new event form is created.
     *
     * Resolution order: saved option → legacy hard-coded default (only when the
     * option was never saved) → `at_woo_gf_template_form_id` filter. An admin who
     * deliberately picks "לא נבחר" stores 0, and 0 is respected — it is not
     * silently replaced by the legacy default.
     *
     * @return int
     */
    public static function get_template_form_id() {
        $stored = get_option( self::OPTION, null );

        if ( null === $stored || '' === $stored ) {
            $form_id = self::LEGACY_DEFAULT_FORM_ID;
        } else {
            $form_id = absint( $stored );
        }

        /**
         * Filter the id of the form used as the duplication template.
         *
         * @param int $form_id Template form id (0 = none configured).
         */
        return (int) apply_filters( 'at_woo_gf_template_form_id', $form_id );
    }

    /**
     * Load the template form, or return a WP_Error explaining why it cannot be used.
     *
     * GFFormsModel::get_form() returns false for a missing form *and* for a
     * trashed one, which is exactly the gate we want: duplicating out of the
     * trash produces a form the admin cannot find in the forms list.
     *
     * @return object|WP_Error Form properties row on success.
     */
    public static function get_template_form_or_error() {
        if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GFFormsModel' ) ) {
            return new WP_Error(
                'gf_inactive',
                __( 'Gravity Forms אינו מותקן או פעיל, ולכן לא ניתן ליצור טפסים.', 'at-woo-gf-integration' )
            );
        }

        $template_form_id = self::get_template_form_id();

        if ( ! $template_form_id ) {
            return new WP_Error(
                'no_template',
                sprintf(
                    /* translators: %s: URL of the event forms settings page. */
                    __( 'לא הוגדר טופס ברירת מחדל לשכפול. בחר טופס במסך <a href="%s">הגדרות טפסי אירועים</a>.', 'at-woo-gf-integration' ),
                    esc_url( self::get_settings_url() )
                )
            );
        }

        $template_props = GFFormsModel::get_form( $template_form_id );

        if ( ! $template_props ) {
            return new WP_Error(
                'template_missing',
                sprintf(
                    /* translators: 1: Gravity Forms form id, 2: URL of the event forms settings page. */
                    __( 'טופס ברירת המחדל (מספר %1$d) לא נמצא או שהועבר לאשפה, ולכן לא ניתן לשכפל אותו. שחזר אותו ב-Gravity Forms או בחר טופס אחר במסך <a href="%2$s">הגדרות טפסי אירועים</a>.', 'at-woo-gf-integration' ),
                    $template_form_id,
                    esc_url( self::get_settings_url() )
                )
            );
        }

        return $template_props;
    }

    /**
     * URL of the settings screen.
     *
     * @return string
     */
    public static function get_settings_url() {
        return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
    }

    /* ── Duplication ────────────────────────────────────────────────────── */

    /**
     * Duplicate the template form, rename it, and link it to a product.
     *
     * Capability checks belong to the caller (AJAX handler / admin-post handler);
     * this method validates data only, so it can also be called from WP-CLI.
     *
     * @param int   $product_id Product (event) id.
     * @param array $args       {
     *     @type string $title Title for the new form. Default "הרשמה: <product>".
     *     @type bool   $force Replace an existing linked form. Default false.
     * }
     * @return array|WP_Error { form_id, form_title, edit_url, template_form_id }
     */
    public static function create_form_for_product( $product_id, $args = array() ) {
        $args = wp_parse_args(
            $args,
            array(
                'title' => '',
                'force' => false,
            )
        );

        $product_id = absint( $product_id );

        if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
            return new WP_Error( 'no_product', __( 'לא צוין מוצר תקין.', 'at-woo-gf-integration' ) );
        }

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return new WP_Error( 'no_product', __( 'מוצר לא נמצא.', 'at-woo-gf-integration' ) );
        }

        $template_props = self::get_template_form_or_error();

        if ( is_wp_error( $template_props ) ) {
            return $template_props;
        }

        $template_form_id = self::get_template_form_id();

        // Guard against a double click / page refresh creating a second form for
        // the same event. The product editor passes force = true, because there
        // the admin explicitly confirmed "החלף בטופס חדש".
        if ( ! $args['force'] ) {
            $existing_form_id = absint( $product->get_meta( '_woo_gf_form_id', true ) );

            if ( $existing_form_id && GFAPI::get_form( $existing_form_id ) ) {
                return new WP_Error(
                    'already_linked',
                    sprintf(
                        /* translators: 1: product name, 2: form id. */
                        __( 'לאירוע "%1$s" כבר מקושר טופס (מספר %2$d), ולכן לא נוצר טופס נוסף.', 'at-woo-gf-integration' ),
                        $product->get_name(),
                        $existing_form_id
                    ),
                    array( 'form_id' => $existing_form_id )
                );
            }
        }

        $requested_title = is_string( $args['title'] ) ? trim( $args['title'] ) : '';

        if ( '' === $requested_title ) {
            $product_name = trim( (string) $product->get_name() );

            if ( '' === $product_name ) {
                return new WP_Error(
                    'no_title',
                    __( 'יש להזין שם לטופס (או לתת שם לאירוע) לפני יצירת הטופס.', 'at-woo-gf-integration' )
                );
            }

            /**
             * Filter the auto-generated title of a new event registration form.
             *
             * @param string $title      Default title.
             * @param int    $product_id Product (event) id.
             */
            $requested_title = apply_filters(
                'at_woo_gf_new_form_title',
                sprintf(
                    /* translators: %s: event (product) name. */
                    __( 'הרשמה: %s', 'at-woo-gf-integration' ),
                    $product_name
                ),
                $product_id
            );
        }

        // GF's own routine copies fields, settings, notifications and confirmations.
        $new_form_id = GFFormsModel::duplicate_form( $template_form_id );

        if ( is_wp_error( $new_form_id ) ) {
            return new WP_Error(
                'duplicate_failed',
                sprintf(
                    /* translators: %s: error message from Gravity Forms. */
                    __( 'שכפול טופס ברירת המחדל נכשל: %s', 'at-woo-gf-integration' ),
                    $new_form_id->get_error_message()
                )
            );
        }

        if ( ! $new_form_id ) {
            return new WP_Error(
                'duplicate_failed',
                __( 'שכפול טופס ברירת המחדל נכשל מסיבה לא ידועה. נסה שוב או צור את הטופס ידנית ב-Gravity Forms.', 'at-woo-gf-integration' )
            );
        }

        $new_form = GFAPI::get_form( $new_form_id );

        if ( ! $new_form ) {
            return new WP_Error(
                'reload_failed',
                __( 'הטופס שוכפל אך לא ניתן היה לטעון אותו לצורך שינוי השם. בדוק את רשימת הטפסים ב-Gravity Forms.', 'at-woo-gf-integration' )
            );
        }

        $new_form['title']       = $requested_title;
        $new_form['description'] = sprintf(
            /* translators: %s: event (product) name. */
            __( 'טופס הרשמה לאירוע: %s', 'at-woo-gf-integration' ),
            $product->get_name()
        );

        // Point the copy at this product, and make sure it did not inherit the
        // template's own product link.
        $new_form['woo_gf_linked_product_id'] = $product_id;

        // Cap entries by the event capacity when stock is managed.
        if ( $product->get_manage_stock() ) {
            $stock_quantity = $product->get_stock_quantity();
            if ( $stock_quantity > 0 ) {
                $new_form['limitEntries']        = true;
                $new_form['limitEntriesCount']   = $stock_quantity;
                $new_form['limitEntriesMessage'] = __( 'מצטערים, ההרשמה לאירוע זה מלאה.', 'at-woo-gf-integration' );
            }
        }

        /**
         * Filter the duplicated form before it is saved.
         *
         * @param array $new_form   The duplicated Gravity Forms form array.
         * @param int   $product_id The product the form is being linked to.
         */
        $new_form = apply_filters( 'woo_gf_integration_new_form', $new_form, $product_id );

        $updated = GFAPI::update_form( $new_form );

        if ( is_wp_error( $updated ) ) {
            return new WP_Error(
                'update_failed',
                sprintf(
                    /* translators: %s: error message from Gravity Forms. */
                    __( 'הטופס שוכפל אך עדכון פרטיו נכשל: %s', 'at-woo-gf-integration' ),
                    $updated->get_error_message()
                ),
                array( 'form_id' => $new_form_id )
            );
        }

        // Read the title back — GF may have made it unique.
        $saved_form  = GFAPI::get_form( $new_form_id );
        $final_title = ( $saved_form && ! empty( $saved_form['title'] ) ) ? $saved_form['title'] : $requested_title;

        // Link the new form to the product (HPOS-safe CRUD).
        $product->update_meta_data( '_woo_gf_form_id', (string) $new_form_id );
        $product->save();

        // The dashboard caches its event lists in transients; a freshly linked
        // event must show up without waiting two minutes.
        self::flush_dashboard_caches();

        /**
         * Fires after a registration form has been created for a product.
         *
         * @param int $new_form_id The new form id.
         * @param int $product_id  The linked product id.
         */
        do_action( 'woo_gf_integration_form_created', $new_form_id, $product_id );

        return array(
            'form_id'          => (int) $new_form_id,
            'form_title'       => $final_title,
            'edit_url'         => admin_url( 'admin.php?page=gf_edit_forms&id=' . $new_form_id ),
            'template_form_id' => (int) $template_form_id,
        );
    }

    /**
     * Delete the dashboard's cached lists after a form link changes.
     */
    private static function flush_dashboard_caches() {
        global $wpdb;

        delete_transient( 'woo_gf_all_forms' );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_woo_gf_events_data_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_woo_gf_events_data_' ) . '%'
            )
        );
    }

    /* ── Events without a form ──────────────────────────────────────────── */

    /**
     * Event products that have no usable registration form.
     *
     * "No usable form" means: no `_woo_gf_form_id` meta, an empty one, or one
     * pointing at a form that was deleted/trashed in Gravity Forms — the last
     * case is the one that silently breaks registration, so it belongs here too.
     *
     * @param int $limit Maximum number of events to return.
     * @return array[] List of arrays: product_id, title, event_date, status, stale_form_id.
     */
    public static function get_events_without_form( $limit = 100 ) {
        static $cache = array();

        $limit = absint( $limit );

        if ( ! $limit ) {
            $limit = 100;
        }

        // The dashboard asks for the count (tab badge) and then for the list, in
        // the same request. One pass per limit is enough.
        if ( isset( $cache[ $limit ] ) ) {
            return $cache[ $limit ];
        }

        $query = new WP_Query(
            array(
                'post_type'              => 'product',
                'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                'posts_per_page'         => $limit,
                'fields'                 => 'ids',
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'tax_query'              => array(
                    array(
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => 'event',
                    ),
                ),
            )
        );

        $events = array();

        foreach ( $query->posts as $product_id ) {
            $form_id = absint( get_post_meta( $product_id, '_woo_gf_form_id', true ) );
            $form    = ( $form_id && class_exists( 'GFAPI' ) ) ? GFAPI::get_form( $form_id ) : false;

            if ( $form ) {
                continue;
            }

            $event_date = get_post_meta( $product_id, '_event_date', true );

            if ( ! $event_date ) {
                $event_date = get_post_meta( $product_id, '_event_start_date', true );
            }

            $events[] = array(
                'product_id'    => (int) $product_id,
                'title'         => get_the_title( $product_id ),
                'event_date'    => $event_date,
                'status'        => get_post_status( $product_id ),
                'stale_form_id' => $form_id,
            );
        }

        $cache[ $limit ] = $events;

        return $events;
    }

    /**
     * Count of event products without a usable form (for the dashboard tab badge).
     *
     * @return int
     */
    public static function get_events_without_form_count() {
        // Same limit as the table below it, so both share one pass over the data.
        // A site with more than the limit shows a capped badge — acceptable for a
        // "needs attention" counter, and far cheaper than a second full scan.
        return count( self::get_events_without_form() );
    }

    /**
     * Render the "events without a form" table inside the registrations dashboard.
     */
    public function render_events_without_form_table() {
        $events = self::get_events_without_form();

        $template_form_id = self::get_template_form_id();
        $template_form    = ( $template_form_id && class_exists( 'GFFormsModel' ) ) ? GFFormsModel::get_form( $template_form_id ) : false;

        echo '<div class="woo-gf-template-form-bar" style="margin-block-end:1.5rem;padding:0.75rem 1rem;background:#f6f7f7;border-inline-start:4px solid #2271b1;">';

        if ( $template_form ) {
            printf(
                '<strong>%s</strong> %s',
                esc_html__( 'טופס ברירת המחדל לשכפול:', 'at-woo-gf-integration' ),
                esc_html( $template_form->title )
            );
            printf(
                ' <a href="%s">%s</a>',
                esc_url( self::get_settings_url() ),
                esc_html__( 'שינוי', 'at-woo-gf-integration' )
            );
        } else {
            printf(
                '<strong>%s</strong> <a href="%s">%s</a>',
                esc_html__( 'לא הוגדר טופס ברירת מחדל לשכפול.', 'at-woo-gf-integration' ),
                esc_url( self::get_settings_url() ),
                esc_html__( 'בחר טופס בהגדרות טפסי אירועים', 'at-woo-gf-integration' )
            );
        }

        echo '</div>';

        if ( empty( $events ) ) {
            echo '<div class="woo-gf-empty-state">';
            echo '<h3>' . esc_html__( 'לכל האירועים יש טופס הרשמה', 'at-woo-gf-integration' ) . '</h3>';
            echo '<p>' . esc_html__( 'לא נמצאו אירועים ללא טופס מקושר.', 'at-woo-gf-integration' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="woo-gf-table-container">';
        echo '<table class="woo-gf-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'אירוע', 'at-woo-gf-integration' ) . '</th>';
        echo '<th>' . esc_html__( 'תאריך האירוע', 'at-woo-gf-integration' ) . '</th>';
        echo '<th>' . esc_html__( 'סטטוס מוצר', 'at-woo-gf-integration' ) . '</th>';
        echo '<th>' . esc_html__( 'פעולות', 'at-woo-gf-integration' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $events as $event ) {
            $product_id = $event['product_id'];

            echo '<tr>';

            echo '<td>';
            printf(
                '<a href="%s" class="woo-gf-event-title-link">%s</a>',
                esc_url( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) ),
                esc_html( $event['title'] )
            );
            if ( $event['stale_form_id'] ) {
                echo '<div class="woo-gf-event-meta" style="color:#d63638;">';
                printf(
                    /* translators: %d: Gravity Forms form id. */
                    esc_html__( 'הטופס המקושר (מספר %d) נמחק או הועבר לאשפה', 'at-woo-gf-integration' ),
                    (int) $event['stale_form_id']
                );
                echo '</div>';
            }
            echo '</td>';

            echo '<td>';
            if ( $event['event_date'] ) {
                echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $event['event_date'] ) ) );
            } else {
                echo '&mdash;';
            }
            echo '</td>';

            echo '<td>' . esc_html( $event['status'] ) . '</td>';

            echo '<td>';
            if ( current_user_can( 'edit_post', $product_id ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
                echo '<input type="hidden" name="action" value="at_woo_gf_create_event_form" />';
                echo '<input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '" />';
                wp_nonce_field( 'at_woo_gf_create_event_form_' . $product_id, 'at_woo_gf_create_form_nonce' );
                echo '<button type="submit" class="button button-primary">';
                echo '<span class="dashicons dashicons-plus-alt" style="vertical-align:text-bottom;"></span> ';
                echo esc_html__( 'צור טופס לאירוע', 'at-woo-gf-integration' );
                echo '</button>';
                echo '</form>';
            } else {
                echo '&mdash;';
            }
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /* ── Admin-post handler ─────────────────────────────────────────────── */

    /**
     * Handle the "צור טופס לאירוע" button on the dashboard.
     */
    public function handle_create_form_request() {
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

        check_admin_referer( 'at_woo_gf_create_event_form_' . $product_id, 'at_woo_gf_create_form_nonce' );

        if ( ! $product_id ) {
            $this->redirect_with_notice( 'error', __( 'לא צוין אירוע.', 'at-woo-gf-integration' ) );
        }

        if ( ! self::current_user_can_create_forms() ) {
            wp_die( esc_html__( 'אין לך הרשאה ליצור טפסים ב-Gravity Forms.', 'at-woo-gf-integration' ), '', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'edit_post', $product_id ) ) {
            wp_die( esc_html__( 'אין לך הרשאה לערוך אירוע זה.', 'at-woo-gf-integration' ), '', array( 'response' => 403 ) );
        }

        $result = self::create_form_for_product( $product_id );

        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( 'error', $result->get_error_message() );
        }

        $this->redirect_with_notice(
            'success',
            sprintf(
                /* translators: 1: new form title, 2: template form id, 3: edit-form URL. */
                __( 'הטופס "%1$s" נוצר כשכפול של טופס ברירת המחדל (מספר %2$d) וקושר לאירוע. <a href="%3$s">ערוך את הטופס</a>', 'at-woo-gf-integration' ),
                $result['form_title'],
                $result['template_form_id'],
                esc_url( $result['edit_url'] )
            )
        );
    }

    /**
     * Can the current user create Gravity Forms forms?
     *
     * @return bool
     */
    public static function current_user_can_create_forms() {
        if ( class_exists( 'GFCommon' ) && GFCommon::current_user_can_any( 'gravityforms_create_form' ) ) {
            return true;
        }

        return current_user_can( 'manage_options' );
    }

    /**
     * Store a one-shot notice and bounce back to the referring admin screen.
     *
     * The message is kept in a per-user transient rather than in the URL: it is
     * pre-translated HTML, and round-tripping it through a query string would
     * mean echoing user-controllable text back into the page.
     *
     * @param string $type    'success' or 'error'.
     * @param string $message Notice text (may contain a link).
     */
    private function redirect_with_notice( $type, $message ) {
        set_transient(
            self::NOTICE_TRANSIENT . get_current_user_id(),
            array(
                'type'    => ( 'success' === $type ) ? 'success' : 'error',
                'message' => $message,
            ),
            MINUTE_IN_SECONDS
        );

        $redirect = wp_get_referer();

        if ( ! $redirect ) {
            $redirect = admin_url( 'admin.php?page=event-registrations' );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /* ── Admin notices ──────────────────────────────────────────────────── */

    /**
     * Render the one-shot notice, plus a standing warning when no template is set.
     */
    public function render_admin_notices() {
        $screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';
        $our_screen = ( false !== strpos( $screen_id, 'event-registrations' ) || false !== strpos( $screen_id, self::PAGE_SLUG ) );

        $notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );

        if ( $notice && is_array( $notice ) ) {
            delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ),
                wp_kses( $notice['message'], self::allowed_notice_html() )
            );
        }

        if ( ! $our_screen || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $template = self::get_template_form_or_error();

        if ( is_wp_error( $template ) ) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                wp_kses( $template->get_error_message(), self::allowed_notice_html() )
            );
        }
    }

    /**
     * HTML allowed inside our admin notices.
     *
     * @return array
     */
    private static function allowed_notice_html() {
        return array(
            'a'      => array(
                'href'   => array(),
                'target' => array(),
            ),
            'strong' => array(),
            'em'     => array(),
        );
    }

    /* ── Settings screen ────────────────────────────────────────────────── */

    /**
     * Register the settings menu entry (submenu of the registrations dashboard).
     */
    public function add_admin_menu() {
        add_submenu_page(
            'event-registrations',
            __( 'הגדרות טפסי אירועים', 'at-woo-gf-integration' ),
            __( 'הגדרות טפסים', 'at-woo-gf-integration' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register the option with the Settings API.
     */
    public function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION,
            array(
                'type'              => 'integer',
                'sanitize_callback' => array( $this, 'sanitize_template_form_id' ),
                'default'           => self::LEGACY_DEFAULT_FORM_ID,
            )
        );
    }

    /**
     * Validate the chosen form id: it must be 0 (none) or an existing GF form.
     *
     * @param mixed $input Raw posted value.
     * @return int
     */
    public function sanitize_template_form_id( $input ) {
        $form_id = absint( $input );

        if ( ! $form_id ) {
            return 0;
        }

        $exists = class_exists( 'GFFormsModel' ) ? (bool) GFFormsModel::get_form( $form_id ) : false;

        if ( ! $exists ) {
            add_settings_error(
                self::OPTION,
                'at_woo_gf_template_missing',
                sprintf(
                    /* translators: %d: Gravity Forms form id. */
                    __( 'הטופס שנבחר (מספר %d) אינו קיים או שהועבר לאשפה. ההגדרה לא שונתה.', 'at-woo-gf-integration' ),
                    $form_id
                ),
                'error'
            );

            $current = get_option( self::OPTION, null );

            return ( null === $current || '' === $current ) ? self::LEGACY_DEFAULT_FORM_ID : absint( $current );
        }

        return $form_id;
    }

    /**
     * Render the settings screen.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_id = self::get_template_form_id();
        $forms      = class_exists( 'GFAPI' ) ? GFAPI::get_forms() : array();
        ?>
        <div class="wrap" dir="rtl">
            <h1><?php esc_html_e( 'הגדרות טפסי אירועים', 'at-woo-gf-integration' ); ?></h1>

            <p style="max-width:48rem;">
                <?php esc_html_e( 'הטופס שנבחר כאן הוא טופס ברירת המחדל: בכל פעם שנוצר טופס הרשמה לאירוע — מדשבורד ההרשמות או מכפתור "צור טופס חדש" בעמוד עריכת האירוע — הטופס הזה משוכפל (שדות, הגדרות, התראות ואישורים), מקבל שם הכולל את שם האירוע ומקושר לאירוע. הטופס המקורי עצמו אינו משתנה.', 'at-woo-gf-integration' ); ?>
            </p>

            <?php settings_errors( self::OPTION ); ?>

            <?php if ( empty( $forms ) ) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'לא נמצאו טפסים ב-Gravity Forms. צור טופס תחילה ולאחר מכן בחר אותו כאן.', 'at-woo-gf-integration' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( self::SETTINGS_GROUP ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="at-woo-gf-template-form">
                                <?php esc_html_e( 'טופס ברירת מחדל לאירועים', 'at-woo-gf-integration' ); ?>
                            </label>
                        </th>
                        <td>
                            <select name="<?php echo esc_attr( self::OPTION ); ?>" id="at-woo-gf-template-form">
                                <option value="0" <?php selected( 0, $current_id ); ?>>
                                    <?php esc_html_e( '— לא נבחר —', 'at-woo-gf-integration' ); ?>
                                </option>
                                <?php foreach ( $forms as $form ) : ?>
                                    <option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( (int) $form['id'], $current_id ); ?>>
                                        <?php
                                        printf(
                                            /* translators: 1: form title, 2: form id. */
                                            esc_html__( '%1$s (מספר %2$d)', 'at-woo-gf-integration' ),
                                            esc_html( $form['title'] ),
                                            (int) $form['id']
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="description">
                                <?php esc_html_e( 'מומלץ להקדיש טופס ייעודי לתפקיד הזה ולא להשתמש בטופס שמקבל הרשמות בפועל.', 'at-woo-gf-integration' ); ?>
                            </p>

                            <?php if ( $current_id && class_exists( 'GFFormsModel' ) && GFFormsModel::get_form( $current_id ) ) : ?>
                                <p>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms&id=' . $current_id ) ); ?>" target="_blank">
                                        <?php esc_html_e( 'ערוך את טופס ברירת המחדל', 'at-woo-gf-integration' ); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'שמור הגדרות', 'at-woo-gf-integration' ) ); ?>
            </form>

            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=event-registrations' ) ); ?>">
                    <?php esc_html_e( '→ חזרה לדשבורד ההרשמות', 'at-woo-gf-integration' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
