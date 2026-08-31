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
     * Schedule or unschedule the email event when a product is saved.
     * @param int $product_id
     */
    public function schedule_or_unschedule_event( $product_id ) {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return;
        }

        $is_enabled = $product->get_meta( '_woo_gf_enable_registration_email' ) === 'yes';
        $frequency = $product->get_meta( '_woo_gf_email_frequency' );
        $hook = "woo_gf_{$frequency}_registration_email";
        
        // First, clear all possible schedules for this product
        $this->clear_all_schedules( $product_id );
        
        if ( $is_enabled && ! empty( $frequency ) ) {
            // Schedule the new event if not already scheduled
            if ( ! wp_next_scheduled( $hook, array( $product_id ) ) ) {
                wp_schedule_event( time(), $frequency, $hook, array( $product_id ) );
            }
        }
    }
    
    /**
     * Clear all possible schedules for a product.
     * @param int $product_id
     */
    private function clear_all_schedules( $product_id ) {
        $schedules = array( 'hourly', 'daily', 'weekly', 'monthly' );
        foreach ( $schedules as $schedule ) {
            $hook = "woo_gf_{$schedule}_registration_email";
            wp_clear_scheduled_hook( $hook, array( $product_id ) );
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
            // Triggered for a single product
            $product = wc_get_product( $product_id );
            if( $product && $product->get_meta('_woo_gf_enable_registration_email') === 'yes' ) {
                $products_to_process[] = $product;
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
        $to = $product->get_meta( '_woo_gf_notification_email' );
        $form_id = $product->get_meta( '_woo_gf_form_id', true );

        if ( ! is_email( $to ) || ! $form_id ) {
            return;
        }

        // Entries are stored against the canonical translation. Only that
        // product sends the digest, otherwise a he/en/ar event would mail the
        // same CSV three times (Polylang syncs `_woo_gf_notification_email`).
        $product_id = $product->get_id();
        if ( function_exists( 'woo_gf_get_canonical_product_id' ) ) {
            $canonical_id = woo_gf_get_canonical_product_id( $product_id );
            if ( $canonical_id !== $product_id ) {
                return;
            }
            $product_id = $canonical_id;
        }

        // Get entries
        $search_criteria = array( 'status' => 'active', 'field_filters' => array( array( 'key' => 'woo_gf_product_id', 'value' => $product_id ) ) );
        $entries = GFAPI::get_entries( $form_id, $search_criteria, null, array( 'page_size' => 1000 ) );
        
        if ( empty( $entries ) ) {
            return; // No entries to send
        }
        
        // Create CSV file
        $csv_path = $this->create_entries_csv( $entries, $form_id, $product->get_id() );
        
        if ( ! $csv_path ) {
            return; // Failed to create CSV
        }

        // Send email
        $product_name = $product->get_name();
        $subject = sprintf( __( 'עדכון הרשמות עבור: %s', 'at-woo-gf-integration' ), $product_name );
        $body = sprintf( __( 'מצורף קובץ CSV עם כל ההרשמות עבור המוצר "%s" נכון לתאריך %s.', 'at-woo-gf-integration' ), $product_name, date_i18n( get_option( 'date_format' ) ) );
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail( $to, $subject, $body, $headers, array( $csv_path ) );
        
        // Delete the temporary CSV file
        unlink( $csv_path );
    }

    /**
     * Create a CSV file from entries.
     * @param array $entries
     * @param int $form_id
     * @param int $product_id
     * @return string|false Path to CSV file or false on failure.
     */
    private function create_entries_csv( $entries, $form_id, $product_id ) {
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) return false;
        
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . "/registration_export_{$product_id}.csv";
        
        $file = fopen( $csv_path, 'w' );
        if ( ! $file ) return false;

        // Add UTF-8 BOM to fix encoding in Excel
        fprintf( $file, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        // Header row
        $header = array();
        foreach ( $form['fields'] as $field ) {
            if ($field->type === 'name') {
                $header[] = $field->label . ' (First)';
                $header[] = $field->label . ' (Last)';
            } else {
                $header[] = $field->label;
            }
        }
        $header[] = __( 'Date Submitted', 'at-woo-gf-integration' );
        fputcsv( $file, $header );
        
        // Data rows
        foreach ( $entries as $entry ) {
            $row = array();
            foreach ( $form['fields'] as $field ) {
                $value = GFFormsModel::get_lead_field_value( $entry, $field );
                if ($field->type === 'name') {
                     $row[] = $entry[ (string) $field->id . '.3' ];
                     $row[] = $entry[ (string) $field->id . '.6' ];
                } else {
                    $row[] = is_array($value) ? implode(', ', $value) : $value;
                }
            }
            $row[] = $entry['date_created'];
            fputcsv( $file, $row );
        }
        
        fclose( $file );
        return $csv_path;
    }
} 