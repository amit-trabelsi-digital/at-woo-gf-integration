<?php
/**
 * Per-language translation of the shared event registration forms.
 *
 * One Gravity Forms form serves all three Polylang translations of an event
 * (capacity and entries are unified by woo_gf_get_canonical_product_id(), see
 * class-event-waitlist.php). This class makes that single form *render* in the
 * visitor's language.
 *
 * Translations are stored in Polylang's "String translations" panel — not in a
 * PHP array — so the content team can change wording without a developer.
 *
 * @package WooGFIntegration
 * @since   2.16.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers and applies Polylang string translations for event forms.
 */
class AT_Woo_GF_Form_I18n {

    /**
     * Instance of this class.
     *
     * @var AT_Woo_GF_Form_I18n
     */
    private static $instance = null;

    /**
     * Polylang string-translation group (the "Group" column / filter on
     * Languages → String translations).
     */
    const STRING_CONTEXT = 'Haruv Forms';

    /**
     * Transient holding the list of form ids we manage.
     */
    const FORM_IDS_TRANSIENT = 'at_woo_gf_i18n_form_ids';

    /**
     * Transient lifetime. Invalidated explicitly on the meta / option writes
     * that can change the list, so a long TTL is safe.
     */
    const FORM_IDS_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * Option holding the id of the form new event forms are duplicated from.
     * Duplicated here as a literal so this file has no load-order dependency on
     * AT_Woo_GF_Event_Form_Template.
     */
    const TEMPLATE_FORM_OPTION = 'at_woo_gf_template_form_id';

    /**
     * Entry meta written by Woo_GF_Product_Form_Metabox::save_product_id_to_entry().
     */
    const ENTRY_LANG_META = 'woo_gf_entry_lang';

    /**
     * Per-request memo of the managed form ids.
     *
     * @var int[]|null
     */
    private $form_ids = null;

    /**
     * Get the singleton instance of this class.
     *
     * @return AT_Woo_GF_Form_I18n
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * No-ops cleanly without Gravity Forms; every Polylang call downstream is
     * behind function_exists(), so the class is also inert when Polylang is
     * deactivated.
     */
    private function __construct() {
        if ( ! class_exists( 'GFAPI' ) ) {
            return;
        }

        /*
         * Registration is deliberately admin-only.
         *
         * Verified against the installed Polylang Pro 3.7.3 source:
         *
         *  - polylang-pro/vendor/wpsyntex/polylang/include/api.php:189
         *      pll_register_string() opens with
         *        `if ( PLL() instanceof PLL_Admin_Base )`
         *      so on a front-end request the call is a no-op by design.
         *
         *  - polylang-pro/vendor/wpsyntex/polylang/include/api.php:204
         *      pll__() is just `__( $string, 'pll_string' )`.
         *
         *  - polylang-pro/vendor/wpsyntex/polylang/include/base.php:113-131
         *      PLL_Base::load_strings_translations() imports the whole
         *      'pll_string' MO from the language term meta on
         *      `pll_language_defined` — i.e. the translation store is populated
         *      independently of registration.
         *
         * Conclusion: pll__() resolves on the front end whether or not the
         * string was registered in that request. Registration only feeds the
         * admin listing (PLL_Admin_Strings::get_strings(), consumed by the
         * strings table, the export tab and the machine-translation module), so
         * walking every event form on every front-end request would be pure
         * waste. No front-end transient of form *content* is needed.
         */
        if ( is_admin() ) {
            add_action( 'init', array( $this, 'register_strings' ), 20 );
        }

        // All four render paths: rendering alone leaves validation errors and
        // the admin entry view in the source language.
        add_filter( 'gform_pre_render', array( $this, 'translate_form' ), 20 );
        add_filter( 'gform_pre_validation', array( $this, 'translate_form' ), 20 );
        add_filter( 'gform_pre_submission_filter', array( $this, 'translate_form' ), 20 );
        add_filter( 'gform_admin_pre_render', array( $this, 'translate_form' ), 20 );

        add_filter( 'gform_confirmation', array( $this, 'translate_confirmation' ), 20, 4 );
        add_filter( 'gform_notification', array( $this, 'translate_notification' ), 20, 3 );

        // Keep the cached id list honest.
        add_action( 'added_post_meta', array( $this, 'maybe_flush_form_ids' ), 10, 3 );
        add_action( 'updated_post_meta', array( $this, 'maybe_flush_form_ids' ), 10, 3 );
        add_action( 'deleted_post_meta', array( $this, 'maybe_flush_form_ids' ), 10, 3 );
        add_action( 'update_option_' . self::TEMPLATE_FORM_OPTION, array( $this, 'flush_form_ids' ) );
        add_action( 'add_option_' . self::TEMPLATE_FORM_OPTION, array( $this, 'flush_form_ids' ) );
    }

