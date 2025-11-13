<?php
/**
 * Registration Dashboard Class
 * 
 * @package WooGFIntegration
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class to handle registration dashboard
 */
class Woo_GF_Registration_Dashboard {

    /**
     * Instance of this class.
     *
     * @var Woo_GF_Registration_Dashboard
     */
    private static $instance = null;

    /**
     * Get the singleton instance of this class.
     *
     * @return Woo_GF_Registration_Dashboard
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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_init', array( $this, 'handle_sample_event_creation' ) );
        $this->register_ajax_handlers();
    }
    
    /**
     * Get all Gravity Forms with caching
     */
    private function get_cached_forms() {
        $cache_key = 'woo_gf_all_forms';
        $forms = get_transient( $cache_key );
        
        if ( false === $forms ) {
            $forms = class_exists( 'GFAPI' ) ? GFAPI::get_forms() : array();
            set_transient( $cache_key, $forms, 5 * MINUTE_IN_SECONDS );
        }
        
        return $forms;
    }

    /**
     * Add dashboard menu item to WooCommerce menu
     */
    public function add_admin_menu() {
        // הוספה לתפריט הראשי במקום כתת-תפריט של WooCommerce
        add_menu_page(
            __( 'דשבורד הרשמות', 'at-woo-gf-integration' ),
            __( 'דשבורד הרשמות', 'at-woo-gf-integration' ),
            'manage_woocommerce',
            'event-registrations',
            array( $this, 'render_dashboard_page' ),
            'dashicons-groups',
            3
        );
    }

