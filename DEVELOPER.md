# מדריך למפתחים - AT WooCommerce Gravity Forms Integration

## סקירה כללית

תוסף זה מחבר בין WooCommerce ל-Gravity Forms ומאפשר ניהול הרשמות למוצרים ואירועים.

## ארכיטקטורה

### מבנה קבצים
```
at-woo-gf-integration/
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── dashboard.css
│   └── js/
│       ├── admin.js
│       └── dashboard.js
├── includes/
│   ├── class-ajax-handler.php
│   ├── class-event-attendees-limit.php
│   ├── class-event-form-template.php
│   ├── class-event-product-type.php
│   ├── class-product-event.php
│   ├── class-product-form-metabox.php
│   ├── class-registration-dashboard.php
│   └── class-updater.php
├── languages/
├── at-woo-gf-integration.php
├── uninstall.php
├── README.md
├── CHANGELOG.md
└── DEVELOPER.md
```

### מחלקות עיקריות

#### AT_Woo_GF_Integration
המחלקה הראשית של התוסף, אחראית על:
- בדיקת תלויות (WooCommerce ו-Gravity Forms)
- טעינת קבצים ומחלקות
- אתחול התוסף

#### Woo_GF_Product_Form_Metabox
מנהלת את הממשק בעמוד עריכת המוצר:
- הוספת כרטיסיית Gravity Forms
- שמירת קישור טופס-מוצר
- הצגת הרשמות

#### WooGF_Event_Product_Type
מגדירה את סוג המוצר "אירוע":
- רישום סוג המוצר
- ממשק ניהול אירועים
- שמירת נתוני אירוע

#### WC_Product_Event
מחלקת המוצר מסוג אירוע:
- יורשת מ-WC_Product
- מוסיפה שדות ייעודיים לאירועים
- משתמשת ב-WooCommerce data store

## Hooks ופילטרים

### Actions

```php
// מופעל לאחר יצירת טופס חדש
do_action( 'woo_gf_integration_form_created', $form_id, $product_id );

// מופעל כשרשומה מקושרת למוצר
do_action( 'woo_gf_product_associated', $entry_id, $product_id );
```

### Filters

```php
// סינון טפסים זמינים
$forms = apply_filters( 'woo_gf_available_forms', $forms );

// שינוי מספר רשומות לעמוד
$per_page = apply_filters( 'woo_gf_entries_per_page', 20 );

// התאמת הטופס המשוכפל לפני שמירתו
$form = apply_filters( 'woo_gf_integration_new_form', $form, $product_id );

// מזהה טופס ברירת המחדל שממנו משכפלים טופס הרשמה חדש
// (ברירת המחדל מגיעה מהאופציה at_woo_gf_template_form_id)
$template_id = apply_filters( 'at_woo_gf_template_form_id', $form_id_from_option );

// שם ברירת המחדל של טופס אירוע חדש ("הרשמה: <שם האירוע>")
$title = apply_filters( 'at_woo_gf_new_form_title', $title, $product_id );
```

### טופס ברירת המחדל (טופס התבנית)

מוצר אירוע חדש **אינו** מקבל טופס נבחר אוטומטית — מנהל האתר חייב לבחור טופס קיים או ליצור טופס חדש.

יצירת טופס חדש אינה בונה טופס מאפס: היא משכפלת את **טופס ברירת המחדל** באמצעות `GFFormsModel::duplicate_form()`, כך שהשדות, ההגדרות, ההתראות והאישורים מגיעים ממנו. לאחר השכפול הטופס מקבל שם חדש (`הרשמה: <שם האירוע>`, ניתן לעריכה בממשק המוצר), תיאור, קישור למוצר דרך `_woo_gf_form_id` ו-`woo_gf_linked_product_id` על הטופס, ומגבלת הרשמות לפי מלאי המוצר.

**מי בוחר את טופס ברירת המחדל:** מנהל האתר, במסך **דשבורד הרשמות → הגדרות טפסים** (`admin.php?page=at-woo-gf-event-forms`, הרשאה `manage_options`). הבחירה נשמרת באופציה `at_woo_gf_template_form_id`. סדר הפתרון: אופציה → `AT_Woo_GF_Event_Form_Template::LEGACY_DEFAULT_FORM_ID` (טופס 20, רק כשהאופציה מעולם לא נשמרה) → הפילטר `at_woo_gf_template_form_id`. ערך 0 שנשמר במפורש ("— לא נבחר —") מכובד ואינו נופל חזרה ל-20.

הלוגיקה כולה יושבת ב-`includes/class-event-form-template.php` (`AT_Woo_GF_Event_Form_Template`), ושני משטחי היצירה — נקודת ה-AJAX של עמוד המוצר ופעולת הדשבורד — קוראים לאותה מתודה `create_form_for_product( $product_id, [ 'title' => '', 'force' => false ] )` שמחזירה מערך או `WP_Error`.

**שני משטחי יצירה:**

