<?php
/**
 * Gravity Forms Product Link Setting
 * 
 * @package WooGFIntegration
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class to add a "Linked Product" setting to Gravity Forms
 */
class Woo_GF_Product_Link_Setting {

    /**
     * Instance of this class.
     * @var Woo_GF_Product_Link_Setting
     */
    private static $instance = null;

    /**
     * Get the singleton instance of this class.
     * @return Woo_GF_Product_Link_Setting
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
        add_filter( 'gform_form_settings_fields', array( $this, 'add_form_settings_field' ), 10, 2 );
    }

    /**
     * Add a "Linked Product" setting to the Form Settings page.
     *
     * @param array $fields The form settings fields.
     * @param array $form   The current form object.
     * @return array The modified settings fields.
     */
    public function add_form_settings_field( $fields, $form ) {
        
        $product_options = array(
            array( 'label' => __( 'ללא מוצר מקושר', 'at-woo-gf-integration' ), 'value' => '' )
        );

        // For sites with many products, this could be slow. Consider a searchable/AJAX solution.
        $products = get_posts( array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        if ( ! empty( $products ) ) {
            foreach ( $products as $product ) {
                $product_options[] = array(
                    'label' => $product->post_title,
                    'value' => $product->ID,
                );
            }
        }
        
        $fields['woo_gf_integration'] = array(
            'title'  => __( 'קישור למוצר WooCommerce', 'at-woo-gf-integration' ),
            'fields' => array(
                array(
                    'label'   => __( 'מוצר מקושר', 'at-woo-gf-integration' ),
                    'type'    => 'select',
                    'name'    => 'woo_gf_linked_product_id',
                    'tooltip' => '<h6>' . __( 'מוצר מקושר', 'at-woo-gf-integration' ) . '</h6>' . __( 'בחר את המוצר שאליו טופס זה מקושר. הקישור יתעדכן אוטומטית בעת שמירת מוצר ב-WooCommerce ובחירת טופס זה.', 'at-woo-gf-integration' ),
                    'choices' => $product_options,
                    'value'   => rgar( $form, 'woo_gf_linked_product_id' )
                ),
            ),
        );

        return $fields;
    }
} 