    /**
     * Enqueue dashboard assets
     */
    public function enqueue_scripts( $hook ) {
        // Load custom menu CSS on all admin pages
        wp_enqueue_style( 
            'woo-gf-dashboard-custom', 
            AT_WOO_GF_INTEGRATION_URL . 'assets/css/dashboard-custom.css', 
            array(), 
            AT_WOO_GF_INTEGRATION_VERSION 
        );
        
        // Load dashboard-specific assets only on dashboard page
        if ( 'toplevel_page_event-registrations' !== $hook ) {
            return;
        }

        wp_enqueue_style( 
            'woo-gf-dashboard', 
            AT_WOO_GF_INTEGRATION_URL . 'assets/css/dashboard.css', 
            array(), 
            AT_WOO_GF_INTEGRATION_VERSION 
        );
        
        wp_enqueue_script( 
            'woo-gf-dashboard', 
            AT_WOO_GF_INTEGRATION_URL . 'assets/js/dashboard.js', 
            array( 'jquery' ), 
            AT_WOO_GF_INTEGRATION_VERSION, 
            true 
        );
        
        wp_localize_script( 'woo-gf-dashboard', 'wooGfDashboard', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'woo_gf_dashboard_nonce' ),
            'strings' => array(
                'loading' => __( 'טוען...', 'at-woo-gf-integration' ),
                'error' => __( 'שגיאה בטעינת הנתונים', 'at-woo-gf-integration' ),
                'noRegistrations' => __( 'אין נרשמים לאירוע זה', 'at-woo-gf-integration' )
            )
        ) );
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        // Debug logging
        error_log( '=== WooGF Dashboard Render START ===' );
        error_log( 'Memory at start: ' . round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB' );
        $start_time = microtime( true );
        
        // Clear cache if requested
        if ( isset( $_GET['clear_cache'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_cache' ) ) {
            $this->clear_all_caches();
            wp_redirect( remove_query_arg( array( 'clear_cache', '_wpnonce' ) ) );
            exit;
        }
        
        error_log( 'WooGF: Starting page render...' );
        ?>
        <div class="wrap woo-gf-dashboard-wrap">
            <div class="woo-gf-dashboard-header">
                <h1 class="woo-gf-dashboard-title">
                    <span class="dashicons dashicons-groups"></span>
                    <?php esc_html_e( 'דשבורד הרשמות', 'at-woo-gf-integration' ); ?>
                </h1>
                <div class="woo-gf-dashboard-actions">
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'clear_cache', '1' ), 'clear_cache' ) ); ?>" 
                       class="button button-primary" id="refresh-dashboard">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'רענן', 'at-woo-gf-integration' ); ?>
                    </a>
                </div>
            </div>
            
            <!-- סטטיסטיקות כלליות -->
            <div class="woo-gf-stats-section">
                <div class="woo-gf-stats-grid">
                    <?php 
                    error_log( 'WooGF: Before render_dashboard_stats()' );
                    $this->render_dashboard_stats(); 
                    error_log( 'WooGF: After render_dashboard_stats()' );
                    ?>
                </div>
            </div>

            <!-- חיפוש וסינון -->
            <div class="woo-gf-search-section">
                <div class="woo-gf-search-card">
                    <h3 class="woo-gf-card-title">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'חיפוש וסינון', 'at-woo-gf-integration' ); ?>
                    </h3>
                    <form id="woo-gf-search-form" method="get" action="">
                        <input type="hidden" name="page" value="event-registrations" />
                        
                        <div class="woo-gf-search-grid">
                            <div class="woo-gf-search-field">
                                <label for="woo-gf-search"><?php esc_html_e( 'חיפוש חופשי', 'at-woo-gf-integration' ); ?></label>
                                <input type="text" name="search" id="woo-gf-search" 
                                       value="<?php echo esc_attr( isset( $_GET['search'] ) ? $_GET['search'] : '' ); ?>" 
                                       placeholder="<?php esc_attr_e( 'חפש לפי שם, אימייל, טלפון...', 'at-woo-gf-integration' ); ?>" />
                            </div>
                            
                            <div class="woo-gf-search-field">
                                <label for="woo-gf-form-filter"><?php esc_html_e( 'טופס', 'at-woo-gf-integration' ); ?></label>
                                <?php $this->render_forms_dropdown(); ?>
                            </div>
                            
                            <div class="woo-gf-search-field">
                                <label for="woo-gf-product-filter"><?php esc_html_e( 'מוצר', 'at-woo-gf-integration' ); ?></label>
                                <?php $this->render_products_dropdown(); ?>
                            </div>
                            
                            <div class="woo-gf-search-field">
                                <label><?php esc_html_e( 'תאריך', 'at-woo-gf-integration' ); ?></label>
                                <div class="woo-gf-date-range">
                                    <input type="date" name="date_from" 
                                           value="<?php echo esc_attr( isset( $_GET['date_from'] ) ? $_GET['date_from'] : '' ); ?>" 
                                           placeholder="<?php esc_attr_e( 'מתאריך', 'at-woo-gf-integration' ); ?>" />
                                    <span class="woo-gf-date-separator">עד</span>
                                    <input type="date" name="date_to" 
                                           value="<?php echo esc_attr( isset( $_GET['date_to'] ) ? $_GET['date_to'] : '' ); ?>" 
                                           placeholder="<?php esc_attr_e( 'עד תאריך', 'at-woo-gf-integration' ); ?>" />
                                </div>
                            </div>
                        </div>
                        
                        <div class="woo-gf-search-actions">
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-search"></span>
                                <?php esc_html_e( 'חפש', 'at-woo-gf-integration' ); ?>
                            </button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=event-registrations' ) ); ?>" 
                               class="button button-secondary">
                                <span class="dashicons dashicons-dismiss"></span>
                                <?php esc_html_e( 'נקה חיפוש', 'at-woo-gf-integration' ); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- מערכת טאבים -->
            <div class="woo-gf-tabs-container">
                <div class="woo-gf-tabs-card">
                    <!-- Tabs Header -->
                    <div class="woo-gf-tabs-header">
                        <button class="woo-gf-tab-btn active" data-tab="events">
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <span class="woo-gf-tab-label"><?php esc_html_e( 'אירועים', 'at-woo-gf-integration' ); ?></span>
                            <span class="woo-gf-tab-count"><?php echo $this->get_events_count(); ?></span>
                        </button>
                        <button class="woo-gf-tab-btn" data-tab="registrations">
                            <span class="dashicons dashicons-list-view"></span>
                            <span class="woo-gf-tab-label"><?php esc_html_e( 'הרשמות', 'at-woo-gf-integration' ); ?></span>
                            <span class="woo-gf-tab-count"><?php echo $this->get_total_entries_count(); ?></span>
                        </button>
                    </div>

                    <!-- Tabs Content -->
                    <div class="woo-gf-tabs-content">
                        <!-- טאב אירועים -->
                        <div id="woo-gf-tab-events" class="woo-gf-tab-panel active">
                            <?php 
                            error_log( 'WooGF: Before render_events_table()' );
                            $this->render_events_table(); 
                            error_log( 'WooGF: After render_events_table()' );
                            ?>
                        </div>

                        <!-- טאב הרשמות -->
                        <div id="woo-gf-tab-registrations" class="woo-gf-tab-panel">
                            <?php 
                            error_log( 'WooGF: Before render_entries_table()' );
                            $this->render_entries_table(); 
                            error_log( 'WooGF: After render_entries_table()' );
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidepeek Modal -->
        <div id="woo-gf-sidepeek" class="woo-gf-sidepeek" dir="rtl">
            <div class="woo-gf-sidepeek-overlay"></div>
            <div class="woo-gf-sidepeek-content">
                <div class="woo-gf-sidepeek-header">
                    <h3 id="woo-gf-sidepeek-title"></h3>
                    <button type="button" class="woo-gf-sidepeek-close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="woo-gf-sidepeek-body" id="woo-gf-sidepeek-body">
                    <!-- תוכן דינמי יוכנס כאן -->
                </div>
            </div>
        </div>
        <?php
        // Debug logging at end
        $end_time = microtime( true );
        $total_time = round( ( $end_time - $start_time ) * 1000, 2 );
        $peak_memory = round( memory_get_peak_usage() / 1024 / 1024, 2 );
        error_log( '=== WooGF Dashboard Render COMPLETE ===' );
        error_log( 'Total time: ' . $total_time . ' ms' );
        error_log( 'Peak memory: ' . $peak_memory . ' MB' );
    }

    /**
     * Render dashboard statistics
     */
    private function render_dashboard_stats() {
        // Set PHP timeout to prevent hangs
        @set_time_limit( 60 );
        
        // Get statistics with error handling
        try {
            $total_entries = $this->get_total_entries_count();
            $total_forms = count( $this->get_cached_forms() );
            $total_products = $this->get_products_with_forms_count();
            $today_entries = $this->get_today_entries_count();
            $total_events = $this->get_events_count();
            $active_events = $this->get_active_events_count();
        } catch ( Exception $e ) {
            error_log( 'WooGF Dashboard Stats Error: ' . $e->getMessage() );
            // Return default values on error
            $total_entries = 0;
            $total_forms = 0;
            $total_products = 0;
            $today_entries = 0;
            $total_events = 0;
            $active_events = 0;
        }
        
        $stats = array(
            array(
                'title' => __( 'סך הרשמות', 'at-woo-gf-integration' ),
                'value' => number_format_i18n( $total_entries ),
                'icon' => 'dashicons-groups',
                'color' => '#0073aa',
                'trend' => $this->get_registrations_trend()
            ),
            array(
                'title' => __( 'אירועים פעילים', 'at-woo-gf-integration' ),
                'value' => number_format_i18n( $active_events ),
                'icon' => 'dashicons-calendar-alt',
                'color' => '#46b450',
                'subtitle' => sprintf( __( 'מתוך %d אירועים', 'at-woo-gf-integration' ), $total_events )
            ),
            array(
                'title' => __( 'הרשמות היום', 'at-woo-gf-integration' ),
                'value' => number_format_i18n( $today_entries ),
                'icon' => 'dashicons-clock',
                'color' => '#f39c12',
                'trend' => $this->get_today_trend()
            ),
            array(
                'title' => __( 'טפסים פעילים', 'at-woo-gf-integration' ),
                'value' => number_format_i18n( $total_forms ),
                'icon' => 'dashicons-feedback',
                'color' => '#8e44ad',
                'subtitle' => sprintf( __( 'עם %d מוצרים', 'at-woo-gf-integration' ), $total_products )
            )
        );

        foreach ( $stats as $stat ) {
            ?>
            <div class="woo-gf-stat-card" style="--stat-color: <?php echo esc_attr( $stat['color'] ); ?>">
                <div class="woo-gf-stat-icon">
                    <span class="dashicons <?php echo esc_attr( $stat['icon'] ); ?>"></span>
                </div>
                <div class="woo-gf-stat-content">
                    <h4 class="woo-gf-stat-title"><?php echo esc_html( $stat['title'] ); ?></h4>
                    <div class="woo-gf-stat-value"><?php echo esc_html( $stat['value'] ); ?></div>
                    <?php if ( isset( $stat['subtitle'] ) ) : ?>
                        <div class="woo-gf-stat-subtitle"><?php echo esc_html( $stat['subtitle'] ); ?></div>
                    <?php endif; ?>
                    <?php if ( isset( $stat['trend'] ) ) : ?>
                        <div class="woo-gf-stat-trend <?php echo esc_attr( $stat['trend']['class'] ); ?>">
                            <span class="dashicons <?php echo esc_attr( $stat['trend']['icon'] ); ?>"></span>
                            <?php echo esc_html( $stat['trend']['text'] ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Render events table
     */
    public function render_events_table() {
        // Set PHP timeout to prevent hangs
        @set_time_limit( 60 );
        
        try {
            $events = $this->get_events_data();
        } catch ( Exception $e ) {
            error_log( 'WooGF Events Table Error: ' . $e->getMessage() );
            echo '<div class="notice notice-error">';
            echo '<p>שגיאה בטעינת נתוני האירועים. אנא נסה לרענן את הדף.</p>';
            echo '<p><small>פרטים טכניים: ' . esc_html( $e->getMessage() ) . '</small></p>';
            echo '</div>';
            return;
        }
        
        // Handle sorting
        $sort_by = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : 'date';
        $sort_order = isset($_GET['sort_order']) ? sanitize_text_field($_GET['sort_order']) : 'asc';
        
        // Sort events
        usort($events, function($a, $b) use ($sort_by, $sort_order) {
            $comparison = 0;
            
            switch($sort_by) {
                case 'price':
                    $price_a = isset($a['price']) ? floatval($a['price']) : 0;
                    $price_b = isset($b['price']) ? floatval($b['price']) : 0;
                    $comparison = $price_a <=> $price_b;
                    break;
                    
                case 'status':
                    // Get form status
                    $status_a = (isset($a['form']) && $a['form'] && (!isset($a['form']['is_active']) || $a['form']['is_active'] !== false)) ? 1 : 0;
                    $status_b = (isset($b['form']) && $b['form'] && (!isset($b['form']['is_active']) || $b['form']['is_active'] !== false)) ? 1 : 0;
                    $comparison = $status_a <=> $status_b;
                    break;
                    
                case 'date':
                default:
                    $date_a = isset($a['event_date']) && $a['event_date'] ? strtotime($a['event_date']) : 0;
                    $date_b = isset($b['event_date']) && $b['event_date'] ? strtotime($b['event_date']) : 0;
                    $comparison = $date_a <=> $date_b;
                    break;
            }
            
            return $sort_order === 'desc' ? -$comparison : $comparison;
        });
        
        if (empty($events)) {
            echo '<div class="woo-gf-empty-state">';
            echo '<h3>אין אירועים להצגה</h3>';
            echo '<p>לא נמצאו אירועים עם טפסי הרשמה מקושרים. צור אירוע חדש או קשר אירוע קיים לטופס Gravity Forms.</p>';
            
            // Debug information
            $this->display_debug_info();
            
            echo '<h4>הוראות:</h4>';
            echo '<ol>';
            echo '<li>צור מוצר חדש מסוג "אירוע"</li>';
            echo '<li>בכרטיסיית "Gravity Forms" בחר טופס</li>';
            echo '<li>בכרטיסיית "פרטי אירוע" הגדר תאריך ומספר משתתפים מקסימלי</li>';
            echo '<li>שמור את המוצר</li>';
            echo '</ol>';
            
            // Add button to create sample event
            if (current_user_can('manage_options')) {
                echo '<div class="woo-gf-sample-event-section">';
                echo '<h4>🎯 יצירת אירוע לדוגמה</h4>';
                echo '<p>לחץ על הכפתור למטה כדי ליצור אירוע לדוגמה עם כל הנתונים הנדרשים לבדיקת הדשבורד.</p>';
                echo '<form method="post" style="display: inline;">';
                wp_nonce_field('woo_gf_create_sample_event', 'woo_gf_nonce');
                echo '<button type="submit" name="woo_gf_create_sample_event" class="woo-gf-btn woo-gf-btn-success">';
                echo '<span class="dashicons dashicons-plus-alt"></span>';
                echo 'צור אירוע לדוגמה';
                echo '</button>';
                echo '</form>';
                echo '</div>';
            }
            
            echo '</div>';
        } else {
            echo '<div class="woo-gf-table-container">';
            echo '<table class="woo-gf-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>פרטי אירוע</th>';
            
            // Date header with sort link
            echo '<th>';
            $next_order = ($sort_by === 'date' && $sort_order === 'asc') ? 'desc' : 'asc';
            $sort_icon = ($sort_by === 'date') ? ('asc' === $sort_order ? ' ↓' : ' ↑') : '';
            echo '<a href="' . esc_url(add_query_arg(array('sort_by' => 'date', 'sort_order' => $next_order))) . '" class="woo-gf-sort-link">';
            echo 'תאריך &amp; שעה' . $sort_icon;
            echo '</a>';
            echo '</th>';
            
            // Price header with sort link
            echo '<th>';
            $next_order = ($sort_by === 'price' && $sort_order === 'asc') ? 'desc' : 'asc';
            $sort_icon = ($sort_by === 'price') ? ('asc' === $sort_order ? ' ↓' : ' ↑') : '';
            echo '<a href="' . esc_url(add_query_arg(array('sort_by' => 'price', 'sort_order' => $next_order))) . '" class="woo-gf-sort-link">';
            echo 'מחיר' . $sort_icon;
            echo '</a>';
            echo '</th>';
            
            echo '<th>פרוייקטור אחראי</th>';
            echo '<th>משתתפים</th>';
            
            // Status header with sort link
            echo '<th>';
            $next_order = ($sort_by === 'status' && $sort_order === 'asc') ? 'desc' : 'asc';
            $sort_icon = ($sort_by === 'status') ? ('asc' === $sort_order ? ' ↓' : ' ↑') : '';
            echo '<a href="' . esc_url(add_query_arg(array('sort_by' => 'status', 'sort_order' => $next_order))) . '" class="woo-gf-sort-link">';
            echo 'סטטוס טופס' . $sort_icon;
            echo '</a>';
            echo '</th>';
            
            echo '<th>פעולות</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($events as $event) {
                $event_id = $event['product_id'];
                $event_date = $event['event_date'] ? strtotime($event['event_date']) : 0;
                $registration_count = $event['registration_count'];
                $capacity = $event['capacity'];
                $available_spots = max(0, $capacity - $registration_count);
                
                // Determine form status (open/closed)
                $form = isset($event['form_id']) ? GFAPI::get_form($event['form_id']) : null;
                $is_form_active = $form && !isset($form['is_active']) || $form['is_active'] !== false;
                $form_status_class = $is_form_active ? 'woo-gf-status-active' : 'woo-gf-status-past';
                $form_status_text = $is_form_active ? '✅ פתוח' : '❌ סגור';
                
                echo '<tr class="woo-gf-event-row" data-event-id="' . esc_attr($event_id) . '">';
                
                // Event Details
                echo '<td>';
                // Event name as clickable link
                echo '<a href="' . esc_url(admin_url('post.php?post=' . $event_id . '&action=edit')) . '" class="woo-gf-event-title-link">' . esc_html($event['title']) . '</a>';
                
                // Form name as clickable link
                echo '<div class="woo-gf-event-meta">';
                echo 'טופס: ';
                if (isset($event['form_id']) && $event['form_id']) {
                    echo '<a href="' . esc_url(admin_url('admin.php?page=gf_edit_forms&id=' . $event['form_id'])) . '" target="_blank">' . esc_html($event['form_title']) . '</a>';
                } else {
                    echo esc_html($event['form_title']);
                }
                echo '</div>';
                
                // Form close date if set
                if (isset($event['form']) && $event['form']) {
                    $form = $event['form'];
                    if (isset($form['scheduleForm']) && $form['scheduleForm'] && isset($form['scheduleEnd'])) {
                        $close_date = strtotime($form['scheduleEnd']);
                        if ($close_date) {
                            echo '<div class="woo-gf-event-meta" style="color: #dc3232; font-size: 12px;">';
                            echo '⏱️ סגירה: ' . date_i18n('j בF H:i', $close_date);
                            echo '</div>';
                        }
                    }
                }
                
                // Capacity/Tickets
                echo '<div class="woo-gf-event-meta" style="color: #0073aa; font-weight: 500;">';
                if ($capacity > 0) {
                    echo '🎫 ' . number_format_i18n($capacity) . ' כרטיסים';
                } else {
                    echo '🎫 ∞ (ללא הגבלה)';
                }
                echo '</div>';
                
                echo '</td>';
                
                // Event Date & Time
                echo '<td>';
                if ($event_date) {
                    echo '<div class="woo-gf-event-date-time">';
                    echo '<div class="woo-gf-event-meta" style="font-weight: 600;">' . date_i18n('j בF', $event_date) . '</div>';
                    echo '<div class="woo-gf-event-meta" style="color: #0073aa;">' . date_i18n('H:i', $event_date) . '</div>';
                    echo '</div>';
                } else {
                    echo '<div class="woo-gf-event-meta" style="color: #999;">ללא תאריך</div>';
                }
                echo '</td>';
                
                // Price
                echo '<td>';
                $price = isset($event['price']) ? $event['price'] : 0;
                if ($price > 0) {
                    echo '<div class="woo-gf-event-title">' . wc_price($price) . '</div>';
                } else {
                    echo '<div class="woo-gf-event-badge" style="background: #e7f7e8; color: #46b450;">🎉 חינמי</div>';
                }
                echo '</td>';
                
                // Event Manager
                echo '<td>';
                $manager = isset($event['manager']) ? $event['manager'] : '';
                if (!empty($manager)) {
                    echo '<div class="woo-gf-event-meta">' . esc_html($manager) . '</div>';
                } else {
                    echo '<div class="woo-gf-event-meta" style="color: #999;">לא צוין</div>';
                }
                echo '</td>';
                
                // Participants Summary (combined registrations and available spots)
                echo '<td>';
                $capacity_percentage = $capacity > 0 ? ($registration_count / $capacity) * 100 : 0;
                $capacity_class = $capacity_percentage >= 90 ? 'woo-gf-capacity-high' : 
                                ($capacity_percentage >= 70 ? 'woo-gf-capacity-medium' : 'woo-gf-capacity-low');
                
                echo '<div class="woo-gf-participants-summary">';
                echo '<div class="woo-gf-event-title">' . number_format_i18n($registration_count);
                if ($capacity > 0) {
                    echo ' / ' . number_format_i18n($capacity);
                }
                echo '</div>';
                
                if ($capacity > 0) {
                    echo '<div class="woo-gf-capacity-bar">';
                    echo '<div class="woo-gf-capacity-fill ' . $capacity_class . '" style="width: ' . min(100, $capacity_percentage) . '%"></div>';
                    echo '</div>';
                    echo '<div class="woo-gf-event-meta">' . number_format_i18n($available_spots) . ' פנויים</div>';
                } else {
                    echo '<div class="woo-gf-event-meta" style="color: #999;">ללא הגבלה</div>';
                }
                echo '</div>';
                echo '</td>';
                
                // Form Status
                echo '<td>';
                echo '<span class="woo-gf-status-badge ' . $form_status_class . '">' . $form_status_text . '</span>';
                echo '</td>';
                
                // Actions
                echo '<td>';
                echo '<div class="woo-gf-action-buttons">';
                echo '<a href="' . admin_url('post.php?post=' . $event_id . '&action=edit') . '" class="woo-gf-btn woo-gf-btn-secondary woo-gf-btn-sm" title="ערוך אירוע">';
                echo '<span class="dashicons dashicons-edit"></span>';
                echo '</a>';
                
                // View registrations button
                $has_registrations = $registration_count > 0;
                echo '<button type="button" class="woo-gf-btn woo-gf-btn-primary woo-gf-btn-sm view-registrations" ';
                echo 'data-event-id="' . esc_attr($event_id) . '" ';
                echo 'data-form-id="' . esc_attr($event['form_id']) . '" ';
                echo 'data-event-title="' . esc_attr($event['title']) . '" ';
                echo 'title="' . ($has_registrations ? 'צפה בנרשמים' : 'אין נרשמים') . '">';
                echo '<span class="dashicons dashicons-groups"></span>';
                echo '</button>';
                
                // Export button - disabled if no registrations
                echo '<button type="button" class="woo-gf-btn woo-gf-btn-success woo-gf-btn-sm export-registrations" ';
                echo 'data-event-id="' . esc_attr($event_id) . '" ';
                echo 'data-form-id="' . esc_attr($event['form_id']) . '" ';
                echo 'data-event-title="' . esc_attr($event['title']) . '" ';
                echo ($has_registrations ? '' : 'disabled="disabled" ');
                echo 'title="' . ($has_registrations ? 'ייצוא לאקסל' : 'אין הרשמות לייצוא') . '">';
                echo '<span class="dashicons dashicons-download"></span>';
                echo '</button>';
                
                echo '</div>';
                echo '</td>';
                
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>'; // Close woo-gf-table-container
        }
    }

    /**
     * Get events with registrations data
     */
    private function get_events_with_registrations() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'compare' => 'EXISTS',
                ),
            ),
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => 'event',
                ),
            ),
        );
        
        $products = get_posts( $args );
        $events = array();
        
        foreach ( $products as $product ) {
            $wc_product = wc_get_product( $product->ID );
            if ( ! $wc_product ) continue;
            
            $form_id = $wc_product->get_meta( '_woo_gf_form_id', true );
            if ( ! $form_id ) continue;
            
            // Get registrations count
            $search_criteria = array(
                'status' => 'active',
                'field_filters' => array(
                    array(
                        'key' => 'woo_gf_product_id',
                        'value' => $product->ID,
                    ),
                ),
            );
            $registrations = GFAPI::count_entries( $form_id, $search_criteria );
            
            // Get event data
            $event_date = $wc_product->get_meta( '_event_date', true );
            $event_end_date = $wc_product->get_meta( '_event_end_date', true );
            $event_location = $wc_product->get_meta( '_event_location', true );
            $max_attendees = $wc_product->get_meta( '_max_attendees', true );
            $event_type = $wc_product->get_meta( '_event_type', true );
            
            $events[] = array(
                'id' => $product->ID,
                'title' => $product->post_title,
                'date' => $event_date,
                'end_date' => $event_end_date,
                'date_display' => $event_date ? date_i18n( 'd/m/Y', strtotime( $event_date ) ) : '-',
                'time_display' => $event_date ? date_i18n( 'H:i', strtotime( $event_date ) ) : '',
                'location' => $event_location,
                'max_attendees' => intval( $max_attendees ),
                'registrations' => $registrations,
                'type' => $this->get_event_type_display( $event_type ),
                'status' => $this->get_event_status( $event_date, $event_end_date, $registrations, $max_attendees ),
            );
        }
        
        // Sort by date
        usort( $events, function( $a, $b ) {
            if ( ! $a['date'] && ! $b['date'] ) return 0;
            if ( ! $a['date'] ) return 1;
            if ( ! $b['date'] ) return -1;
            return strtotime( $a['date'] ) - strtotime( $b['date'] );
        });
        
        return $events;
    }

    /**
     * Get event type display
     */
    private function get_event_type_display( $type ) {
        $types = array(
            'physical' => __( '📍 פיזי', 'at-woo-gf-integration' ),
            'virtual' => __( '💻 מקוון', 'at-woo-gf-integration' ),
            'hybrid' => __( '🔄 משולב', 'at-woo-gf-integration' ),
        );
        
        return isset( $types[ $type ] ) ? $types[ $type ] : '';
    }

    /**
     * Get event status
     */
    private function get_event_status( $date, $end_date, $registrations, $max_attendees ) {
        if ( ! $date ) return 'draft';
        
        $now = current_time( 'timestamp' );
        $event_time = strtotime( $date );
        $end_time = $end_date ? strtotime( $end_date ) : $event_time;
        
        if ( $now < $event_time ) {
            if ( $max_attendees > 0 && $registrations >= $max_attendees ) {
                return 'full';
            }
            return 'upcoming';
        } elseif ( $now >= $event_time && $now <= $end_time ) {
            return 'ongoing';
        } else {
            return 'past';
        }
    }

    /**
     * Get event status badge
     */
    private function get_event_status_badge( $event ) {
        $status = $event['status'];
        $badges = array(
            'upcoming' => array(
                'text' => __( 'קרוב', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-status-upcoming',
                'icon' => 'dashicons-clock'
            ),
            'ongoing' => array(
                'text' => __( 'מתקיים', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-status-ongoing',
                'icon' => 'dashicons-yes-alt'
            ),
            'past' => array(
                'text' => __( 'הסתיים', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-status-past',
                'icon' => 'dashicons-yes'
            ),
            'full' => array(
                'text' => __( 'מלא', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-status-full',
                'icon' => 'dashicons-warning'
            ),
            'draft' => array(
                'text' => __( 'טיוטה', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-status-draft',
                'icon' => 'dashicons-edit'
            )
        );
        
        if ( ! isset( $badges[ $status ] ) ) {
            return '';
        }
        
        $badge = $badges[ $status ];
        return sprintf(
            '<span class="woo-gf-status-badge %s"><span class="dashicons %s"></span>%s</span>',
            esc_attr( $badge['class'] ),
            esc_attr( $badge['icon'] ),
            esc_html( $badge['text'] )
        );
    }

    /**
     * Get events count (optimized with caching)
     */
    private function get_events_count() {
        // Use transient cache for 5 minutes
        $cache_key = 'woo_gf_events_count';
        $count = get_transient( $cache_key );
        
        if ( false !== $count ) {
            return $count;
        }
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        
        $query = new WP_Query( $args );
        $count = $query->found_posts;
        
        set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );
        return $count;
    }

    /**
     * Get active events count (optimized with caching)
     */
    private function get_active_events_count() {
        // Use transient cache for 5 minutes
        $cache_key = 'woo_gf_active_events_count';
        $count = get_transient( $cache_key );
        
        if ( false !== $count ) {
            return $count;
        }
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 100, // Limit to prevent memory issues
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key' => '_event_date',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        
        $product_ids = get_posts( $args );
        $active_count = 0;
        
        if ( ! empty( $product_ids ) ) {
            $now = current_time( 'timestamp' );
            
            foreach ( $product_ids as $product_id ) {
                $event_date = get_post_meta( $product_id, '_event_date', true );
                if ( ! $event_date ) continue;
                
                $event_time = strtotime( $event_date );
                $end_date = get_post_meta( $product_id, '_event_end_date', true );
                $end_time = $end_date ? strtotime( $end_date ) : $event_time;
                
                if ( $now >= $event_time && $now <= $end_time ) {
                    $active_count++;
                }
            }
        }
        
        set_transient( $cache_key, $active_count, 5 * MINUTE_IN_SECONDS );
        return $active_count;
    }

    /**
     * Get registrations trend
     */
    private function get_registrations_trend() {
        $today = $this->get_today_entries_count();
        $yesterday = $this->get_yesterday_entries_count();
        
        if ( $yesterday == 0 ) {
            return array(
                'text' => __( 'חדש', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-trend-up',
                'icon' => 'dashicons-plus'
            );
        }
        
        $change = $today - $yesterday;
        $percentage = $yesterday > 0 ? ( $change / $yesterday ) * 100 : 0;
        
        if ( $change > 0 ) {
            return array(
                'text' => sprintf( __( '+%d%%', 'at-woo-gf-integration' ), round( $percentage ) ),
                'class' => 'woo-gf-trend-up',
                'icon' => 'dashicons-arrow-up-alt'
            );
        } elseif ( $change < 0 ) {
            return array(
                'text' => sprintf( __( '%d%%', 'at-woo-gf-integration' ), round( $percentage ) ),
                'class' => 'woo-gf-trend-down',
                'icon' => 'dashicons-arrow-down-alt'
            );
        } else {
            return array(
                'text' => __( 'יציב', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-trend-stable',
                'icon' => 'dashicons-minus'
            );
        }
    }

    /**
     * Get today trend
     */
    private function get_today_trend() {
        $today = $this->get_today_entries_count();
        $yesterday = $this->get_yesterday_entries_count();
        
        if ( $today > $yesterday ) {
            return array(
                'text' => __( 'עלייה', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-trend-up',
                'icon' => 'dashicons-arrow-up-alt'
            );
        } elseif ( $today < $yesterday ) {
            return array(
                'text' => __( 'ירידה', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-trend-down',
                'icon' => 'dashicons-arrow-down-alt'
            );
        } else {
            return array(
                'text' => __( 'יציב', 'at-woo-gf-integration' ),
                'class' => 'woo-gf-trend-stable',
                'icon' => 'dashicons-minus'
            );
        }
    }

    /**
     * Get yesterday's entries count (with caching)
     */
    private function get_yesterday_entries_count() {
        // Use transient cache that expires at midnight
        $cache_key = 'woo_gf_yesterday_entries_count_' . date('Ymd', strtotime('-1 day'));
        $count = get_transient( $cache_key );
        
        if ( false === $count ) {
            $count = 0;
                $forms = $this->get_cached_forms();
            
            if ( ! empty( $forms ) && count( $forms ) < 50 ) {
                // Only count if reasonable number of forms
                $search_criteria = array(
                    'status' => 'active',
                    'start_date' => date( 'Y-m-d 00:00:00', strtotime( '-1 day' ) ),
                    'end_date' => date( 'Y-m-d 23:59:59', strtotime( '-1 day' ) ),
                );
                
                foreach ( $forms as $form ) {
                    $count += GFAPI::count_entries( $form['id'], $search_criteria );
                }
            }
            
            set_transient( $cache_key, $count, 24 * HOUR_IN_SECONDS );
        }
        
        return $count;
    }

    /**
     * AJAX handler for getting event registrations
     */
    public function ajax_get_event_registrations() {
        check_ajax_referer( 'woo_gf_dashboard_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'אין לך הרשאה לבצע פעולה זו.', 'at-woo-gf-integration' ) );
        }
        
        $event_id = intval( $_POST['event_id'] );
        $event_title = sanitize_text_field( $_POST['event_title'] );
        
        $wc_product = wc_get_product( $event_id );
        if ( ! $wc_product ) {
            wp_send_json_error( __( 'אירוע לא נמצא.', 'at-woo-gf-integration' ) );
        }
        
        $form_id = $wc_product->get_meta( '_woo_gf_form_id', true );
        if ( ! $form_id ) {
            wp_send_json_error( __( 'לא נמצא טופס הרשמה לאירוע זה.', 'at-woo-gf-integration' ) );
        }
        
        // Get ALL entries from this form, not just active ones
        // First try with product_id meta filter
        $search_criteria = array(
            'field_filters' => array(
                array(
                    'key' => 'woo_gf_product_id',
                    'value' => $event_id,
                ),
            ),
        );
        
        // Check if we found any entries with this criteria
        $test_count = GFAPI::count_entries( $form_id, $search_criteria );
        
        // If no entries found with meta, get ALL entries from the form
        if ( $test_count == 0 ) {
            $search_criteria = array(); // Empty criteria = all entries
        }
        
        $sorting = array( 'key' => 'date_created', 'direction' => 'DESC' );
        $paging = array( 'offset' => 0, 'page_size' => 50 );
        
        $entries = GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging );
        
        if ( empty( $entries ) ) {
            wp_send_json_success( array(
                'html' => '<div class="woo-gf-empty-state"><p>' . __( 'לא נמצאו הרשמות לאירוע זה.', 'at-woo-gf-integration' ) . '</p></div>'
            ));
        }
        
        $form = GFAPI::get_form( $form_id );
        $html = $this->render_registrations_table_html( $entries, $form, $event_title );
        
        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * Render registrations table HTML for sidepeek
     */
    private function render_registrations_table_html( $entries, $form, $event_title ) {
        ob_start();
        ?>
        <div class="woo-gf-registrations-sidepeek">
            <div class="woo-gf-registrations-header">
                <h4><?php echo esc_html( $event_title ); ?></h4>
                <p><?php printf( esc_html__( '%d הרשמות', 'at-woo-gf-integration' ), count( $entries ) ); ?></p>
            </div>
            
            <div class="woo-gf-registrations-table">
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'שם', 'at-woo-gf-integration' ); ?></th>
                            <th><?php esc_html_e( 'אימייל', 'at-woo-gf-integration' ); ?></th>
                            <th><?php esc_html_e( 'טלפון', 'at-woo-gf-integration' ); ?></th>
                            <th><?php esc_html_e( 'תאריך הרשמה', 'at-woo-gf-integration' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $entries as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $this->get_entry_name( $entry, $form ) ); ?></td>
                                <td><?php echo esc_html( $this->get_entry_email( $entry, $form ) ); ?></td>
                                <td><?php echo esc_html( $this->get_entry_phone( $entry, $form ) ); ?></td>
                                <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $entry['date_created'] ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get entry phone
     */
    private function get_entry_phone( $entry, $form ) {
        foreach ( $form['fields'] as $field ) {
            if ( 'phone' === $field->type && ! empty( $entry[ $field->id ] ) ) {
                return $entry[ $field->id ];
            }
        }
        
        return '-';
    }

    /**
     * Render forms dropdown
     */
    private function render_forms_dropdown() {
        $forms = $this->get_cached_forms();
        $selected_form = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : 0;
        ?>
        <select name="form_id" id="woo-gf-form-filter">
            <option value=""><?php esc_html_e( 'כל הטפסים', 'at-woo-gf-integration' ); ?></option>
            <?php foreach ( $forms as $form ) : ?>
                <option value="<?php echo esc_attr( $form['id'] ); ?>" 
                        <?php selected( $selected_form, $form['id'] ); ?>>
                    <?php echo esc_html( $form['title'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render products dropdown
     */
    private function render_products_dropdown() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        
        $products = get_posts( $args );
        $selected_product = isset( $_GET['product_id'] ) ? intval( $_GET['product_id'] ) : 0;
        ?>
        <select name="product_id" id="woo-gf-product-filter">
            <option value=""><?php esc_html_e( 'כל המוצרים', 'at-woo-gf-integration' ); ?></option>
            <?php foreach ( $products as $product ) : ?>
                <option value="<?php echo esc_attr( $product->ID ); ?>" 
                        <?php selected( $selected_product, $product->ID ); ?>>
                    <?php echo esc_html( $product->post_title ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render entries table
     */
    private function render_entries_table() {
        // Get search parameters
        $search_criteria = $this->build_search_criteria();
        $paging = array( 'offset' => 0, 'page_size' => 20 );
        $sorting = array( 'key' => 'date_created', 'direction' => 'DESC' );
        
        // Get current page
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $paging['offset'] = ( $current_page - 1 ) * $paging['page_size'];
        
        // Get entries
        $total_count = 0;
        $entries = $this->get_all_entries( $search_criteria, $sorting, $paging, $total_count );
        
        if ( empty( $entries ) ) {
            echo '<div class="woo-gf-empty-state">';
            echo '<span class="dashicons dashicons-list-view"></span>';
            echo '<p>' . esc_html__( 'לא נמצאו הרשמות.', 'at-woo-gf-integration' ) . '</p>';
            echo '</div>';
            return;
        }
        
        // Calculate pagination
        $total_pages = ceil( $total_count / $paging['page_size'] );
        
        ?>
        <div class="woo-gf-table-container">
            <table class="woo-gf-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'מזהה', 'at-woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'טופס', 'at-woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'מוצר', 'at-woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'שם', 'at-woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'אימייל', 'at-woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'תאריך', 'at-woo-gf-integration' ); ?></th>
                        <th><?php esc_html_e( 'פעולות', 'at-woo-gf-integration' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <?php $this->render_entry_row( $entry ); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ( $total_pages > 1 ) : ?>
                <div class="woo-gf-pagination">
                    <?php
                    $pagination = paginate_links( array(
                        'base' => add_query_arg( 'paged', '%#%' ),
                        'format' => '',
                        'prev_text' => '<span class="dashicons dashicons-arrow-right-alt2"></span>',
                        'next_text' => '<span class="dashicons dashicons-arrow-left-alt2"></span>',
                        'total' => $total_pages,
                        'current' => $current_page,
                        'type' => 'plain',
                    ) );
                    echo $pagination;
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render single entry row
     */
    private function render_entry_row( $entry ) {
        $form = GFAPI::get_form( $entry['form_id'] );
        $product_id = $this->get_product_by_entry( $entry );
        $product_title = $product_id ? get_the_title( $product_id ) : '-';
        
        // Get name and email from entry
        $name = $this->get_entry_name( $entry, $form );
        $email = $this->get_entry_email( $entry, $form );
        
        ?>
        <tr>
            <td>
                <span class="woo-gf-entry-id">#<?php echo esc_html( $entry['id'] ); ?></span>
            </td>
            <td>
                <div class="woo-gf-form-name">
                    <span class="dashicons dashicons-feedback"></span>
                    <?php echo esc_html( $form['title'] ); ?>
                </div>
            </td>
            <td>
                <div class="woo-gf-product-name">
                    <?php if ( $product_id ) : ?>
                        <span class="dashicons dashicons-cart"></span>
                        <a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>" target="_blank">
                            <?php echo esc_html( $product_title ); ?>
                        </a>
                    <?php else : ?>
                        <span class="woo-gf-no-product"><?php esc_html_e( 'לא צוין', 'at-woo-gf-integration' ); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="woo-gf-entry-name">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php echo esc_html( $name ); ?>
                </div>
            </td>
            <td>
                <div class="woo-gf-entry-email">
                    <span class="dashicons dashicons-email"></span>
                    <a href="mailto:<?php echo esc_attr( $email ); ?>">
                        <?php echo esc_html( $email ); ?>
                    </a>
                </div>
            </td>
            <td>
                <div class="woo-gf-entry-date">
                    <span class="dashicons dashicons-calendar"></span>
                    <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $entry['date_created'] ) ) ); ?>
                </div>
            </td>
            <td>
                <div class="woo-gf-entry-actions">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry['id'] ) ); ?>" 
                       class="button button-small button-primary" target="_blank"
                       title="<?php esc_attr_e( 'צפה בפרטי הרשמה', 'at-woo-gf-integration' ); ?>">
                        <span class="dashicons dashicons-visibility"></span>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry['id'] . '&screen_mode=edit' ) ); ?>" 
                       class="button button-small" target="_blank"
                       title="<?php esc_attr_e( 'ערוך הרשמה', 'at-woo-gf-integration' ); ?>">
                        <span class="dashicons dashicons-edit"></span>
                    </a>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Get entry name
     */
    private function get_entry_name( $entry, $form ) {
        // Try to find name fields
        foreach ( $form['fields'] as $field ) {
            if ( 'name' === $field->type ) {
                $name_parts = array();
                if ( ! empty( $entry[ $field->id . '.3' ] ) ) {
                    $name_parts[] = $entry[ $field->id . '.3' ]; // First name
                }
                if ( ! empty( $entry[ $field->id . '.6' ] ) ) {
                    $name_parts[] = $entry[ $field->id . '.6' ]; // Last name
                }
                if ( ! empty( $name_parts ) ) {
                    return implode( ' ', $name_parts );
                }
            }
        }
        
        return '-';
    }

    /**
     * Get entry email
     */
    private function get_entry_email( $entry, $form ) {
        // Try to find email field
        foreach ( $form['fields'] as $field ) {
            if ( 'email' === $field->type && ! empty( $entry[ $field->id ] ) ) {
                return $entry[ $field->id ];
            }
        }
        
        return '-';
    }

    /**
     * Build search criteria from GET parameters
     */
    private function build_search_criteria() {
        $search_criteria = array();
        
        // Search text
        if ( ! empty( $_GET['search'] ) ) {
            $search_criteria['field_filters'][] = array(
                'key' => 0, // Search all fields
                'value' => sanitize_text_field( $_GET['search'] ),
                'operator' => 'contains',
            );
        }
        
        // Date range
        if ( ! empty( $_GET['date_from'] ) ) {
            $search_criteria['start_date'] = sanitize_text_field( $_GET['date_from'] );
        }
        
        if ( ! empty( $_GET['date_to'] ) ) {
            $search_criteria['end_date'] = sanitize_text_field( $_GET['date_to'] );
        }
        
        // Status
        $search_criteria['status'] = 'active';
        
        return $search_criteria;
    }

    /**
     * Get all entries from all forms or specific form (optimized)
     */
    private function get_all_entries( $search_criteria, $sorting, $paging, &$total_count ) {
        $entries = array();
        
        // Check if specific form is selected
        if ( ! empty( $_GET['form_id'] ) ) {
            $form_id = intval( $_GET['form_id'] );
            
            try {
                $entries = GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging, $total_count );
            } catch ( Exception $e ) {
                error_log( 'WooGF Error getting entries for form ' . $form_id . ': ' . $e->getMessage() );
                return array();
            }
        } else {
            // Get entries from all forms
            $forms = $this->get_cached_forms();
            
            // Limit number of forms to prevent memory issues
            if ( count( $forms ) > 20 ) {
                $forms = array_slice( $forms, 0, 20 );
            }
            
            $form_ids = wp_list_pluck( $forms, 'id' );
            
            // If product filter is set, get only forms associated with that product
            if ( ! empty( $_GET['product_id'] ) ) {
                $product_id = intval( $_GET['product_id'] );
                $form_id = get_post_meta( $product_id, '_woo_gf_form_id', true );
                if ( $form_id ) {
                    $form_ids = array( $form_id );
                }
            }
            
            try {
                $entries = GFAPI::get_entries( $form_ids, $search_criteria, $sorting, $paging, $total_count );
            } catch ( Exception $e ) {
                error_log( 'WooGF Error getting entries for all forms: ' . $e->getMessage() );
                return array();
            }
        }
        
        return $entries;
    }

    /**
     * Get total entries count (with caching)
     */
    private function get_total_entries_count() {
        // Use transient cache for 5 minutes
        $cache_key = 'woo_gf_total_entries_count';
        $count = get_transient( $cache_key );
        
        if ( false === $count ) {
            $count = 0;
                $forms = $this->get_cached_forms();
            
            if ( ! empty( $forms ) && count( $forms ) < 50 ) {
                // Only count if reasonable number of forms
                foreach ( $forms as $form ) {
                    $count += GFAPI::count_entries( $form['id'], array( 'status' => 'active' ) );
                }
            }
            
            set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );
        }
        
        return $count;
    }

    /**
     * Get today's entries count (with caching)
     */
    private function get_today_entries_count() {
        // Use transient cache for 5 minutes
        $cache_key = 'woo_gf_today_entries_count_' . date('Ymd');
        $count = get_transient( $cache_key );
        
        if ( false === $count ) {
            $count = 0;
                $forms = $this->get_cached_forms();
            
            if ( ! empty( $forms ) && count( $forms ) < 50 ) {
                // Only count if reasonable number of forms
                $search_criteria = array(
                    'status' => 'active',
                    'start_date' => date( 'Y-m-d 00:00:00' ),
                    'end_date' => date( 'Y-m-d 23:59:59' ),
                );
                
                foreach ( $forms as $form ) {
                    $count += GFAPI::count_entries( $form['id'], $search_criteria );
                }
            }
            
            set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );
        }
        
        return $count;
    }

    /**
     * Get count of products with forms
     */
    private function get_products_with_forms_count() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        
        $query = new WP_Query( $args );
        return $query->found_posts;
    }

    /**
     * Get product by entry
     */
    private function get_product_by_entry( $entry ) {
        // Try to get product from entry meta
        $product_id = gform_get_meta( $entry['id'], 'woo_gf_product_id' );
        
        if ( $product_id ) {
            return $product_id;
        }
        
        // Fallback: Find product by form ID
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'value' => $entry['form_id'],
                ),
            ),
        );
        
        $products = get_posts( $args );
        
        if ( ! empty( $products ) ) {
            return $products[0]->ID;
        }
        
        return 0;
    }

    /**
     * Get registrations count for a specific form and product (with caching)
     */
    private function get_registration_count( $form_id, $product_id ) {
        // Use transient cache for 2 minutes
        $cache_key = 'woo_gf_reg_count_' . $form_id . '_' . $product_id;
        $count = get_transient( $cache_key );
        
        if ( false === $count ) {
            // First try with product_id filter
            $search_criteria = array(
                'status' => 'active',
                'field_filters' => array(
                    array(
                        'key' => 'woo_gf_product_id',
                        'value' => $product_id,
                    ),
                ),
            );
            
            $count = GFAPI::count_entries( $form_id, $search_criteria );
            
            // If no entries found with meta, count ALL entries from the form as fallback
            if ( $count == 0 ) {
                $count = GFAPI::count_entries( $form_id, array( 'status' => 'active' ) );
            }
            
            set_transient( $cache_key, $count, 2 * MINUTE_IN_SECONDS );
        }
        
        return $count;
    }

    /**
     * Get events data with registration information (optimized with caching)
     */
    private function get_events_data() {
        $events = array();
        
        // Check if Gravity Forms is active
        if ( ! class_exists( 'GFAPI' ) ) {
            echo '<div class="notice notice-error">';
            echo '<h3>Gravity Forms לא פעיל</h3>';
            echo '<p>הדשבורד דורש Gravity Forms להיות מותקן ופעיל.</p>';
            echo '</div>';
            return $events;
        }
        
        // Use transient cache for events data (2 minutes)
        $cache_key = 'woo_gf_events_data_v2';
        $events = get_transient( $cache_key );
        
        if ( false !== $events ) {
            return $events;
        }
        
        // Get all products that have forms linked to them - optimized query
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 100, // Limit to prevent memory issues
            'fields' => 'ids', // Get IDs only first for speed
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        
        $product_ids = get_posts( $args );
        
        if ( empty( $product_ids ) ) {
            // Cache empty result for 30 seconds to prevent repeated queries
            set_transient( $cache_key, array(), 30 );
            return array();
        }
        
        $events = array();
        $batch_forms = array(); // Cache forms to avoid repeated GFAPI calls
        
        foreach ( $product_ids as $product_id ) {
            $product = get_post( $product_id );
            if ( ! $product ) continue;
            
            $product_obj = wc_get_product( $product_id );
            if ( ! $product_obj ) continue;
            
            // Get form ID from product meta
            $form_id = get_post_meta( $product_id, '_woo_gf_form_id', true );
            if ( ! $form_id ) continue;
            
            // Get form from cache or fetch once
            if ( ! isset( $batch_forms[ $form_id ] ) ) {
                $batch_forms[ $form_id ] = GFAPI::get_form( $form_id );
            }
            $form = $batch_forms[ $form_id ];
            $form_title = $form ? $form['title'] : '';
            
            // Get registration count (uses its own caching)
            $registration_count = $this->get_registration_count( $form_id, $product_id );
            
            // Get event date - check multiple possible meta fields
            $event_date = get_post_meta( $product_id, '_event_date', true );
            if ( ! $event_date ) {
                $event_date = get_post_meta( $product_id, '_event_start_date', true );
            }
            
            // Get capacity - check multiple possible meta fields
            $capacity = get_post_meta( $product_id, '_event_capacity', true );
            if ( ! $capacity ) {
                $capacity = get_post_meta( $product_id, '_max_attendees', true );
            }
            if ( ! $capacity ) {
                $capacity = get_post_meta( $product_id, '_event_max_participants', true );
            }
            $capacity = $capacity ? intval( $capacity ) : 0;
            
            $events[] = array(
                'product_id' => $product_id,
                'title' => $product->post_title,
                'form_id' => $form_id,
                'form' => $form,
                'form_title' => $form_title,
                'registration_count' => $registration_count,
                'capacity' => $capacity,
                'event_date' => $event_date,
                'image_url' => '', // Skip image for performance
                'product_url' => get_edit_post_link( $product_id ),
                'is_event' => true,
                'product_type' => $product_obj->get_type(),
                'price' => $product_obj->get_price(),
                'manager' => get_post_meta( $product_id, '_event_manager', true )
            );
        }
        
        // Sort by event date (if available) or by title
        usort( $events, function( $a, $b ) {
            if ( $a['event_date'] && $b['event_date'] ) {
                return strtotime( $a['event_date'] ) - strtotime( $b['event_date'] );
            }
            return strcmp( $a['title'], $b['title'] );
        } );
        
        // Cache for 2 minutes
        set_transient( $cache_key, $events, 2 * MINUTE_IN_SECONDS );
        
        return $events;
    }

    /**
     * Register AJAX handlers
     */
    public function register_ajax_handlers() {
        add_action( 'wp_ajax_woo_gf_get_event_details', array( $this, 'ajax_get_event_details' ) );
        add_action( 'wp_ajax_woo_gf_get_registrations', array( $this, 'ajax_get_registrations' ) );
        add_action( 'wp_ajax_woo_gf_export_registrations', array( $this, 'ajax_export_registrations' ) );
        add_action( 'wp_ajax_woo_gf_clear_dashboard_cache', array( $this, 'ajax_clear_dashboard_cache' ) );
    }
    
    /**
     * Clear dashboard cache (called on refresh)
     */
    public function ajax_clear_dashboard_cache() {
        check_ajax_referer( 'woo_gf_dashboard_nonce', 'nonce' );
        $this->clear_all_caches();
        wp_send_json_success( array( 'message' => __( 'המטמון נוקה בהצלחה', 'at-woo-gf-integration' ) ) );
    }
    
    /**
     * Clear all dashboard caches
     */
    private function clear_all_caches() {
        // Clear all dashboard caches
        delete_transient( 'woo_gf_events_data_v2' );
        delete_transient( 'woo_gf_total_entries_count' );
        delete_transient( 'woo_gf_today_entries_count_' . date('Ymd') );
        delete_transient( 'woo_gf_yesterday_entries_count_' . date('Ymd', strtotime('-1 day')) );
        delete_transient( 'woo_gf_active_events_count' );
        delete_transient( 'woo_gf_events_count' );
        delete_transient( 'woo_gf_all_forms' ); // Clear forms cache
        
        // Clear all registration count caches
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woo_gf_reg_count_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_woo_gf_reg_count_%'" );
    }

    /**
     * AJAX handler for getting event details
     */
    public function ajax_get_event_details() {
        check_ajax_referer( 'woo_gf_dashboard_nonce', 'nonce' );
        
        $product_id = intval( $_POST['product_id'] );
        
        if ( ! $product_id ) {
            wp_die( 'Invalid product ID' );
        }
        
        $product = get_post( $product_id );
        if ( ! $product || $product->post_type !== 'product' ) {
            wp_die( 'Product not found' );
        }
        
        $product_obj = wc_get_product( $product_id );
        $form_id = get_post_meta( $product_id, '_woo_gf_form_id', true );
        $event_date = get_post_meta( $product_id, '_event_date', true );
        $capacity = get_post_meta( $product_id, '_event_capacity', true );
        $registration_count = $this->get_registration_count( $form_id, $product_id );
        
        $form = GFAPI::get_form( $form_id );
        $form_title = $form ? $form['title'] : '';
        
        $image_id = get_post_thumbnail_id( $product_id );
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
        
        $percentage = $capacity > 0 ? ( $registration_count / $capacity ) * 100 : 0;
        
        ob_start();
        ?>
        <div class="woo-gf-event-details">
            <div class="woo-gf-event-header">
                <?php if ( $image_url ) : ?>
                    <div class="woo-gf-event-image">
                        <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product->post_title ); ?>">
                    </div>
                <?php endif; ?>
                <div class="woo-gf-event-info">
                    <h2><?php echo esc_html( $product->post_title ); ?></h2>
                    <p class="woo-gf-event-description"><?php echo esc_html( $product->post_excerpt ?: $product->post_content ); ?></p>
                </div>
            </div>
            
            <div class="woo-gf-event-stats">
                <div class="woo-gf-stat-item">
                    <span class="woo-gf-stat-label"><?php esc_html_e( 'טופס הרשמה', 'at-woo-gf-integration' ); ?></span>
                    <span class="woo-gf-stat-value"><?php echo esc_html( $form_title ); ?></span>
                </div>
                
                <?php if ( $event_date ) : ?>
                <div class="woo-gf-stat-item">
                    <span class="woo-gf-stat-label"><?php esc_html_e( 'תאריך האירוע', 'at-woo-gf-integration' ); ?></span>
                    <span class="woo-gf-stat-value"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $event_date ) ) ); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="woo-gf-stat-item">
                    <span class="woo-gf-stat-label"><?php esc_html_e( 'נרשמים', 'at-woo-gf-integration' ); ?></span>
                    <span class="woo-gf-stat-value"><?php echo esc_html( $registration_count ); ?></span>
                </div>
                
                <?php if ( $capacity > 0 ) : ?>
                <div class="woo-gf-stat-item">
                    <span class="woo-gf-stat-label"><?php esc_html_e( 'קיבולת', 'at-woo-gf-integration' ); ?></span>
                    <span class="woo-gf-stat-value"><?php echo esc_html( $capacity ); ?></span>
                </div>
                
                <div class="woo-gf-stat-item">
                    <span class="woo-gf-stat-label"><?php esc_html_e( 'מקומות פנויים', 'at-woo-gf-integration' ); ?></span>
                    <span class="woo-gf-stat-value"><?php echo esc_html( max( 0, $capacity - $registration_count ) ); ?></span>
                </div>
                
                <div class="woo-gf-stat-item">
                    <span class="woo-gf-stat-label"><?php esc_html_e( 'אחוז מילוי', 'at-woo-gf-integration' ); ?></span>
                    <span class="woo-gf-stat-value"><?php echo esc_html( round( $percentage, 1 ) ); ?>%</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="woo-gf-event-actions">
                <a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'ערוך אירוע', 'at-woo-gf-integration' ); ?>
                </a>
                <button type="button" class="button woo-gf-btn-view-registrations" 
                        data-form-id="<?php echo esc_attr( $form_id ); ?>"
                        data-product-id="<?php echo esc_attr( $product_id ); ?>">
                    <?php esc_html_e( 'צפה בנרשמים', 'at-woo-gf-integration' ); ?>
                </button>
            </div>
        </div>
        <?php
        
        $content = ob_get_clean();
        wp_send_json_success( array( 'content' => $content ) );
    }

    /**
     * AJAX handler for getting registrations
     */
    public function ajax_get_registrations() {
        check_ajax_referer( 'woo_gf_dashboard_nonce', 'nonce' );
        
        $form_id = intval( $_POST['form_id'] );
        $product_id = intval( $_POST['product_id'] );
        
        if ( ! $form_id || ! $product_id ) {
            wp_die( 'Invalid parameters' );
        }
        
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) {
            wp_die( 'Form not found' );
        }
        
        $product = get_post( $product_id );
        if ( ! $product ) {
            wp_die( 'Product not found' );
        }
        
        // Get ALL entries from this form (not just active)
        // First try with product_id meta filter
        $search_criteria = array(
            'field_filters' => array(
                array(
                    'key' => 'woo_gf_product_id',
                    'value' => $product_id,
                ),
            ),
        );
        
        // Check if we found any entries with this criteria
        $test_count = GFAPI::count_entries( $form_id, $search_criteria );
        
        // If no entries found with meta, get ALL entries from the form
        if ( $test_count == 0 ) {
            $search_criteria = array(); // Empty criteria = all entries
        }
        
        $entries = GFAPI::get_entries( $form_id, $search_criteria );
        $total_entries = count( $entries );
        
        // Get event details
        $event_date = get_post_meta( $product_id, '_event_date', true );
        $capacity = get_post_meta( $product_id, '_event_capacity', true );
        $capacity = $capacity ? intval( $capacity ) : 0;
        $available_spots = $capacity - $total_entries;
        $percentage = $capacity > 0 ? ( $total_entries / $capacity ) * 100 : 0;
        
        ob_start();
        ?>
        <div class="woo-gf-registrations-container">
            <!-- Header with stats -->
            <div class="woo-gf-registrations-header">
                <div class="woo-gf-registrations-title">
                    <h3><?php echo esc_html( $product->post_title ); ?></h3>
                    <?php if ( $event_date ) : ?>
                        <p class="woo-gf-event-date">
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $event_date ) ) ); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="woo-gf-registrations-stats">
                    <div class="woo-gf-stat">
                        <span class="woo-gf-stat-number"><?php echo esc_html( $total_entries ); ?></span>
                        <span class="woo-gf-stat-label"><?php esc_html_e( 'נרשמים', 'at-woo-gf-integration' ); ?></span>
                    </div>
                    <?php if ( $capacity > 0 ) : ?>
                        <div class="woo-gf-stat">
                            <span class="woo-gf-stat-number"><?php echo esc_html( $available_spots ); ?></span>
                            <span class="woo-gf-stat-label"><?php esc_html_e( 'מקומות פנויים', 'at-woo-gf-integration' ); ?></span>
                        </div>
                        <div class="woo-gf-stat">
                            <span class="woo-gf-stat-number"><?php echo esc_html( round( $percentage, 1 ) ); ?>%</span>
                            <span class="woo-gf-stat-label"><?php esc_html_e( 'אחוז מילוי', 'at-woo-gf-integration' ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Capacity bar -->
            <?php if ( $capacity > 0 ) : ?>
                <div class="woo-gf-capacity-section">
                    <div class="woo-gf-capacity-bar">
                        <?php 
                        $status_class = $percentage >= 90 ? 'danger' : ( $percentage >= 70 ? 'warning' : 'success' );
                        ?>
                        <div class="woo-gf-capacity-fill <?php echo esc_attr( $status_class ); ?>" 
                             style="width: <?php echo esc_attr( min( 100, $percentage ) ); ?>%"></div>
                    </div>
                    <div class="woo-gf-capacity-text">
                        <span class="woo-gf-available"><?php echo esc_html( $available_spots ); ?></span>
                        <span class="woo-gf-separator">/</span>
                        <span class="woo-gf-total"><?php echo esc_html( $capacity ); ?></span>
                        <span class="woo-gf-spots-label"><?php esc_html_e( 'מקומות', 'at-woo-gf-integration' ); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Registrations table -->
            <?php if ( empty( $entries ) ) : ?>
                <div class="woo-gf-no-registrations">
                    <div class="woo-gf-no-registrations-icon">👥</div>
                    <h4><?php esc_html_e( 'אין נרשמים', 'at-woo-gf-integration' ); ?></h4>
                    <p><?php esc_html_e( 'עדיין לא נרשמו אנשים לאירוע זה.', 'at-woo-gf-integration' ); ?></p>
                </div>
            <?php else : ?>
                <div class="woo-gf-registrations-table-container">
                    <table class="woo-gf-registrations-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'תאריך הרשמה', 'at-woo-gf-integration' ); ?></th>
                                <th><?php esc_html_e( 'שם', 'at-woo-gf-integration' ); ?></th>
                                <th><?php esc_html_e( 'אימייל', 'at-woo-gf-integration' ); ?></th>
                                <th><?php esc_html_e( 'טלפון', 'at-woo-gf-integration' ); ?></th>
                                <th><?php esc_html_e( 'סטטוס', 'at-woo-gf-integration' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $entries as $entry ) : ?>
                                <tr class="woo-gf-registration-row">
                                    <td class="woo-gf-registration-date">
                                        <div class="woo-gf-date-display">
                                            <span class="woo-gf-date-day"><?php echo esc_html( date_i18n( 'd', strtotime( $entry['date_created'] ) ) ); ?></span>
                                            <span class="woo-gf-date-month"><?php echo esc_html( date_i18n( 'M', strtotime( $entry['date_created'] ) ) ); ?></span>
                                            <span class="woo-gf-date-year"><?php echo esc_html( date_i18n( 'Y', strtotime( $entry['date_created'] ) ) ); ?></span>
                                        </div>
                                        <div class="woo-gf-time-display">
                                            <?php echo esc_html( date_i18n( 'H:i', strtotime( $entry['date_created'] ) ) ); ?>
                                        </div>
                                    </td>
                                    <td class="woo-gf-registration-name">
                                        <?php echo esc_html( $this->get_entry_name( $entry, $form ) ); ?>
                                    </td>
                                    <td class="woo-gf-registration-email">
                                        <?php echo esc_html( $this->get_entry_email( $entry, $form ) ); ?>
                                    </td>
                                    <td class="woo-gf-registration-phone">
                                        <?php echo esc_html( $this->get_entry_phone( $entry, $form ) ); ?>
                                    </td>
                                    <td class="woo-gf-registration-status">
                                        <span class="woo-gf-status woo-gf-status-completed">
                                            <?php esc_html_e( 'נרשם', 'at-woo-gf-integration' ); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        
        $content = ob_get_clean();
        wp_send_json_success( array( 'content' => $content ) );
    }

    /**
     * Handle sample event creation form submission
     */
    public function handle_sample_event_creation() {
        if ( ! isset( $_POST['woo_gf_create_sample_event'] ) || ! isset( $_POST['woo_gf_nonce'] ) ) {
            return;
        }
        
        if ( ! wp_verify_nonce( $_POST['woo_gf_nonce'], 'woo_gf_create_sample_event' ) ) {
            wp_die( 'Security check failed' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        $this->create_sample_event();
    }

    /**
     * Create a sample event product for testing (only for administrators)
     */
    public function create_sample_event() {
        // Only allow administrators to create sample events
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        // Check if we have any Gravity Forms
        $forms = $this->get_cached_forms();
        if ( empty( $forms ) ) {
            echo '<div class="notice notice-error">';
            echo '<p>אין טפסים זמינים. צור טופס Gravity Forms תחילה.</p>';
            echo '</div>';
            return;
        }
        
        // Create a sample event product
        $product = new WC_Product_Simple();
        $product->set_name( 'אירוע לדוגמה - ' . date( 'Y-m-d H:i' ) );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_description( 'אירוע לדוגמה שנוצר אוטומטית לבדיקת הדשבורד' );
        $product->set_short_description( 'אירוע לדוגמה' );
        $product->set_regular_price( '100' );
        $product->set_sale_price( '' );
        $product->set_manage_stock( false );
        $product->set_stock_status( 'instock' );
        $product->set_virtual( true );
        $product->set_downloadable( false );
        
        // Set product type to event
        $product->set_meta_data( '_product_type', 'event' );
        
        // Link to first available form
        $first_form = $forms[0];
        $product->set_meta_data( '_woo_gf_form_id', $first_form['id'] );
        
        // Set event date (tomorrow)
        $tomorrow = date( 'Y-m-d H:i:s', strtotime( '+1 day' ) );
        $product->set_meta_data( '_event_date', $tomorrow );
        
        // Set max attendees
        $product->set_meta_data( '_max_attendees', 50 );
        
        // Set event location
        $product->set_meta_data( '_event_location', 'מיקום לדוגמה' );
        
        // Save the product
        $product_id = $product->save();
        
        if ( $product_id ) {
            // Set product type term
            wp_set_object_terms( $product_id, 'event', 'product_type' );
            
            echo '<div class="notice notice-success">';
            echo '<p>נוצר אירוע לדוגמה בהצלחה! <a href="' . get_edit_post_link( $product_id ) . '">ערוך את האירוע</a></p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-error">';
            echo '<p>שגיאה ביצירת אירוע לדוגמה.</p>';
            echo '</div>';
        }
    }

    /**
     * Get available Gravity Forms for filtering
     */
    private function get_available_forms() {
        if (!class_exists('GFAPI')) {
            return array();
        }
        
        $forms = GFAPI::get_forms();
        $available_forms = array();
        
        foreach ($forms as $form) {
            $available_forms[] = array(
                'id' => $form['id'],
                'title' => $form['title']
            );
        }
        
        return $available_forms;
    }

    /**
     * Display debug information when no events are found (lightweight version)
     */
    private function display_debug_info() {
        echo '<div class="woo-gf-debug-section">';
        echo '<h4>🔍 מידע דיבאג</h4>';
        
        // Check if Gravity Forms is active
        if (!class_exists('GFAPI')) {
            echo '<div class="woo-gf-debug-item">';
            echo '<strong>❌ Gravity Forms לא פעיל</strong> - הדשבורד דורש Gravity Forms להיות מותקן ופעיל.';
            echo '</div>';
            return;
        }
        
        // Quick counts - limit queries to prevent timeout
        $products_count = wp_count_posts( 'product' );
        $published_products = isset( $products_count->publish ) ? $products_count->publish : 0;
        
        // Quick check for products with forms (limit to 10)
        $products_with_forms = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_woo_gf_form_id',
                    'compare' => 'EXISTS',
                )
            )
        ));
        
        echo '<div class="woo-gf-debug-stats">';
        echo '<div class="woo-gf-debug-stat">';
        echo '<span class="woo-gf-debug-number">' . $published_products . '</span>';
        echo '<span class="woo-gf-debug-label">סה"כ מוצרים מפורסמים</span>';
        echo '</div>';
        echo '<div class="woo-gf-debug-stat">';
        echo '<span class="woo-gf-debug-number">' . count($products_with_forms) . '</span>';
        echo '<span class="woo-gf-debug-label">מוצרים עם טפסים (עשרה ראשונים)</span>';
        echo '</div>';
        echo '</div>';
        
        // Show sample products with forms (only first 3)
        if (!empty($products_with_forms)) {
            echo '<div class="woo-gf-debug-item">';
            echo '<strong>📋 דוגמאות מוצרים עם טפסים:</strong>';
            echo '<ul>';
            foreach (array_slice($products_with_forms, 0, 3) as $product_id) {
                $form_id = get_post_meta($product_id, '_woo_gf_form_id', true);
                $event_date = get_post_meta($product_id, '_event_date', true);
                $product_title = get_the_title($product_id);
                
                echo '<li>';
                echo '<strong>' . esc_html($product_title) . '</strong>';
                echo ' (ID: ' . $product_id . ')';
                echo ' - טופס: ' . ($form_id ? $form_id : 'לא נמצא');
                if ($event_date) echo ' - תאריך: ' . esc_html($event_date);
                echo ' <a href="' . get_edit_post_link($product_id) . '">ערוך</a>';
                echo '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        
        // Check for forms (limit to 5)
        try {
            $forms = $this->get_cached_forms();
            echo '<div class="woo-gf-debug-item">';
            echo '<strong>📝 טפסים זמינים:</strong> ' . count($forms) . ' טפסים';
            if (!empty($forms)) {
                echo '<ul>';
                foreach (array_slice($forms, 0, 3) as $form) {
                    echo '<li>' . esc_html($form['title']) . ' (ID: ' . $form['id'] . ')</li>';
                }
                if (count($forms) > 3) {
                    echo '<li>... ועוד ' . (count($forms) - 3) . ' טפסים</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        } catch ( Exception $e ) {
            echo '<div class="woo-gf-debug-item">';
            echo '<strong>⚠️ שגיאה בטעינת טפסים:</strong> ' . esc_html($e->getMessage());
            echo '</div>';
        }
        
        echo '</div>'; // .woo-gf-debug-section
    }
    
    /**
     * AJAX handler for exporting registrations to Excel/CSV
     */
    public function ajax_export_registrations() {
        check_ajax_referer( 'woo_gf_dashboard_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'אין לך הרשאה לביצוע פעולה זו', 'at-woo-gf-integration' ) );
        }
        
        $form_id = intval( $_POST['form_id'] );
        $product_id = intval( $_POST['product_id'] );
        
        if ( ! $form_id || ! $product_id ) {
            wp_send_json_error( __( 'פרמטרים לא תקינים', 'at-woo-gf-integration' ) );
        }
        
        $product = get_post( $product_id );
        if ( ! $product ) {
            wp_send_json_error( __( 'המוצר לא נמצא', 'at-woo-gf-integration' ) );
        }
        
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) {
            wp_send_json_error( __( 'הטופס לא נמצא', 'at-woo-gf-integration' ) );
        }
        
        // Get all entries for this form/product combination
        // First try with product_id meta filter
        $search_criteria = array(
            'field_filters' => array(
                array(
                    'key' => 'woo_gf_product_id',
                    'value' => $product_id,
                ),
            ),
        );
        
        // Check if we found any entries with this criteria
        $test_count = GFAPI::count_entries( $form_id, $search_criteria );
        
        // If no entries found with meta, get ALL entries from the form
        if ( $test_count == 0 ) {
            $search_criteria = array(); // Empty criteria = all entries
        }
        
        $entries = GFAPI::get_entries( $form_id, $search_criteria );
        
        if ( empty( $entries ) ) {
            wp_send_json_error( __( 'אין נרשמים לייצוא', 'at-woo-gf-integration' ) );
        }
        
        // Prepare CSV data
        $csv_data = array();
        
        // Add header row with field labels
        $headers = array( 'תאריך הרשמה', 'שם', 'דוא"ל', 'סטטוס' );
        
        // Add custom form fields to headers
        foreach ( $form['fields'] as $field ) {
            if ( isset( $field['label'] ) && ! empty( $field['label'] ) ) {
                $headers[] = $field['label'];
            }
        }
        
        $csv_data[] = $headers;
        
        // Add data rows
        foreach ( $entries as $entry ) {
            $row = array(
                isset( $entry['date_created'] ) ? date_i18n( 'j.m.Y H:i', strtotime( $entry['date_created'] ) ) : '',
                isset( $entry['1.3'] ) ? $entry['1.3'] : '', // Assuming field 1 is name
                isset( $entry['5'] ) ? $entry['5'] : '', // Assuming field 5 is email (common)
                isset( $entry['status'] ) ? $entry['status'] : 'active',
            );
            
            // Add custom field values
            foreach ( $form['fields'] as $field ) {
                if ( isset( $entry[ $field['id'] ] ) ) {
                    $row[] = $entry[ $field['id'] ];
                } else {
                    $row[] = '';
                }
            }
            
            $csv_data[] = $row;
        }
        
        // Generate filename
        $filename = sanitize_file_name( $product->post_title . '_' . date( 'Y-m-d_H-i-s' ) . '.csv' );
        
        // Create CSV string
        $csv_string = '';
        foreach ( $csv_data as $row ) {
            $csv_string .= '"' . implode( '","', array_map( 'wp_specialchars', $row ) ) . '"' . "\n";
        }
        
        // Set headers for download
        wp_send_json_success( array(
            'csv_data' => $csv_string,
            'filename' => $filename,
        ) );
    }
} 