    /* ── Managed form ids ───────────────────────────────────────────────── */

    /**
     * Every distinct `_woo_gf_form_id` on an event product, plus the template
     * form new event forms are duplicated from.
     *
     * @return int[]
     */
    public function get_form_ids() {
        if ( null !== $this->form_ids ) {
            return $this->form_ids;
        }

        $cached = get_transient( self::FORM_IDS_TRANSIENT );

        if ( is_array( $cached ) ) {
            $this->form_ids = $cached;
            return $this->form_ids;
        }

        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value
                   FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                  WHERE pm.meta_key = %s
                    AND pm.meta_value <> ''
                    AND p.post_type = %s
                    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
                    AND tt.taxonomy = %s
                    AND t.slug = %s",
                '_woo_gf_form_id',
                'product',
                'product_type',
                'event'
            )
        );

        $ids = array_map( 'absint', (array) $ids );

        $template_id = absint( get_option( self::TEMPLATE_FORM_OPTION, 0 ) );

        if ( $template_id ) {
            $ids[] = $template_id;
        }

        $ids = array_values( array_unique( array_filter( $ids ) ) );

        /**
         * Filter the forms whose strings are translated per language.
         *
         * @param int[] $ids Gravity Forms form ids.
         */
        $ids = array_map( 'absint', (array) apply_filters( 'at_woo_gf_i18n_form_ids', $ids ) );

        set_transient( self::FORM_IDS_TRANSIENT, $ids, self::FORM_IDS_TTL );

        $this->form_ids = $ids;

        return $this->form_ids;
    }

    /**
     * Is this form one of ours?
     *
     * @param int|string $form_id Form id.
     * @return bool
     */
    private function is_managed_form( $form_id ) {
        $form_id = absint( $form_id );

        return $form_id && in_array( $form_id, $this->get_form_ids(), true );
    }

    /**
     * Drop the cached id list when an event's form link changes.
     *
     * @param int    $meta_id   Meta row id (unused).
     * @param int    $object_id Post id (unused).
     * @param string $meta_key  Meta key.
     * @return void
     */
    public function maybe_flush_form_ids( $meta_id, $object_id, $meta_key ) {
        if ( '_woo_gf_form_id' === $meta_key ) {
            $this->flush_form_ids();
        }
    }

    /**
     * Drop the cached id list.
     *
     * @return void
     */
    public function flush_form_ids() {
        $this->form_ids = null;
        delete_transient( self::FORM_IDS_TRANSIENT );
    }

    /* ── Registration ───────────────────────────────────────────────────── */

    /**
     * Register every translatable string of every managed form.
     *
     * Runs on admin `init` only — see the note in the constructor.
     *
     * @return void
     */
    public function register_strings() {
        if ( ! function_exists( 'pll_register_string' ) ) {
            return;
        }

        foreach ( $this->get_form_ids() as $form_id ) {
            $form = GFAPI::get_form( $form_id );

            if ( ! is_array( $form ) ) {
                continue;
            }

            $this->register_string( rgar( $form, 'title' ) );
            $this->register_string( rgar( $form, 'description' ) );
            $this->register_string( rgars( $form, 'button/text' ) );

            if ( ! empty( $form['fields'] ) && is_array( $form['fields'] ) ) {
                foreach ( $form['fields'] as $field ) {
                    $this->register_field_strings( $field );
                }
            }

            if ( ! empty( $form['confirmations'] ) && is_array( $form['confirmations'] ) ) {
                foreach ( $form['confirmations'] as $confirmation ) {
                    if ( 'message' === rgar( $confirmation, 'type' ) ) {
                        $this->register_string( rgar( $confirmation, 'message' ) );
                    }
                }
            }

            if ( ! empty( $form['notifications'] ) && is_array( $form['notifications'] ) ) {
                foreach ( $form['notifications'] as $notification ) {
                    $this->register_string( rgar( $notification, 'subject' ) );
                    $this->register_string( rgar( $notification, 'message' ) );
                }
            }
        }
    }

    /**
     * Register the translatable strings of a single field.
     *
     * @param GF_Field|array $field Field.
     * @return void
     */
    private function register_field_strings( $field ) {
        foreach ( $this->field_text_properties() as $property ) {
            $this->register_string( $this->get_prop( $field, $property ) );
        }

        $choices = $this->get_prop( $field, 'choices' );

        if ( is_array( $choices ) ) {
            foreach ( $choices as $choice ) {
                if ( is_array( $choice ) ) {
                    $this->register_string( rgar( $choice, 'text' ) );
                }
            }
        }

        // Composite fields (name, address, list, email/password confirmation,
        // time…) keep their sub-labels on $field->inputs. The rendered label is
        // `customLabel` when set, otherwise `label` (GF_Field::get_input_label()
        // and GF_Field::complex_validation_message()), so both are registered.
        $inputs = $this->get_prop( $field, 'inputs' );

        if ( is_array( $inputs ) ) {
            foreach ( $inputs as $input ) {
                if ( ! is_array( $input ) ) {
                    continue;
                }

                $this->register_string( rgar( $input, 'label' ) );
                $this->register_string( rgar( $input, 'customLabel' ) );
                $this->register_string( rgar( $input, 'placeholder' ) );

                if ( ! empty( $input['choices'] ) && is_array( $input['choices'] ) ) {
                    foreach ( $input['choices'] as $choice ) {
                        if ( is_array( $choice ) ) {
                            $this->register_string( rgar( $choice, 'text' ) );
                        }
                    }
                }
            }
        }
    }

    /**
     * Scalar field properties that hold editor-authored, visitor-facing text.
     *
     * `checkboxLabel` is the consent field's own label; `content` is the HTML
     * field's body; `errorMessage` is the per-field custom validation message.
     * `adminLabel` is intentionally absent — it never reaches a visitor.
     *
     * @return string[]
     */
    private function field_text_properties() {
        return array( 'label', 'description', 'placeholder', 'checkboxLabel', 'content', 'errorMessage' );
    }

    /**
     * Register one string with Polylang.
     *
     * String NAMING SCHEME — keyed by the SOURCE TEXT, never by form/field id.
     * Every new event gets a freshly duplicated form with brand new field ids,
     * so an id-keyed name would force the team to re-translate "שם מלא" for
     * every event forever. Polylang itself stores registered strings in an array
     * keyed by md5( $string ) (PLL_Admin_Strings::register_string(), admin/
     * admin-strings.php:53) and saves translations against the string itself
     * (settings/table-string.php:405), so identical source text across forms
     * collapses to one row that every future duplicate inherits.
     *
     * The $name is therefore a *display label* only, with no uniqueness or
     * length constraint. We show a readable, deterministic excerpt: whitespace-
     * collapsed, tag-stripped, capped at 60 chars. When it has to be cut we
     * append the first 8 hex of md5( $string ) so two long strings that share an
     * opening never look like the same row in the admin table.
     *
     * @param mixed $text Candidate string.
     * @return void
     */
    private function register_string( $text ) {
        if ( ! is_string( $text ) || '' === trim( $text ) ) {
            return;
        }

        pll_register_string(
            $this->string_name( $text ),
            $text,
            self::STRING_CONTEXT,
            $this->is_multiline( $text )
        );
    }

    /**
     * Build the human-readable admin label for a registered string.
     *
     * @param string $text Source string.
     * @return string
     */
    private function string_name( $text ) {
        $name = wp_strip_all_tags( $text );
        $name = preg_replace( '/\s+/u', ' ', (string) $name );
        $name = trim( (string) $name );

        if ( '' === $name ) {
            $name = trim( preg_replace( '/\s+/u', ' ', $text ) );
        }

        if ( function_exists( 'mb_strlen' ) && mb_strlen( $name, 'UTF-8' ) > 60 ) {
            $name = mb_substr( $name, 0, 60, 'UTF-8' ) . '… [' . substr( md5( $text ), 0, 8 ) . ']';
        }

        return $name;
    }

    /**
     * Should the strings table offer a textarea rather than a single-line input?
     *
     * @param string $text Source string.
     * @return bool
     */
    private function is_multiline( $text ) {
        if ( false !== strpos( $text, "\n" ) ) {
            return true;
        }

        return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) > 80 : strlen( $text ) > 80;
    }

    /* ── Applying translations ──────────────────────────────────────────── */

    /**
     * Translate a managed form for rendering / validation / submission.
     *
     * Registered on gform_pre_render, gform_pre_validation,
     * gform_pre_submission_filter and gform_admin_pre_render.
     *
     * @param array $form Form array.
     * @return array
     */
    public function translate_form( $form ) {
        if ( ! is_array( $form ) || empty( $form['id'] ) ) {
            return $form;
        }

        if ( ! $this->polylang_ready() || ! $this->is_managed_form( $form['id'] ) ) {
            return $form;
        }

        $lang = '';

        if ( is_admin() && ! wp_doing_ajax() ) {
            if ( ! $this->admin_screen_is_read_only() ) {
                return $form;
            }

            // On the entry screens show the form as the registrant saw it.
            $lang = $this->entry_language( $this->current_admin_entry_id() );
        }

        foreach ( array( 'title', 'description' ) as $property ) {
            if ( ! empty( $form[ $property ] ) && is_string( $form[ $property ] ) ) {
                $form[ $property ] = $this->translate( $form[ $property ], $lang );
            }
        }

        if ( ! empty( $form['button']['text'] ) && is_string( $form['button']['text'] ) ) {
            $form['button']['text'] = $this->translate( $form['button']['text'], $lang );
        }

        if ( ! empty( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $this->translate_field( $field, $lang );
            }
        }

        return $form;
    }

    /**
     * Translate one field in place.
     *
     * @param GF_Field|array $field Field (GF passes objects; mutated by reference).
     * @param string         $lang  Target language slug, '' for the current one.
     * @return void
     */
    private function translate_field( $field, $lang ) {
        if ( ! is_object( $field ) ) {
            return;
        }

        foreach ( $this->field_text_properties() as $property ) {
            $value = $this->get_prop( $field, $property );

            if ( is_string( $value ) && '' !== $value ) {
                $field->{$property} = $this->translate( $value, $lang );
            }
        }

        // GF_Field has no __get(), so never touch a property that may not exist
        // on this field type — read through get_prop() instead.
        $choices = $this->get_prop( $field, 'choices' );

        if ( is_array( $choices ) ) {
            $field->choices = $this->translate_choices( $choices, $lang );
        }

        $inputs = $this->get_prop( $field, 'inputs' );

        if ( is_array( $inputs ) ) {
            foreach ( $inputs as $index => $input ) {
                if ( ! is_array( $input ) ) {
                    continue;
                }

                foreach ( array( 'label', 'customLabel', 'placeholder' ) as $property ) {
                    if ( isset( $input[ $property ] ) && is_string( $input[ $property ] ) && '' !== $input[ $property ] ) {
                        $inputs[ $index ][ $property ] = $this->translate( $input[ $property ], $lang );
                    }
                }

                if ( ! empty( $input['choices'] ) && is_array( $input['choices'] ) ) {
                    $inputs[ $index ]['choices'] = $this->translate_choices( $input['choices'], $lang );
                }
            }

            $field->inputs = $inputs;
        }
    }

    /**
     * Translate a choice list while freezing the submitted value.
     *
     * A choice with no explicit `value` submits its `text`
     * (GF_Field_Radio::get_choices() — "! empty( $choice['value'] ) ||
     * $this->enableChoiceValue ? $choice['value'] : $choice['text']" — and the
     * same expression in GFCommon::get_select_choices()). Translating `text`
     * alone would therefore make an English visitor store an English value and
     * an Arabic visitor an Arabic one for the *same* answer, splitting the
     * unified entry pool F166 just built, and would break validation outright if
     * the language context differs between render and submit.
     *
     * So we copy the ORIGINAL effective value into `value` before overwriting
     * `text`. Rendered markup then carries the Hebrew source as the value while
     * displaying the translation: entries stay canonical in every language, and
     * conditional-logic rules — which store the source text — keep matching.
     *
     * @param array  $choices Choice list.
     * @param string $lang    Target language slug, '' for the current one.
     * @return array
     */
    private function translate_choices( $choices, $lang ) {
        foreach ( $choices as $index => $choice ) {
            if ( ! is_array( $choice ) || ! isset( $choice['text'] ) || ! is_string( $choice['text'] ) || '' === $choice['text'] ) {
                continue;
            }

            $translated = $this->translate( $choice['text'], $lang );

            if ( $translated === $choice['text'] ) {
                continue;
            }

            if ( ! isset( $choice['value'] ) || '' === $choice['value'] ) {
                $choices[ $index ]['value'] = $choice['text'];
            }

            $choices[ $index ]['text'] = $translated;
        }

        return $choices;
    }

    /**
     * Translate the confirmation message in the registrant's language.
     *
     * gform_confirmation receives the message *after* merge-tag replacement, so
     * it can no longer be matched against the registered raw string. We take the
     * raw message GF selected ($form['confirmation'], set by
     * GFFormDisplay::update_confirmation() right before this filter), translate
     * that, and let GF rebuild the output through its own
     * GFFormDisplay::get_confirmation_message() so autoformatting, sanitising
     * and the confirmation wrapper markup stay identical.
     *
     * @param string|array $confirmation Confirmation output (array = redirect).
     * @param array        $form         Form.
     * @param array        $entry        Entry.
     * @param bool         $ajax         Whether the form is ajax-enabled (unused).
     * @return string|array
     */
    public function translate_confirmation( $confirmation, $form, $entry, $ajax = false ) {
        if ( ! is_string( $confirmation ) || '' === $confirmation ) {
            return $confirmation;
        }

        if ( ! $this->polylang_ready() || ! is_array( $form ) || empty( $form['id'] ) ) {
            return $confirmation;
        }

        if ( ! $this->is_managed_form( $form['id'] ) ) {
            return $confirmation;
        }

        $source = rgar( $form, 'confirmation' );

        if ( ! is_array( $source ) || 'message' !== rgar( $source, 'type' ) ) {
            return $confirmation;
        }

        $message = rgar( $source, 'message' );

        if ( ! is_string( $message ) || '' === $message ) {
            return $confirmation;
        }

        $lang       = $this->entry_language( rgar( (array) $entry, 'id' ) );
        $translated = $this->translate( $message, $lang );

        if ( $translated === $message ) {
            return $confirmation;
        }

        if ( ! class_exists( 'GFFormDisplay' ) || ! method_exists( 'GFFormDisplay', 'get_confirmation_message' ) ) {
            return $confirmation;
        }

        $source['message'] = $translated;

        return GFFormDisplay::get_confirmation_message( $source, $form, $entry );
    }

    /**
     * Translate a notification into the language the entry was submitted in.
     *
     * The language MUST come from the entry, not from pll_current_language():
     * an admin resending a notification months later does so in their own
     * language, and the registrant should still get their own.
     *
     * @param array $notification Notification.
     * @param array $form         Form.
     * @param array $entry        Entry.
     * @return array
     */
    public function translate_notification( $notification, $form, $entry ) {
        if ( ! is_array( $notification ) || ! $this->polylang_ready() ) {
            return $notification;
        }

        if ( ! is_array( $form ) || empty( $form['id'] ) || ! $this->is_managed_form( $form['id'] ) ) {
            return $notification;
        }

        $lang = $this->entry_language( rgar( (array) $entry, 'id' ) );

        if ( '' === $lang ) {
            return $notification;
        }

        foreach ( array( 'subject', 'message' ) as $property ) {
            if ( ! empty( $notification[ $property ] ) && is_string( $notification[ $property ] ) ) {
                $notification[ $property ] = $this->translate( $notification[ $property ], $lang );
            }
        }

        return $notification;
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    /**
     * Translate one string.
     *
     * With an empty $lang the current language is used (pll__(), which reads the
     * 'pll_string' text domain Polylang loads from the language term meta). With
     * an explicit $lang we use pll_translate_string( $string, $lang ) — present
     * in the installed Polylang Pro 3.7.3 at
     * polylang-pro/vendor/wpsyntex/polylang/include/api.php:288 (@since 1.5.4).
     * It short-circuits to pll__() when the requested language is already the
     * current front-end one and otherwise imports that language's PLL_MO
     * directly, so no global language state is mutated.
     *
     * @param mixed  $text Source string.
     * @param string $lang Target language slug, '' for the current language.
     * @return mixed Translated string, or the input unchanged.
     */
    private function translate( $text, $lang = '' ) {
        if ( ! is_string( $text ) || '' === trim( $text ) ) {
            return $text;
        }

        if ( '' === $lang ) {
            if ( ! function_exists( 'pll__' ) ) {
                return $text;
            }

            $translated = pll__( $text );
        } else {
            if ( ! function_exists( 'pll_translate_string' ) ) {
                return $text;
            }

            $translated = pll_translate_string( $text, $lang );
        }

        return ( is_string( $translated ) && '' !== $translated ) ? $translated : $text;
    }

    /**
     * Is Polylang loaded far enough to translate?
     *
     * pll_translate_string() dereferences PLL()->model, so the object has to
     * exist before we call anything.
     *
     * @return bool
     */
    private function polylang_ready() {
        if ( ! function_exists( 'pll__' ) || ! function_exists( 'PLL' ) ) {
            return false;
        }

        return (bool) PLL();
    }

    /**
     * Resolve the language a notification / confirmation should speak.
     *
     * Order: the entry's own stamp (woo_gf_entry_lang, written since 2.15.0) →
     * the current language → the default language. Entries created before that
     * stamp existed simply fall through, which reproduces the previous
     * behaviour instead of erroring.
     *
     * @param int|string $entry_id Entry id.
     * @return string Language slug, or '' when Polylang cannot resolve one.
     */
    private function entry_language( $entry_id ) {
        $entry_id = absint( $entry_id );

        if ( $entry_id && function_exists( 'gform_get_meta' ) ) {
            $stamped = gform_get_meta( $entry_id, self::ENTRY_LANG_META );

            if ( is_string( $stamped ) && '' !== $stamped && $this->is_known_language( $stamped ) ) {
                return $stamped;
            }
        }

        if ( function_exists( 'pll_current_language' ) ) {
            $current = pll_current_language();

            if ( is_string( $current ) && '' !== $current ) {
                return $current;
            }
        }

        if ( function_exists( 'pll_default_language' ) ) {
            $default = pll_default_language();

            if ( is_string( $default ) && '' !== $default ) {
                return $default;
            }
        }

        return '';
    }

    /**
     * Guard against a stale or hand-edited language stamp.
     *
     * @param string $lang Language slug.
     * @return bool
     */
    private function is_known_language( $lang ) {
        if ( ! function_exists( 'pll_languages_list' ) ) {
            return false;
        }

        $languages = pll_languages_list();

        return is_array( $languages ) && in_array( $lang, $languages, true );
    }

    /**
     * Should gform_admin_pre_render translate on the current admin screen?
     *
     * DECISION: only the entry screens, never the form editor or the form /
     * confirmation / notification settings screens.
     *
     * form_detail.php:156, form_settings.php:805 and
     * includes/class-confirmation.php:500 all pipe the form array through
     * GFCommon::gform_admin_pre_render() and then seed the editor state from it.
     * A translated array reaching those screens means an admin browsing the
     * admin in English would save the English labels straight over the Hebrew
     * source — silently destroying the very strings the translations are keyed
     * on. The editor must always show the source form.
     *
     * The entry views (entry_detail.php:126) are read-only with respect to the
     * form definition, and there the admin genuinely wants to see the form as
     * the registrant saw it, so those are translated into the entry's language.
     *
     * @return bool
     */
    private function admin_screen_is_read_only() {
        if ( ! class_exists( 'GFForms' ) || ! method_exists( 'GFForms', 'get_page' ) ) {
            return false;
        }

        return in_array( GFForms::get_page(), array( 'entry_detail', 'entry_detail_edit' ), true );
    }

    /**
     * Entry id of the admin entry screen currently being viewed.
     *
     * Read-only lookup used purely to pick a display language, hence no nonce.
     *
     * @return int
     */
    private function current_admin_entry_id() {
        if ( isset( $_GET['lid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return absint( wp_unslash( $_GET['lid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( isset( $_POST['lid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return absint( wp_unslash( $_POST['lid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        return 0;
    }

    /**
     * Read a property from a GF_Field object or a plain field array.
     *
     * @param GF_Field|array $field    Field.
     * @param string         $property Property name.
     * @return mixed
     */
    private function get_prop( $field, $property ) {
        if ( is_array( $field ) ) {
            return rgar( $field, $property );
        }

        if ( is_object( $field ) ) {
            return isset( $field->{$property} ) ? $field->{$property} : null;
        }

        return null;
    }
}