| מקום | פעולה | הגנת כפילות |
|------|-------|--------------|
| עמוד עריכת מוצר → כרטיסיית Gravity Forms | "צור טופס חדש" / "החלף בטופס חדש" (AJAX) | `force = true` — המשתמש כבר אישר החלפה ב-`confirm()` |
| דשבורד הרשמות → טאב "אירועים ללא טופס" | "צור טופס לאירוע" (`admin-post.php`) | `force = false` — אירוע שכבר יש לו טופס מוחזר כשגיאה `already_linked` |

אם טופס ברירת המחדל לא הוגדר, נמחק, הועבר לאשפה או ש-Gravity Forms כבוי — הפעולה נכשלת עם הודעת שגיאה מפורשת בעברית (עם קישור למסך ההגדרות) ואינה יוצרת טופס חלקי.

### טאב "אירועים ללא טופס"

הטאב מציג מוצרים מסוג `event` שאין להם `_woo_gf_form_id`, שהערך שלהם ריק, **או** שהוא מצביע על טופס שנמחק/הועבר לאשפה ב-Gravity Forms (המקרה האחרון שובר הרשמה בשקט ולכן נכלל). לכל שורה כפתור "צור טופס לאירוע" ששולח ל-`admin-post.php` עם nonce ייעודי לכל מוצר.

## שימוש ב-API

### יצירת מוצר אירוע תכנותית

```php
// יצירת מוצר אירוע
$event = new WC_Product_Event();
$event->set_name( 'סדנת WordPress' );
$event->set_regular_price( 350 );
$event->set_event_date( '2024-02-15 18:00:00' );
$event->set_event_location( 'תל אביב, רחוב הארבעה 21' );
$event->set_max_attendees( 30 );
$event->set_event_type( 'physical' );
$event->save();
```

### שיוך טופס למוצר

```php
$product = wc_get_product( $product_id );
$product->update_meta_data( '_woo_gf_form_id', $form_id );
$product->save();
```

### קבלת מספר משתתפים רשומים

```php
$product_id = 123;
$form_id = get_post_meta( $product_id, '_woo_gf_form_id', true );

if ( $form_id && class_exists( 'GFAPI' ) ) {
    $search_criteria = array(
        'status' => 'active',
        'field_filters' => array(
            array(
                'key'   => 'woo_gf_product_id',
                'value' => $product_id,
            ),
        ),
    );
    
    $entry_count = GFAPI::count_entries( $form_id, $search_criteria );
}
```

## AJAX Endpoints

### woo_gf_get_entries
מחזיר רשימת הרשמות לטופס
- פרמטרים: form_id, product_id, page, nonce
- תגובה: HTML של טבלת הרשמות

### woo_gf_get_entry_details
מחזיר פרטי רשומה בודדת
- פרמטרים: entry_id, form_id, nonce
- תגובה: HTML של פרטי הרשומה

### haruv_create_gf_form_for_event
משכפל את טופס ברירת המחדל, נותן לו שם ומקשר אותו למוצר
- פרמטרים: `product_id`, `form_title` (אופציונלי — ברירת מחדל `הרשמה: <שם האירוע>`), `security` (nonce מסוג `haruv_event_gf_nonce`)
- הרשאות: `gravityforms_create_form` **וגם** `edit_post` על המוצר
- תגובה: `form_id`, `form_title`, `edit_url`, `message`
- המימוש הוא עטיפה דקה בלבד סביב `AT_Woo_GF_Event_Form_Template::create_form_for_product()`

## פעולות admin-post

### at_woo_gf_create_event_form
כפתור "צור טופס לאירוע" בטאב "אירועים ללא טופס" בדשבורד ההרשמות
- פרמטרים: `product_id`, `at_woo_gf_create_form_nonce` (nonce מסוג `at_woo_gf_create_event_form_{product_id}`)
- הרשאות: `gravityforms_create_form` (או `manage_options`) **וגם** `edit_post` על המוצר
- תוצאה: redirect חזרה לדשבורד + הודעת הצלחה/שגיאה שנשמרת ב-transient חד-פעמי לכל משתמש

## אבטחה

- כל פעולות ה-AJAX מוגנות ב-nonce
- בדיקת הרשאות משתמש
- סניטציה של כל הקלטים
- שימוש ב-prepared statements

## ביצועים

- שימוש ב-transients לקאשינג עדכונים
- טעינת נכסים רק בדפים הרלוונטיים
- AJAX לטעינת נתונים דינמית

## תאימות

- WordPress 5.8+
- WooCommerce 7.0+
- Gravity Forms 2.5+
- PHP 7.2+

## עדכונים מרחוק

התוסף כולל מערכת עדכונים מרחוק (`includes/class-updater.php`) שקוראת את מניפסט הגרסה מה-`Update URI` שבכותרת התוסף:
```
https://raw.githubusercontent.com/amit-trabelsi-digital/at-woo-gf-integration/dev/plugin-info.json
```

### מבנה תגובת שרת העדכונים (`plugin-info.json`)

```json
{
    "version": "2.13.2",
    "download_url": "https://github.com/amit-trabelsi-digital/at-woo-gf-integration/releases/download/v2.13.2/at-woo-gf-integration-2.13.2.zip",
    "homepage": "https://amit-trabelsi.co.il/",
    "author": "Amit Trabelsi",
    "tested": "6.7",
    "requires": "6.0",
    "requires_php": "7.4",
    "last_updated": "2026-07-08 00:00:00"
}
```

