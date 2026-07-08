<?php
/**
 * Plugin Name: AT - WooCommerce Gravity Forms Integration
 * Plugin URI: https://amit-trabelsi.co.il/
 * Description: תוסף מתקדם שמחבר בין WooCommerce ל-Gravity Forms עם ניהול אירועים, הרשאות משתמשים ודשבורד הרשמות מלא
 * Version: 2.13.3
 * Author: Amit Trabelsi
 * Author URI: https://amit-trabelsi-digital.com/
 * Text Domain: at-woo-gf-integration
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://raw.githubusercontent.com/amit-trabelsi-digital/at-woo-gf-integration/dev/plugin-info.json
 * 
 * WC requires at least: 7.0
 * WC tested up to: 8.9
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * 
 * @package ATWooGFIntegration
 * @version 2.13.3
 * @author Amit Trabelsi
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'AT_WOO_GF_INTEGRATION_VERSION', '2.13.3' );
define( 'AT_WOO_GF_INTEGRATION_FILE', __FILE__ );
define( 'AT_WOO_GF_INTEGRATION_PATH', plugin_dir_path( __FILE__ ) );
define( 'AT_WOO_GF_INTEGRATION_URL', plugin_dir_url( __FILE__ ) );
define( 'AT_WOO_GF_INTEGRATION_UPDATE_URL', 'https://updates.amit-trabelsi.co.il/' );

/**
 * Main plugin class
 */
class AT_Woo_GF_Integration {

    /**
     * Instance of this class.
     *
     * @var AT_Woo_GF_Integration
     */
    private static $instance = null;

    /**
     * Get the singleton instance of this class.
     *
     * @return AT_Woo_GF_Integration
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
        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Check if required plugins are active
        add_action( 'plugins_loaded', array( $this, 'check_requirements' ) );

        // GDPR cookie consent — site-wide, must load even without WooCommerce/GF.
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-cookie-consent.php';
        AT_Woo_GF_Cookie_Consent::get_instance();
    }
    
    /**
     * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 
                'custom_order_tables', 
                AT_WOO_GF_INTEGRATION_FILE, 
                true 
            );
        }
    }

    /**
     * Check if WooCommerce and Gravity Forms are active
     */
    public function check_requirements() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        if ( ! class_exists( 'GFForms' ) ) {
            add_action( 'admin_notices', array( $this, 'gravity_forms_missing_notice' ) );
            return;
        }

