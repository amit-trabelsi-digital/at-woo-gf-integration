<?php
/**
 * Registration Scheduler Class
 * 
 * @package WooGFIntegration
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class to handle scheduled registration summary emails
 */
class Woo_GF_Registration_Scheduler {

    /**
     * Instance of this class.
     * @var Woo_GF_Registration_Scheduler
     */
    private static $instance = null;

    /**
     * Frequencies offered in the product metabox. Each one maps to its own hook.
     * @var string[]
     */
    private static $frequencies = array( 'hourly', 'daily', 'weekly', 'monthly' );

    /**
     * Get the singleton instance of this class.
     * @return Woo_GF_Registration_Scheduler
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
        // Register cron schedules
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // Schedule/unschedule events on product save
        add_action( 'woocommerce_update_product', array( $this, 'schedule_or_unschedule_event' ) );
        
        // Hook into our custom cron events
        add_action( 'woo_gf_hourly_registration_email', array( $this, 'send_registration_summary' ) );
        add_action( 'woo_gf_daily_registration_email', array( $this, 'send_registration_summary' ) );
        add_action( 'woo_gf_weekly_registration_email', array( $this, 'send_registration_summary' ) );
        add_action( 'woo_gf_monthly_registration_email', array( $this, 'send_registration_summary' ) );
    }

    /**
     * Add custom cron schedules.
     * @param array $schedules
     * @return array
     */
    public function add_cron_schedules( $schedules ) {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => esc_html__( 'Once Weekly', 'at-woo-gf-integration' ),
        );
        $schedules['monthly'] = array(
            'interval' => MONTH_IN_SECONDS,
            'display'  => esc_html__( 'Once Monthly', 'at-woo-gf-integration' ),
        );
        return $schedules;
    }

    /**
     * Write a diagnostic line to the PHP error log.
     *
     * The digest used to fail completely silently: an admin who ticked
     * "send me registration updates" and received nothing had no way to tell
     * "the cron never ran" from "there are no registrations yet". Every early
     * return below now says why.
     *
     * @param string $message
     */
    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[woo-gf-registration-digest] ' . $message );
        }
    }

    /**
     * Normalise a product ID to the translation group's canonical product.
     * @param int $product_id
     * @return int
     */
    private function canonical_id( $product_id ) {
        $product_id = absint( $product_id );

        if ( $product_id && function_exists( 'woo_gf_get_canonical_product_id' ) ) {
            $canonical = absint( woo_gf_get_canonical_product_id( $product_id ) );
            if ( $canonical ) {
                return $canonical;
            }
        }

        return $product_id;
    }

    /**
     * Every product ID in a translation group (canonical first).
     *
     * Entries submitted before the canonicalisation landed in v2.15.0 are still
     * stamped with the translation they were submitted on, so the digest has to
     * look for all of them or those registrants silently disappear.
     *
     * @param int $product_id
     * @return int[]
     */
    private function translation_group_ids( $product_id ) {
        $ids = array( $this->canonical_id( $product_id ), absint( $product_id ) );

        if ( function_exists( 'pll_get_post_translations' ) ) {
            $translations = pll_get_post_translations( absint( $product_id ) );
            if ( is_array( $translations ) ) {
                $ids = array_merge( $ids, array_map( 'absint', array_values( $translations ) ) );
            }
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * Schedule or unschedule the email event when a product is saved.
     *
     * The event is always registered against the canonical translation, because
     * that is the only product process_and_send_email_for_product() will send
     * for. Scheduling it against, say, the English translation of a Hebrew event
     * used to produce a cron event that fired on time and then returned early
     * forever — the digest looked scheduled but could never send.
     *
     * @param int $product_id
     */
    public function schedule_or_unschedule_event( $product_id ) {
        $canonical_id = $this->canonical_id( $product_id );

        if ( ! $canonical_id ) {
            return;
        }

        // A cron event on a translation can never send (the sender only mails
        // for the canonical product), so drop any left over from an older
        // version. The canonical's own schedule is rebuilt below.
        foreach ( $this->translation_group_ids( $product_id ) as $id ) {
            if ( $id !== $canonical_id ) {
                $this->clear_all_schedules( $id );
            }
        }

        // Settings are read from the canonical product, not from whichever
        // translation happened to be saved — otherwise saving the English page
        // of a Hebrew event would tear down the Hebrew event's schedule.
        $settings = $this->get_digest_settings( $product_id );

        $this->clear_all_schedules( $canonical_id );

        if ( ! $settings['enabled'] || empty( $settings['frequency'] ) ) {
            return;
        }

        if ( ! in_array( $settings['frequency'], self::$frequencies, true ) ) {
            $this->log( sprintf( 'product %d: unknown frequency "%s", nothing scheduled.', $canonical_id, $settings['frequency'] ) );
            return;
        }

        $hook = "woo_gf_{$settings['frequency']}_registration_email";

        if ( wp_next_scheduled( $hook, array( $canonical_id ) ) ) {
            return;
        }

        $scheduled = wp_schedule_event( time(), $settings['frequency'], $hook, array( $canonical_id ), true );

        if ( is_wp_error( $scheduled ) ) {
            $this->log( sprintf( 'product %d: wp_schedule_event(%s) failed — %s', $canonical_id, $settings['frequency'], $scheduled->get_error_message() ) );
        } elseif ( false === $scheduled ) {
            $this->log( sprintf( 'product %d: wp_schedule_event(%s) was blocked by a filter.', $canonical_id, $settings['frequency'] ) );
        }
    }

    /**
     * Resolve the digest settings for a translation group.
     *
     * The canonical product wins. If it has the digest switched off but one of
     * its translations has it on, that translation's settings are used — an
     * admin who ticked the box on the English page should not end up with a
     * setting that is stored but can never fire.
     *
     * @param int $product_id
     * @return array{enabled: bool, frequency: string}
     */
    private function get_digest_settings( $product_id ) {
        $off = array( 'enabled' => false, 'frequency' => '' );

        foreach ( $this->translation_group_ids( $product_id ) as $id ) {
            $product = wc_get_product( $id );

            if ( ! $product ) {
                continue;
            }

            if ( 'yes' === $product->get_meta( '_woo_gf_enable_registration_email' ) ) {
                return array(
                    'enabled'   => true,
                    'frequency' => (string) $product->get_meta( '_woo_gf_email_frequency' ),
                );
            }
        }

        return $off;
    }

    /**
     * Clear all possible schedules for a product.
     * @param int $product_id
     */
    private function clear_all_schedules( $product_id ) {
        foreach ( self::$frequencies as $schedule ) {
            $hook = "woo_gf_{$schedule}_registration_email";
            wp_clear_scheduled_hook( $hook, array( absint( $product_id ) ) );
        }
    }

    /**
     * Send registration summary email.
     * This method is triggered by the cron job.
     * @param int $product_id (Optional) - If triggered by a specific product's cron.
     */
    public function send_registration_summary( $product_id = 0 ) {
        $products_to_process = array();

        if ( $product_id ) {
            // Triggered for a single product. The flag is read across the whole
            // translation group so an event enabled on a non-Hebrew page still
            // reports.
            $product  = wc_get_product( $product_id );
            $settings = $this->get_digest_settings( $product_id );

            if ( $product && $settings['enabled'] ) {
                $products_to_process[] = $product;
            } elseif ( ! $product ) {
                $this->log( sprintf( 'cron fired for product %d, which no longer exists.', $product_id ) );
            } else {
                $this->log( sprintf( 'cron fired for product %d, but the digest is switched off.', $product_id ) );
            }
        } else {
            // General cron, check all products (less ideal, but a fallback)
            $args = array(
                'post_type'   => 'product',
                'posts_per_page' => -1,
                'meta_query'  => array(
                    array(
                        'key'     => '_woo_gf_enable_registration_email',
                        'value'   => 'yes',
                        'compare' => '=',
                    ),
                ),
            );
            $query = new WP_Query( $args );
            if ( $query->have_posts() ) {
                while ( $query->have_posts() ) {
                    $query->the_post();
                    $products_to_process[] = wc_get_product( get_the_ID() );
                }
                wp_reset_postdata();
            }
        }
        
        foreach( $products_to_process as $product ) {
            $this->process_and_send_email_for_product( $product );
        }
    }

    /**
     * Process and send email for a single product.
     * @param WC_Product $product
     */
    private function process_and_send_email_for_product( $product ) {
        $product_id = $product->get_id();

        // Entries are stored against the canonical translation. Only that
        // product sends the digest, otherwise a he/en/ar event would mail the
        // same report three times (Polylang syncs `_woo_gf_notification_email`).
        // schedule_or_unschedule_event() only ever schedules the canonical ID,
        // so reaching this with a translation means a stale event from an older
        // version — unschedule it instead of returning every hour forever.
        $canonical_id = $this->canonical_id( $product_id );
        if ( $canonical_id !== $product_id ) {
            $this->log( sprintf( 'product %d is a translation of %d; clearing its stale schedules.', $product_id, $canonical_id ) );
            $this->clear_all_schedules( $product_id );
            return;
        }

        $to      = $this->get_notification_recipient( $product );
        $form_id = $product->get_meta( '_woo_gf_form_id', true );

        if ( ! is_email( $to ) ) {
            $this->log( sprintf( 'product %d: no valid notification address (%s), skipping.', $product_id, var_export( $to, true ) ) );
            return;
        }

        if ( ! $form_id ) {
            $this->log( sprintf( 'product %d: no registration form linked, skipping.', $product_id ) );
            return;
        }

        $entries = $this->get_entries_for_product( $form_id, $product_id );

        if ( is_wp_error( $entries ) ) {
            $this->log( sprintf( 'product %d: GFAPI::get_entries(form %s) failed — %s', $product_id, $form_id, $entries->get_error_message() ) );
            return;
        }

        $table = $this->build_registrations_table( $entries, $form_id );

        // An empty report is still a report. Bailing out on zero registrations
        // is what made this feature look broken: the admin opted into a periodic
        // update, so send it and say plainly that nobody has signed up yet.
        $attachments = array();
        $csv_path    = '';

        if ( ! empty( $table['rows'] ) ) {
            $csv_path = $this->create_entries_csv( $table, $product_id );
            if ( $csv_path ) {
                $attachments[] = $csv_path;
            } else {
                $this->log( sprintf( 'product %d: could not write the CSV, sending the table only.', $product_id ) );
            }
        }

        $product_name = $product->get_name();
        $count        = count( $table['rows'] );

        $subject = sprintf(
            /* translators: 1: number of registrations, 2: event name */
            __( 'עדכון הרשמות (%1$d) עבור: %2$s', 'at-woo-gf-integration' ),
            $count,
            $product_name
        );

        $body = $this->build_email_body( $product_name, $table, (bool) $csv_path );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $sent = wp_mail( $to, $subject, $body, $headers, $attachments );

        $this->log( sprintf(
            'product %d: %d registration(s), wp_mail to %s returned %s.',
            $product_id,
            $count,
            $to,
            var_export( $sent, true )
        ) );

        if ( $csv_path && file_exists( $csv_path ) ) {
            unlink( $csv_path );
        }
    }

    /**
     * The address the digest goes to.
     *
     * Falls back to the translations of the event, so a recipient entered on the
     * English page still receives the report when Polylang has not synced the
     * meta across the group.
     *
     * @param WC_Product $product Canonical product.
     * @return string
     */
    private function get_notification_recipient( $product ) {
        $to = trim( (string) $product->get_meta( '_woo_gf_notification_email' ) );

        if ( is_email( $to ) ) {
            return $to;
        }

        foreach ( $this->translation_group_ids( $product->get_id() ) as $id ) {
            $translation = wc_get_product( $id );

            if ( ! $translation ) {
                continue;
            }

            $candidate = trim( (string) $translation->get_meta( '_woo_gf_notification_email' ) );

            if ( is_email( $candidate ) ) {
                return $candidate;
            }
        }

        return $to;
    }

    /**
     * Fetch the active entries belonging to an event.
     *
     * Matches every ID in the translation group, not just the canonical one, so
     * registrations stamped before v2.15.0 canonicalised `woo_gf_product_id`
     * are still reported.
     *
     * @param int|string $form_id
     * @param int        $product_id Canonical product ID.
     * @return array|WP_Error
     */
    private function get_entries_for_product( $form_id, $product_id ) {
        $ids = $this->translation_group_ids( $product_id );

        $search_criteria = array(
            'status'        => 'active',
            'field_filters' => array(
                count( $ids ) > 1
                    ? array( 'key' => 'woo_gf_product_id', 'value' => $ids, 'operator' => 'in' )
                    : array( 'key' => 'woo_gf_product_id', 'value' => $product_id ),
            ),
        );

        $sorting = array( 'key' => 'date_created', 'direction' => 'DESC' );
        $paging  = array( 'offset' => 0, 'page_size' => 1000 );

        $entries = GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging );

        return is_wp_error( $entries ) ? $entries : (array) $entries;
    }

    /**
     * Flatten entries into a header row plus data rows.
     *
     * Single source of truth for both the CSV attachment and the HTML table in
     * the email body, so the two can never disagree.
     *
     * @param array      $entries
     * @param int|string $form_id
     * @return array{header: string[], rows: array[]}
     */
    private function build_registrations_table( $entries, $form_id ) {
        $empty = array( 'header' => array(), 'rows' => array() );

        $form = GFAPI::get_form( $form_id );

        if ( ! $form || empty( $form['fields'] ) ) {
            $this->log( sprintf( 'form %s could not be loaded, no table built.', $form_id ) );
            return $empty;
        }

        $header = array();
        foreach ( $form['fields'] as $field ) {
            if ( 'name' === $field->type ) {
                $header[] = $field->label . ' ' . __( '(שם פרטי)', 'at-woo-gf-integration' );
                $header[] = $field->label . ' ' . __( '(שם משפחה)', 'at-woo-gf-integration' );
            } else {
                $header[] = $field->label;
            }
        }
        $header[] = __( 'תאריך הרשמה', 'at-woo-gf-integration' );

        $rows = array();
        foreach ( (array) $entries as $entry ) {
            $row = array();
            foreach ( $form['fields'] as $field ) {
                if ( 'name' === $field->type ) {
                    $row[] = isset( $entry[ (string) $field->id . '.3' ] ) ? $entry[ (string) $field->id . '.3' ] : '';
                    $row[] = isset( $entry[ (string) $field->id . '.6' ] ) ? $entry[ (string) $field->id . '.6' ] : '';
                } else {
                    $value = GFFormsModel::get_lead_field_value( $entry, $field );
                    $row[] = is_array( $value ) ? implode( ', ', array_filter( $value ) ) : (string) $value;
                }
            }
            $row[]  = isset( $entry['date_created'] ) ? $entry['date_created'] : '';
            $rows[] = $row;
        }

        return array( 'header' => $header, 'rows' => $rows );
    }

    /**
     * Build the HTML body of the digest.
     *
     * @param string $product_name
     * @param array  $table         Output of build_registrations_table().
     * @param bool   $has_csv
     * @return string
     */
    private function build_email_body( $product_name, $table, $has_csv ) {
        $count = count( $table['rows'] );
        $date  = date_i18n( get_option( 'date_format' ) . ' H:i' );

        $html  = '<div dir="rtl" style="text-align:start;font-family:Arial,Helvetica,sans-serif;">';
        $html .= '<p>' . sprintf(
            /* translators: 1: event name, 2: date and time */
            esc_html__( 'עדכון הרשמות עבור "%1$s", נכון ל-%2$s.', 'at-woo-gf-integration' ),
            esc_html( $product_name ),
            esc_html( $date )
        ) . '</p>';

        if ( 0 === $count ) {
            $html .= '<p><strong>' . esc_html__( 'אין עדיין נרשמים לאירוע זה.', 'at-woo-gf-integration' ) . '</strong></p>';
            $html .= '</div>';
            return $html;
        }

        $html .= '<p><strong>' . sprintf(
            /* translators: %d: number of registrations */
            esc_html( _n( 'נרשם %d משתתף.', 'נרשמו %d משתתפים.', $count, 'at-woo-gf-integration' ) ),
            $count
        ) . '</strong></p>';

        $html .= '<div style="overflow-x:auto;">';
        $html .= '<table dir="rtl" cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-size:13px;">';

        $html .= '<thead><tr>';
        foreach ( $table['header'] as $label ) {
            $html .= '<th style="border:1px solid #999;background:#f2f2f2;text-align:start;white-space:nowrap;">' . esc_html( $label ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ( $table['rows'] as $row ) {
            $html .= '<tr>';
            foreach ( $row as $cell ) {
                $html .= '<td style="border:1px solid #ccc;text-align:start;">' . esc_html( $cell ) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        if ( $has_csv ) {
            $html .= '<p>' . esc_html__( 'מצורף גם קובץ CSV עם אותם הנתונים.', 'at-woo-gf-integration' ) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Write the registrations table to a temporary CSV file.
     *
     * @param array $table      Output of build_registrations_table().
     * @param int   $product_id
     * @return string|false Path to CSV file or false on failure.
     */
    private function create_entries_csv( $table, $product_id ) {
        if ( empty( $table['header'] ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) ) {
            $this->log( 'wp_upload_dir() error: ' . $upload_dir['error'] );
            return false;
        }

        $csv_path = trailingslashit( $upload_dir['basedir'] ) . 'registration_export_' . absint( $product_id ) . '.csv';

        $file = fopen( $csv_path, 'w' );
        if ( ! $file ) {
            $this->log( 'could not open ' . $csv_path . ' for writing.' );
            return false;
        }

        // UTF-8 BOM so Excel renders the Hebrew columns correctly.
        fwrite( $file, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv( $file, $table['header'] );

        foreach ( $table['rows'] as $row ) {
            fputcsv( $file, $row );
        }

        fclose( $file );

        return $csv_path;
    }
} 