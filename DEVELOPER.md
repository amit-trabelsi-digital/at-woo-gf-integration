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

// התאמת טופס חדש לפני יצירה
$form = apply_filters( 'woo_gf_integration_new_form', $form, $product_id );
```

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

### woo_gf_create_form
יוצר טופס חדש אוטומטית
- פרמטרים: product_id, nonce
- תגובה: form_id, message, redirect

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

## תמיכה

לשאלות ותמיכה: [amit@trabel.si](mailto:amit@trabel.si)

## רישיון

GPL v2 or later 