## דוגמאות קוד

### הוספת שדה מותאם אישית לטופס חדש

```php
add_filter( 'woo_gf_integration_new_form', function( $form, $product_id ) {
    // הוספת שדה תאריך לידה
    $form['fields'][] = array(
        'type' => 'date',
        'id' => 5,
        'label' => 'תאריך לידה',
        'isRequired' => false,
        'dateType' => 'datepicker',
        'calendarIconType' => 'calendar',
    );
    
    return $form;
}, 10, 2 );
```

### הוספת עמודה מותאמת לדשבורד ההרשמות

```php
add_filter( 'woo_gf_dashboard_columns', function( $columns ) {
    $columns['custom_field'] = __( 'שדה מותאם', 'my-textdomain' );
    return $columns;
} );

add_filter( 'woo_gf_dashboard_column_data', function( $data, $column, $entry ) {
    if ( 'custom_field' === $column ) {
        return rgar( $entry, '5' ); // Field ID 5
    }
    return $data;
}, 10, 3 );
```

## אינטגרציית ניוזלטר + תיעוד הסכמה (ActiveTrail / MyMarketing)

`includes/class-newsletter-consent.php` (מחלקה `AT_Newsletter_Consent`) הוא **מקור אמת אחד** לתיעוד הצטרפות/הסרה מהניוזלטר ולסנכרון מול ActiveTrail. נטען site-wide מה-constructor (כמו מודול העוגיות), כך שההלפר והטבלה קיימים גם ללא WooCommerce/GF.

### 🔴 הגדרה נדרשת בכל סביבה — המפתח לא בגיט

המפתח (Authorization token) של ActiveTrail **אינו** בקוד. הגדר ב-`wp-config.php` (קובץ שאינו מנוהל בגיט של התמה/התוסף):

```php
define( 'HARUV_ACTIVETRAIL_TOKEN', '<הערך של כותרת ה-Authorization מ-MyMarketing>' );
// אופציונלי — קבוצת/רשימת ברירת המחדל (ברירת מחדל 106435):
// define( 'HARUV_ACTIVETRAIL_GROUP', 106435 );
```

סדר עדיפויות לקריאת המפתח: קונסטנטה `HARUV_ACTIVETRAIL_TOKEN` → option `at_activetrail_token` → פילטר `haruv_activetrail_token`. אם לא הוגדר מפתח — נרשמת אזהרה (ב-`WP_DEBUG`) ולא מתבצעת קריאת רשת. שים לב: המחרוזת `Haruv2023&` שהופיעה בקוד הישן הייתה משתנה **לא בשימוש** — ערך ה-Authorization האמיתי הוא מחרוזת ה-hex הארוכה.

### מבנה ה-payload (תואם לאתר הישן)

`POST https://webapi.mymarketing.co.il/api/contacts/Import`, header `Authorization: <token>`, גוף:

```json
{ "group": 106435, "contacts": [ {
  "email": "", "first_name": "", "last_name": "", "phone1": "",
  "ext1": "<עיסוק>", "ext2": "<אזור>", "ext3": "<מקום עבודה>",
  "is_do_not_mail": false, "is_deleted": false } ] }
```

opt-out נשלח לאותו endpoint עם `is_do_not_mail: true` (ל-MyMarketing אין endpoint ציבורי מתועד ל-unsubscribe באינטגרציה זו — הנחה, לא להמציא list-ids/endpoints).

### טבלת audit trail

`{$wpdb->prefix}at_newsletter_consent_log` — נוצרת דרך `dbDelta` (version-checked ב-`init`). עמודות: `user_id`, `email`, `action` (`opt_in`/`opt_out`/`declined`), `source` (`newsletter_form`/`checkout`/`user_area`), `consent_text`, `consent_version`, `ip`, `created_at`. נשמרת גם בהסרת התוסף (רשומת compliance). מצב-נוכחי ממושכ ל-user meta `newsletter_subscription`.

### נקודות הרחבה

| Hook | תפקיד |
|------|-------|
| `haruv_log_newsletter_consent( $identity, $action, $source, $args )` | פונקציה גלובלית לתיעוד אירוע הסכמה (guarded). |
| `haruv_newsletter_activetrail_sync( $contact, $action )` | פונקציה גלובלית לסנכרון ל-ActiveTrail. |
| `haruv_newsletter_form_id` (filter) | מזהה טופס GF לניוזלטר (ברירת מחדל 21). |
| `haruv_activetrail_token` (filter) | override למפתח. |
| `haruv_activetrail_group` (filter) | override לקבוצה; מקבל גם slug שפה (Polylang) ל-routing עתידי. |
| `haruv_activetrail_payload` (filter) | שינוי ה-payload היוצא. |
| `haruv_newsletter_privacy_url` (filter) | לינק מדיניות פרטיות ב-checkout (ברירת מחדל `get_privacy_policy_url()`). |

## תמיכה

לשאלות ותמיכה: [amit@trabel.si](mailto:amit@trabel.si)

## רישיון

GPL v2 or later 