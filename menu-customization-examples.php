<?php
/**
 * דוגמאות להרחבת מערכת התפריטים לפי הרשאות משתמשים
 *
 * קובץ זה מכיל דוגמאות להוספת תפקידים מותאמים אישית
 * והתאמת התפריטים לפי צרכי האתר הספציפיים
 *
 * @package ATWooGFIntegration
 */

// מונע גישה ישירה
if (!defined('ABSPATH')) {
    exit;
}

/**
 * דוגמה 1: הוספת תפקיד "מנהל שיווק"
 * לתפקיד זה תהיה גישה ל:
 * - WooCommerce (לצורך אנליטיקס ומבצעים)
 * - תוכן (לצורך ניהול תוכן שיווקי)
 * - הגדרות מוגבלות
 */
function add_marketing_manager_role() {
    add_role(
        'marketing_manager',
        __('מנהל שיווק', 'at-woo-gf-integration'),
        array(
            'read' => true,
            'edit_posts' => true,
            'edit_pages' => true,
            'upload_files' => true,
            'manage_woocommerce' => true, // גישה לניתוח מכירות
            'view_woocommerce_reports' => true,
            // הוסף הרשאות נוספות לפי הצורך
        )
    );
}
// הפעל זאת פעם אחת בלבד:
// add_action('init', 'add_marketing_manager_role');

/**
 * דוגמה 2: הוספת תפקיד "סוכן תמיכה"
 * לתפקיד זה תהיה גישה מוגבלת להזמנות בלבד
 */
function add_support_agent_role() {
    add_role(
        'support_agent',
        __('סוכן תמיכה', 'at-woo-gf-integration'),
        array(
            'read' => true,
            'edit_shop_orders' => true,     // עריכת הזמנות
            'read_shop_orders' => true,     // קריאת הזמנות
            'manage_shop_order_terms' => false, // ללא ניהול סטטוסים
        )
    );
}
// הפעל זאת פעם אחת בלבד:
// add_action('init', 'add_support_agent_role');

/**
 * דוגמה 3: התאמה אישית לפי משתמש ספציפי
 * שימוש ב-user meta להתאמת תפריטים אישית
 */
function customize_menu_for_specific_user($user_id) {
    // דוגמה: הוסף meta למשתמש ספציפי
    update_user_meta($user_id, 'custom_admin_access', 'full');

    // או חלקי:
    update_user_meta($user_id, 'custom_admin_access', 'limited');
}
// שימוש:
// customize_menu_for_specific_user(123); // החלף 123 ב-ID המשתמש

/**
 * דוגמה 4: הוספת תפריט מותאם אישית
 * יצירת תפריט חדש לגמרי עבור תפקיד מסוים
 */
function add_custom_menu_for_role() {
    // בדוק אם המשתמש הוא מנהל שיווק
    if (current_user_can('marketing_manager')) {
        add_menu_page(
            __('כלי שיווק', 'at-woo-gf-integration'),
            __('שיווק', 'at-woo-gf-integration'),
            'manage_options',
            'marketing-tools',
            'marketing_tools_page',
            'dashicons-megaphone',
            30
        );

        // הוסף תת-תפריטים
        add_submenu_page(
            'marketing-tools',
            __('קמפיינים', 'at-woo-gf-integration'),
            __('קמפיינים', 'at-woo-gf-integration'),
            'manage_options',
            'marketing-campaigns',
            'marketing_campaigns_page'
        );
    }
}
add_action('admin_menu', 'add_custom_menu_for_role', 20);

/**
 * דוגמה 5: הסתרת תפריטים ספציפיים לפי תפקיד
 */
function hide_menus_by_role() {
    // הסתר תפריטים מסוימים מסוכני תמיכה
    if (current_user_can('support_agent')) {
        remove_menu_page('tools.php');        // הסתר כלים
        remove_menu_page('plugins.php');      // הסתר תוספים
        remove_menu_page('themes.php');       // הסתר עיצוב
        remove_menu_page('options-general.php'); // הסתר הגדרות
    }

    // הסתר WooCommerce מלא ממנהלי תוכן
    if (current_user_can('content_manager') && !current_user_can('manage_woocommerce')) {
        remove_menu_page('woocommerce');
    }
}
add_action('admin_menu', 'hide_menus_by_role', 999);

/**
 * דוגמה 6: הוספת הרשאות מותאמות אישית
 */