        // Load plugin functionality
        $this->includes();
        $this->init();
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-product-form-metabox.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-registration-dashboard.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-ajax-handler.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-product-event.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-event-product-type.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-registration-scheduler.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-event-waitlist.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-updater.php';
        require_once AT_WOO_GF_INTEGRATION_PATH . 'includes/class-gf-product-link-setting.php';
        // Note: class-user-roles-manager.php has been removed - using standard WordPress roles
    }

    /**
     * Initialize plugin functionality
     */
    private function init() {
        // Initialize classes
        Woo_GF_Product_Form_Metabox::get_instance();
        Woo_GF_Registration_Dashboard::get_instance();
        Woo_GF_Ajax_Handler::get_instance();
        Woo_GF_Registration_Scheduler::get_instance();
        Woo_GF_Product_Link_Setting::get_instance();
        
        // User roles manager has been removed - using standard WordPress roles
        
        // Initialize event product type
        new WooGF_Event_Product_Type();
        
        // Initialize updater
        new AT_Woo_GF_Integration_Updater();

        // Load translations
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Enqueue admin scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

        // Add action to change post labels
        add_action( 'admin_menu', array( $this, 'change_post_labels' ) );
        
        // Add action to reorganize admin menu
        // add_action( 'admin_menu', array( $this, 'reorganize_admin_menu' ), 999 );
        
        // Hide taxonomy columns from product table
        add_filter( 'manage_edit-product_columns', array( $this, 'remove_product_taxonomy_columns' ), 20 );
        
        // סטטוס מותאם אישית: מפורסם אך נגיש בלינק ישיר בלבד
        add_action('init', array($this, 'register_hidden_published_post_status'));
        add_action('admin_footer-post.php', array($this, 'append_hidden_status_to_status_dropdown'));
        add_action('admin_footer-edit.php', array($this, 'append_hidden_status_to_status_dropdown'));
        add_filter('display_post_states', array($this, 'display_hidden_status_label'), 10, 2);
        add_action('pre_get_posts', array($this, 'exclude_hidden_from_loops'), 999);
        add_filter('woocommerce_product_is_visible', array($this, 'hide_from_catalog'), 10, 2);
        
        // אינטגרציה עם Relevanssi - הסתרת תוכן מוסתר מתוצאות חיפוש
        add_filter('relevanssi_post_ok', array($this, 'relevanssi_exclude_hidden_posts'), 10, 2);
        add_filter('relevanssi_modify_wp_query', array($this, 'relevanssi_exclude_hidden_from_query'));
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'at-woo-gf-integration', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts( $hook ) {
        global $post;

        // Debug: Log the current hook and plugin URL
        error_log( 'AT WooGF Debug - Hook: ' . $hook . ', Plugin URL: ' . AT_WOO_GF_INTEGRATION_URL );

        // Enqueue on product edit page
        if ( in_array( $hook, array( 'post.php', 'post-new.php' ) ) && isset( $post ) && 'product' === $post->post_type ) {
            wp_enqueue_style( 
                'at-woo-gf-integration-admin', 
                AT_WOO_GF_INTEGRATION_URL . 'assets/css/admin.css', 
                array(), 
                AT_WOO_GF_INTEGRATION_VERSION 
            );

            wp_enqueue_script( 
                'at-woo-gf-integration-admin', 
                AT_WOO_GF_INTEGRATION_URL . 'assets/js/admin.js', 
                array( 'jquery' ), 
                AT_WOO_GF_INTEGRATION_VERSION, 
                true 
            );

            wp_localize_script( 'at-woo-gf-integration-admin', 'atWooGfIntegration', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'at_woo_gf_integration_nonce' ),
                'strings' => array(
                    'loading' => __( 'טוען...', 'at-woo-gf-integration' ),
                    'error' => __( 'אירעה שגיאה. אנא נסה שוב.', 'at-woo-gf-integration' ),
                    'create_form' => __( 'צור טופס חדש', 'at-woo-gf-integration' ),
                    'edit_form_confirm' => __( 'האם ברצונך לערוך את הטופס כעת?', 'at-woo-gf-integration' ),
                    'form_prefix' => __( 'הרשמה ל-', 'at-woo-gf-integration' ),
                    'replace_form_confirm' => __( 'כבר קיים טופס משויך למוצר זה. האם ברצונך ליצור טופס חדש ולהחליף את הקיים?', 'at-woo-gf-integration' ),
                )
            ) );
        }

        // Enqueue on dashboard page
        if ( 'toplevel_page_event-registrations' === $hook ) {
            error_log( 'AT WooGF Debug - Loading dashboard assets for hook: ' . $hook );
            error_log( 'AT WooGF Debug - CSS URL: ' . AT_WOO_GF_INTEGRATION_URL . 'assets/css/dashboard.css' );
            error_log( 'AT WooGF Debug - JS URL: ' . AT_WOO_GF_INTEGRATION_URL . 'assets/js/dashboard.js' );
            
            wp_enqueue_style( 
                'at-woo-gf-integration-dashboard', 
                AT_WOO_GF_INTEGRATION_URL . 'assets/css/dashboard.css', 
                array(), 
                AT_WOO_GF_INTEGRATION_VERSION 
            );

            wp_enqueue_script( 
                'at-woo-gf-integration-dashboard', 
                AT_WOO_GF_INTEGRATION_URL . 'assets/js/dashboard.js', 
                array( 'jquery' ), 
                AT_WOO_GF_INTEGRATION_VERSION, 
                true 
            );
        } else {
            error_log( 'AT WooGF Debug - Hook mismatch. Expected: toplevel_page_event-registrations, Got: ' . $hook );
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'תוסף AT - WooCommerce Gravity Forms Integration דורש שתוסף WooCommerce יהיה מותקן ופעיל.', 'at-woo-gf-integration' ); ?></p>
        </div>
        <?php
    }

    /**
     * Gravity Forms missing notice
     */
    public function gravity_forms_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'תוסף AT - WooCommerce Gravity Forms Integration דורש שתוסף Gravity Forms יהיה מותקן ופעיל.', 'at-woo-gf-integration' ); ?></p>
        </div>
        <?php
    }

    /**
     * Change Post Type Labels
     * Posts will be labeled as "פיתוח ידע ומחקר" in the menu
     */
    public function change_post_labels() {
        global $menu, $submenu;

        // Note: Top-level menu label will be set in reorganize_admin_menu()
        // This function handles the internal labels and submenu items

        // Change submenu items
        if (isset($submenu['edit.php'])) {
            foreach ($submenu['edit.php'] as $key => $item) {
                if ($item[2] === 'edit.php') {
                    $submenu['edit.php'][$key][0] = __('כל המאמרים', 'at-woo-gf-integration');
                }
                if ($item[2] === 'post-new.php') {
                    $submenu['edit.php'][$key][0] = __('מאמר חדש', 'at-woo-gf-integration');
                }
            }
        }

        // Change object labels (internal use - for edit screens etc.)
        $get_post_type = get_post_type_object('post');
        if ($get_post_type) {
            $labels = $get_post_type->labels;
            $labels->name = __('מאמרים', 'at-woo-gf-integration');
            $labels->singular_name = __('מאמר', 'at-woo-gf-integration');
            $labels->add_new = __('מאמר חדש', 'at-woo-gf-integration');
            $labels->add_new_item = __('הוסף מאמר חדש', 'at-woo-gf-integration');
            $labels->edit_item = __('ערוך מאמר', 'at-woo-gf-integration');
            $labels->new_item = __('מאמר חדש', 'at-woo-gf-integration');
            $labels->view_item = __('צפה במאמר', 'at-woo-gf-integration');
            $labels->search_items = __('חפש מאמרים', 'at-woo-gf-integration');
            $labels->not_found = __('לא נמצאו מאמרים', 'at-woo-gf-integration');
            $labels->not_found_in_trash = __('לא נמצאו מאמרים באשפה', 'at-woo-gf-integration');
            $labels->all_items = __('כל המאמרים', 'at-woo-gf-integration');
            $labels->name_admin_bar = __('מאמר', 'at-woo-gf-integration');
        }
    }

    /**
     * Get menu configuration based on user role and capabilities
     *
     * דוגמאות להרחבה:
     * - הוסף תפקיד 'marketing_manager' עם גישה ל-WooCommerce ותוכן
     * - הוסף תפקיד 'support_agent' עם גישה מוגבלת להזמנות
     * - השתמש ב-user meta כדי להתאים אישית לפי משתמש ספציפי
     */
    private function get_menu_config_by_user() {
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_roles = $current_user->roles;
        $is_admin = in_array('administrator', $user_roles);
        $is_editor = in_array('editor', $user_roles);
        $is_shop_manager = current_user_can('manage_woocommerce');
        $is_content_manager = current_user_can('edit_posts');

        // Base configuration
        $config = array(
            'show_woocommerce' => false,
            'show_settings' => false,
            'show_tools' => false,
            'show_plugins' => false,
            'show_themes' => false,
            'show_content' => false,
            'menu_title' => __('ניהול אתר', 'at-woo-gf-integration'),
            'menu_icon' => 'dashicons-admin-settings'
        );

        // Admin - sees everything
        if ($is_admin) {
            $config['show_woocommerce'] = true;
            $config['show_settings'] = true;
            $config['show_tools'] = true;
            $config['show_plugins'] = true;
            $config['show_themes'] = true;
            $config['show_content'] = true;
            $config['menu_title'] = __('ניהול אתר מלא', 'at-woo-gf-integration');
        }
        // Shop Manager - WooCommerce focused
        elseif ($is_shop_manager && !$is_admin) {
            $config['show_woocommerce'] = true;
            $config['show_settings'] = false; // Limited settings
            $config['show_tools'] = false;
            $config['show_plugins'] = false;
            $config['show_themes'] = false;
            $config['show_content'] = false;
            $config['menu_title'] = __('ניהול חנות', 'at-woo-gf-integration');
            $config['menu_icon'] = 'dashicons-cart';
        }
        // Content Manager - Content focused
        elseif ($is_content_manager && !$is_editor && !$is_admin) {
            $config['show_woocommerce'] = false;
            $config['show_settings'] = false;
            $config['show_tools'] = false;
            $config['show_plugins'] = false;
            $config['show_themes'] = false;
            $config['show_content'] = true;
            $config['menu_title'] = __('ניהול תוכן', 'at-woo-gf-integration');
            $config['menu_icon'] = 'dashicons-edit';
        }
        // Editor - Limited access
        elseif ($is_editor) {
            $config['show_woocommerce'] = true; // Can see orders
            $config['show_settings'] = false;
            $config['show_tools'] = false;
            $config['show_plugins'] = false;
            $config['show_themes'] = false;
            $config['show_content'] = true;
            $config['menu_title'] = __('ניהול עריכה', 'at-woo-gf-integration');
            $config['menu_icon'] = 'dashicons-edit';
        }
        // Marketing Manager - Custom role example
        elseif (in_array('marketing_manager', $user_roles)) {
            $config['show_woocommerce'] = true; // Analytics and marketing
            $config['show_settings'] = false;
            $config['show_tools'] = false;
            $config['show_plugins'] = false;
            $config['show_themes'] = false;
            $config['show_content'] = true; // Can manage content for marketing
            $config['menu_title'] = __('ניהול שיווק', 'at-woo-gf-integration');
            $config['menu_icon'] = 'dashicons-megaphone';
        }
        // Support Agent - Custom role example
        elseif (in_array('support_agent', $user_roles)) {
            $config['show_woocommerce'] = true; // Can view orders for support
            $config['show_settings'] = false;
            $config['show_tools'] = false;
            $config['show_plugins'] = false;
            $config['show_themes'] = false;
            $config['show_content'] = false;
            $config['menu_title'] = __('תמיכה טכנית', 'at-woo-gf-integration');
            $config['menu_icon'] = 'dashicons-sos';
        }
        // Basic user or custom roles
        else {
            // Check for custom capabilities
            if (current_user_can('view_site_management')) {
                $config['show_woocommerce'] = current_user_can('view_woocommerce');
                $config['show_content'] = current_user_can('view_content');
                $config['menu_title'] = __('גישה מוגבלת', 'at-woo-gf-integration');
                $config['menu_icon'] = 'dashicons-visibility';
            }
            // Example: User-specific permissions based on meta
            elseif (get_user_meta($user_id, 'custom_admin_access', true)) {
                $access_level = get_user_meta($user_id, 'custom_admin_access', true);
                switch ($access_level) {
                    case 'full':
                        $config = array_merge($config, array(
                            'show_woocommerce' => true,
                            'show_content' => true,
                            'menu_title' => __('ניהול מותאם אישית', 'at-woo-gf-integration')
                        ));
                        break;
                    case 'limited':
                        $config = array_merge($config, array(
                            'show_content' => true,
                            'menu_title' => __('גישה חלקית', 'at-woo-gf-integration')
                        ));
                        break;
                }
            }
        }

        return $config;
    }

    /**
     * Reorganize Admin Menu - Custom order for Haruv
     * 
     * Order:
     * 1. {separator}
     * 2. דשבורד הרשמות (standalone)
     * 3. {separator}
     * 4. מוצרים
     * 5. טפסים (admin only, with קופונים submenu)
     * 6. הזמנות
     * 7. WooCommerce (main menu)
     * 8. {separator}
     * 9. מדיה
     * 10. פיתוח ידע ומחקר (מאמרים)
     * 11. הסכתים
     * 12. מאמרים (CPT)
     * 13. מגזינים
     * 14. צוות
     * 15. עמודים (admin only)
     */
    public function reorganize_admin_menu() {
        global $menu, $submenu;

        $is_admin = current_user_can('manage_options');
        
        // Remove default separators
        remove_action('admin_menu', '_add_post_type_separators', 11);
        
        // Store menu positions
        $position = 2; // Start at position 2 (after Dashboard)
        
        // 1. Separator before Registration Dashboard
        $menu[++$position] = array('', 'read', 'separator0', '', 'wp-menu-separator');
        
        // 2. דשבורד הרשמות - already added by class-registration-dashboard.php at position 3
        // Skip position 3 as it's reserved for the dashboard
        $position = 3;
        
        // 3. Separator after Registration Dashboard
        $menu[++$position] = array('', 'read', 'separator1', '', 'wp-menu-separator');
        
        // 4. מוצרים (WooCommerce Products)
        if (isset($menu[56])) { // WooCommerce products default position
            $menu[++$position] = $menu[56];
            unset($menu[56]);
        }
        
        // 5. טפסים (Gravity Forms) - Admin Only
        if ($is_admin && class_exists('GFForms')) {
            // Find Gravity Forms menu
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && strpos($item[2], 'gf_') === 0) {
                    $menu[++$position] = $item;
                    unset($menu[$key]);
                    
                    // Add קופונים as submenu to Gravity Forms
                    if (class_exists('WooCommerce')) {
                        add_submenu_page(
                            'gf_edit_forms',
                            __('קופונים', 'at-woo-gf-integration'),
                            __('קופונים', 'at-woo-gf-integration'),
                            'manage_woocommerce',
                            'edit.php?post_type=shop_coupon'
                        );
                    }
                    break;
                }
            }
        } elseif (!$is_admin && class_exists('GFForms')) {
            // Hide Gravity Forms for non-admins
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && strpos($item[2], 'gf_') === 0) {
                    unset($menu[$key]);
                    break;
                }
            }
        }
        
        // 6. הזמנות (WooCommerce Orders)
        if (class_exists('WooCommerce')) {
            // WooCommerce orders submenu - make it top level
            add_menu_page(
                __('הזמנות', 'at-woo-gf-integration'),
                __('הזמנות', 'at-woo-gf-integration'),
                'edit_shop_orders',
                'edit.php?post_type=shop_order',
                '',
                'dashicons-list-view',
                ++$position
            );
        }
        
        // 7. WooCommerce Main Menu - Keep it visible
        // Don't remove it - just reposition
        if (isset($menu[55])) { // WooCommerce main menu default position
            $menu[++$position] = $menu[55];
            unset($menu[55]);
        }
        
        // 8. Separator
        $menu[++$position] = array('', 'read', 'separator2', '', 'wp-menu-separator');
        
        // 9. מדיה
        if (isset($menu[10])) { // Media default position
            $menu[++$position] = $menu[10];
            unset($menu[10]);
        }
        
        // 10. פיתוח ידע ומחקר - כותרת מתוקנת למאמרים (Posts)
        // Find Posts menu item (might be at position 5 or elsewhere)
        $posts_found = false;
        foreach ($menu as $key => $item) {
            if (isset($item[2]) && $item[2] === 'edit.php' && !$posts_found) {
                // Update menu label to "פיתוח ידע ומחקר"
                $item[0] = __('פיתוח ידע ומחקר', 'at-woo-gf-integration');
                $menu[++$position] = $item;
                unset($menu[$key]);
                $posts_found = true;
                break;
            }
        }
        
        // 11-14. Custom Post Types - will be positioned automatically
        // Looking for: הסכתים, מאמרים (CPT), מגזינים, צוות
        // These might have different slugs, so we'll detect them dynamically
        $cpt_order = array();
        
        // Find all custom post types in menu
        foreach ($menu as $key => $item) {
            if (isset($item[2]) && strpos($item[2], 'edit.php?post_type=') === 0) {
                $post_type_slug = str_replace('edit.php?post_type=', '', $item[2]);
                
                // Skip WooCommerce and known types
                if (!in_array($post_type_slug, array('product', 'shop_order', 'shop_coupon', 'page'))) {
                    $cpt_order[] = array('key' => $key, 'item' => $item, 'slug' => $post_type_slug);
                    unset($menu[$key]);
                }
            }
        }
        
        // Add custom post types in the order they were found
        foreach ($cpt_order as $cpt) {
            $menu[++$position] = $cpt['item'];
        }
        
        // 15. עמודים (Pages) - Admin Only
        if ($is_admin) {
            if (isset($menu[20])) { // Pages default position
                $menu[++$position] = $menu[20];
                unset($menu[20]);
            }
        } else {
            // Hide pages for non-admins
            remove_menu_page('edit.php?post_type=page');
        }
        
        // Remove default separators that WordPress adds
        foreach ($menu as $key => $item) {
            if (isset($item[4]) && strpos($item[4], 'wp-menu-separator') !== false && 
                !in_array($item[2], array('separator0', 'separator1', 'separator2'))) {
                unset($menu[$key]);
            }
        }
        
        // Sort menu by position
        ksort($menu);
    }

    /**
     * Customize product table columns - show only essential columns
     * 
     * הצגת עמודות חיוניות בלבד בטבלת המוצרים:
     * - תמונה (thumb)
     * - שם (name)
     * - מלאי (is_in_stock)
     * - מחיר (price)
     * - תאריך (date)
     * - סוג האירוע (taxonomy-event_type)
     * - שפות (languages/polylang)
     * 
     * @param array $columns עמודות טבלת המוצרים
     * @return array עמודות מותאמות
     */
    public function remove_product_taxonomy_columns( $columns ) {
        // רשימת העמודות שנרצה להשאיר
        $allowed_columns = array(
            'cb',                       // Checkbox לבחירה
            'thumb',                    // תמונה
            'name',                     // שם המוצר
            'is_in_stock',              // מלאי
            'price',                    // מחיר
            'date',                     // תאריך
            'taxonomy-event_type',      // סוג האירוע
            'event_type',               // סוג האירוע (גרסה חלופית)
            'languages',                // שפות (Polylang)
            'language',                 // שפה (גרסה חלופית)
        );
        
        // צור מערך חדש של עמודות שכולל רק את המותרות
        $new_columns = array();
        
        foreach ( $columns as $key => $value ) {
            // שמור רק עמודות מהרשימה המותרת
            if ( in_array( $key, $allowed_columns ) ) {
                $new_columns[ $key ] = $value;
            }
            // שמור גם עמודות שמתחילות ב-languages (תמיכה ב-Polylang)
            elseif ( strpos( $key, 'language' ) === 0 ) {
                $new_columns[ $key ] = $value;
            }
        }
        
        return $new_columns;
    }
    
    /**
     * רישום סטטוס מותאם אישית: מפורסם אך נגיש בלינק ישיר בלבד
     * מאפשר לתוכן להיות נגיש בקישור ישיר אך מוסתר מחיפוש ולופים
     */
    public function register_hidden_published_post_status() {
        register_post_status('publish-hidden', array(
            'label'                     => _x('מפורסם אך נגיש בלינק ישיר בלבד', 'post status', 'at-woo-gf-integration'),
            'public'                    => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'מפורסם אך נגיש בלינק ישיר בלבד <span class="count">(%s)</span>',
                'מפורסם אך נגיש בלינק ישיר בלבד <span class="count">(%s)</span>',
                'at-woo-gf-integration'
            ),
        ));
    }
    
    /**
     * הוספת הסטטוס החדש לתפריט הנפתח בעמוד עריכה
     * עובד על כל סוגי הפוסטים - מוצרים, מאמרים, דפים, וכל post type מותאם אישית
     */
    public function append_hidden_status_to_status_dropdown() {
        global $post;
        
        if (!$post) {
            return;
        }
        
        // רשימת post types שלא נתמך בהם הסטטוס (למשל attachments)
        $excluded_post_types = array('attachment', 'revision', 'nav_menu_item');
        
        if (in_array($post->post_type, $excluded_post_types)) {
            return;
        }
        
        $complete = '';
        $label = '';
        
        if ($post->post_status === 'publish-hidden') {
            $complete = ' selected="selected"';
            $label = '<span id="post-status-display"> מפורסם אך נגיש בלינק ישיר בלבד</span>';
        }
        
        echo '<script>
        jQuery(document).ready(function($) {
            $("select#post_status").append("<option value=\"publish-hidden\"' . $complete . '>מפורסם אך נגיש בלינק ישיר בלבד</option>");
            $(".misc-pub-section label").append("' . $label . '");
        });
        </script>';
    }
    
    /**
     * הצגת תווית הסטטוס ברשימת כל סוגי התוכן
     * עובד על מוצרים, מאמרים, דפים וכל post type
     */
    public function display_hidden_status_label($states, $post) {
        // רשימת post types שלא נתמך בהם הסטטוס
        $excluded_post_types = array('attachment', 'revision', 'nav_menu_item');
        
        if (in_array($post->post_type, $excluded_post_types)) {
            return $states;
        }
        
        if ($post->post_status === 'publish-hidden') {
            $states['publish-hidden'] = __('מפורסם אך נגיש בלינק ישיר בלבד', 'at-woo-gf-integration');
        }
        
        return $states;
    }
    
    /**
     * הסתרת תוכן עם הסטטוס מלופים רגילים
     * עובד על כל סוגי הפוסטים - מוצרים, מאמרים, דפים וכל post type
     */
    public function exclude_hidden_from_loops($query) {
        // רק בקריאות שאינן admin
        if (is_admin()) {
            return;
        }
        
        // אם זה single post/page/product - אל תסתיר (אנחנו רוצים שניתן יהיה לגשת בקישור ישיר)
        if (is_singular()) {
            return;
        }
        
        // אם זה query ראשי (לופים, ארכיונים, חיפושים)
        if ($query->is_main_query()) {
            // קבל את הסטטוסים הנוכחיים
            $post_status = $query->get('post_status');
            
            // אם לא הוגדר סטטוס, השתמש ב-publish
            if (empty($post_status)) {
                $post_status = 'publish';
            }
            
            // וודא שזה מערך
            if (!is_array($post_status)) {
                $post_status = array($post_status);
            }
            
            // הסר את publish-hidden אם הוא קיים
            $post_status = array_diff($post_status, array('publish-hidden'));
            
            // עדכן את הquery
            $query->set('post_status', $post_status);
        }
    }
    
    /**
     * הסתרת מוצרים מקטלוג WooCommerce
     * פונקציה ספציפית למוצרים - מוסיפה שכבת הגנה נוספת ב-WooCommerce
     */
    public function hide_from_catalog($visible, $product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return $visible;
        }
        
        // אם המוצר במצב publish-hidden, הסתר אותו מהקטלוג
        if ($product->get_status() === 'publish-hidden') {
            // אבל אם זה דף המוצר עצמו, הצג אותו (גישה ישירה)
            if (is_singular('product') && get_the_ID() === $product_id) {
                return true;
            }
            return false;
        }
        
        return $visible;
    }
    
    /**
     * אינטגרציה עם Relevanssi - הסתרת פוסטים עם סטטוס publish-hidden מתוצאות חיפוש
     * 
     * @param bool $ok האם הפוסט מתאים להצגה בתוצאות חיפוש
     * @param int $post_id מזהה הפוסט
     * @return bool האם להציג את הפוסט בתוצאות
     */
    public function relevanssi_exclude_hidden_posts($ok, $post_id) {
        // בדוק אם הפוסט במצב publish-hidden
        $post_status = get_post_status($post_id);
        
        if ($post_status === 'publish-hidden') {
            // אל תציג בתוצאות חיפוש
            return false;
        }
        
        return $ok;
    }
    
    /**
     * אינטגרציה עם Relevanssi - עדכון WP_Query לא לכלול פוסטים מוסתרים
     * 
     * @param WP_Query $query אובייקט השאילתה
     * @return WP_Query השאילתה המעודכנת
     */
    public function relevanssi_exclude_hidden_from_query($query) {
        // קבל את הסטטוסים הנוכחיים
        $post_status = $query->get('post_status');
        
        // אם לא הוגדר סטטוס, השתמש ב-publish
        if (empty($post_status)) {
            $post_status = 'publish';
        }
        
        // וודא שזה מערך
        if (!is_array($post_status)) {
            $post_status = array($post_status);
        }
        
        // הסר את publish-hidden אם הוא קיים
        $post_status = array_diff($post_status, array('publish-hidden'));
        
        // עדכן את הquery
        $query->set('post_status', $post_status);
        
        return $query;
    }

}

// Initialize the plugin
AT_Woo_GF_Integration::get_instance(); 