function add_custom_capabilities() {
    $role = get_role('marketing_manager');

    if ($role) {
        // הוסף הרשאות מותאמות אישית
        $role->add_cap('view_marketing_reports');
        $role->add_cap('manage_promotions');
        $role->add_cap('export_customer_data');
    }

    $support_role = get_role('support_agent');
    if ($support_role) {
        $support_role->add_cap('view_customer_orders');
        $support_role->add_cap('update_order_status');
        $support_role->add_cap('send_order_emails');
    }
}
// הפעל זאת פעם אחת בלבד:
// add_action('init', 'add_custom_capabilities');

/**
 * דוגמה 7: לוג של פעילות משתמשים בתפריט
 * למעקב אחר השימוש בממשק הניהול
 */
function log_admin_menu_usage() {
    if (!is_admin()) return;

    $current_user = wp_get_current_user();
    $current_screen = get_current_screen();

    // רשום פעילות
    error_log(sprintf(
        'Admin Menu Usage - User: %s (ID: %d), Role: %s, Screen: %s, Time: %s',
        $current_user->display_name,
        $current_user->ID,
        implode(', ', $current_user->roles),
        $current_screen ? $current_screen->id : 'unknown',
        current_time('Y-m-d H:i:s')
    ));
}
add_action('admin_init', 'log_admin_menu_usage');

/**
 * דוגמה 8: התראות מותאמות אישית לפי תפקיד
 */
function show_role_based_notices() {
    if (!is_admin()) return;

    $current_user = wp_get_current_user();

    // התראה למנהלי שיווק
    if (current_user_can('marketing_manager')) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e('כלי שיווק זמינים:', 'at-woo-gf-integration'); ?></strong><br>
                <?php esc_html_e('יש לך גישה לכלי השיווק והדוחות. השתמש בתפריט "שיווק" לניהול קמפיינים.', 'at-woo-gf-integration'); ?>
            </p>
        </div>
        <?php
    }

    // התראה לסוכני תמיכה
    if (current_user_can('support_agent')) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e('הנחיות תמיכה:', 'at-woo-gf-integration'); ?></strong><br>
                <?php esc_html_e('זכור: אתה יכול לצפות ולהגיב להזמנות בלבד. לשינויי מחירים או החזרים, פנה למנהל.', 'at-woo-gf-integration'); ?>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'show_role_based_notices');

/**
 * פונקציות עזר לדוגמאות
 */
function marketing_tools_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('כלי שיווק', 'at-woo-gf-integration'); ?></h1>
        <p><?php esc_html_e('כאן תוכל למצוא את כל הכלים הדרושים לניהול השיווק באתר.', 'at-woo-gf-integration'); ?></p>

        <div class="marketing-tools-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 30px;">
            <div class="tool-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <h3><?php esc_html_e('דוחות מכירות', 'at-woo-gf-integration'); ?></h3>
                <p><?php esc_html_e('צפה בדוחות המכירות והמבצעים.', 'at-woo-gf-integration'); ?></p>
                <a href="<?php echo admin_url('admin.php?page=wc-reports'); ?>" class="button">
                    <?php esc_html_e('צפה בדוחות', 'at-woo-gf-integration'); ?>
                </a>
            </div>

            <div class="tool-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <h3><?php esc_html_e('קופונים', 'at-woo-gf-integration'); ?></h3>
                <p><?php esc_html_e('נהל קופוני הנחה ומבצעים.', 'at-woo-gf-integration'); ?></p>
                <a href="<?php echo admin_url('edit.php?post_type=shop_coupon'); ?>" class="button">
                    <?php esc_html_e('נהל קופונים', 'at-woo-gf-integration'); ?>
                </a>
            </div>
        </div>
    </div>
    <?php
}

function marketing_campaigns_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('ניהול קמפיינים', 'at-woo-gf-integration'); ?></h1>
        <p><?php esc_html_e('כאן תוכל לנהל את כל הקמפיינים השיווקיים שלך.', 'at-woo-gf-integration'); ?></p>

        <!-- הוסף כאן את התוכן של דף הקמפיינים -->
        <div class="notice notice-info">
            <p><?php esc_html_e('תכונה זו תפותח בעתיד. כרגע יש לך גישה לכלי השיווק הבסיסיים.', 'at-woo-gf-integration'); ?></p>
        </div>
    </div>
    <?php
}

/**
 * הערות חשובות לשימוש:
 *
 * 1. הוסף תפקידים חדשים רק פעם אחת - בדוק אם הם כבר קיימים
 * 2. תמיד בדוק הרשאות לפני ביצוע פעולות
 * 3. השתמש ב-esc_html() ו-esc_attr() לכל פלט HTML
 * 4. שמור על בטיחות - אל תיתן הרשאות מיותרות
 * 5. בדוק את הקוד בסביבת פיתוח לפני העלאה לייצור
 * 6. תעד כל שינוי בתפקידים והרשאות
 */
