<?php

/**
 * Plugin Name: JSON Shipping
 * Plugin URI:
 * Description: Custom Shipping Method for WooCommerce based оn Json
 * Version: 1.0.0
 * Author: Timur Moiseenko
 * Author URI: //tmoiseenko.ru
 * License: GPL-3.0+
 * License URI: //www.gnu.org/licenses/gpl-3.0.html
 * Domain Path: /lang
 */

if (!defined('WPINC')) {

    die;

}

/*
 * Check if WooCommerce is active
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function loadMyScript() {
        wp_register_script( 'stone', plugins_url( '/index.js', __FILE__ ) );
        wp_enqueue_script( 'stone' );
    }

    add_action( 'admin_enqueue_scripts', 'loadMyScript' );


    function json_shipping_method()
    {
        if (!class_exists('json_Shipping_Method')) {
            class json_Shipping_Method extends WC_Shipping_Method
            {

                /**
                 * Constructor for your shipping class
                 *
                 * @access public
                 * @return void
                 */
                public function __construct()
                {
                    $this->id = 'stone-shipping';
                    $this->method_title = __('JSON Shipping', 'json_shipping');
                    $this->method_description = __('Custom Shipping Method for JSON Shipping', 'json_shipping');
                    $this->availability = 'including';

                    $this->init();

                    $this->enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : 'yes';
                    $this->title = isset($this->settings['title']) ? $this->settings['title'] : __('JSON Shipping', 'json_shipping');

                    $data = json_decode($this->settings['raw_data'], true) ?? [];
                    $countries = [];
                    foreach ($data as $code => $val) {
                        $countries[] = $code;
                    }
                    $this->countries = $countries;
                }

                /**
                 * Init your settings
                 *
                 * @access public
                 * @return void
                 */
                function init()
                {
                    // Load the settings API
                    $this->init_form_fields();
                    $this->init_settings();

                    // Save settings in admin if you have any defined
                    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));

                }

                /**
                 * Define settings field for this shipping
                 * @return void
                 */
                function init_form_fields()
                {
					//Get current language
                    $current_lang = defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE ? ICL_LANGUAGE_CODE:'et';

                    // Get active languages from WPML
                    $active_languages = ['et' => 'et']; //Default
                    if ( in_array( 'sitepress-multilingual-cms/sitepress.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
                        $active_languages = apply_filters( 'wpml_active_languages', NULL, 'skip_missing=0&orderby=code&order=asc' );
                    }
					
                    $this->form_fields = array(
                        'enabled' => array(
                            'title' => __('Enable', 'json_shipping'),
                            'type' => 'checkbox',
                            'description' => __('Enable this shipping.', 'json_shipping'),
                            'default' => 'yes'
                        ),
                        'title' => array(
                            'title' => __('Title', 'json_shipping'),
                            'type' => 'text',
                            'description' => __('The name of the delivery method with the number of calculated pallets, 
                            instead of the quantity, there should be a %s symbol', 'json_shipping'),
                            'default' => __('JSON Shipping', 'json_shipping')
                        ),
                        'total_weight' => array(
                            'title' => __('Total weight (kg)', 'json_shipping'),
                            'type' => 'number',
                            'description' => __('Maximum weight for euro pallets', 'json_shipping'),
                            'default' => 700
                        ),
                        'pallet_weight' => array(
                            'title' => __('Pallet weight (kg)', 'json_shipping'),
                            'type' => 'number',
                            'description' => __('Weight one euro pallets', 'json_shipping'),
                            'default' => 20
                        ),
                        'country_error_' . $current_lang => array(
                            'title' => __('Country error', 'json_shipping'),
                            'type' => 'text',
                            'description' => __('An error that will be shown when choosing a country to which delivery is not carried out', 'json_shipping'),
                            'default' => __('Shipping to this country code is not available', 'json_shipping')
                        ),
                        'zip_code_error_' . $current_lang => array(
                            'title' => __('zip code error', 'json_shipping'),
                            'type' => 'text',
                            'description' => __('An error that will be shown when choosing a zip code to which delivery is not carried out', 'json_shipping'),
                            'default' => __('Shipping to this zip code is not available', 'json_shipping')
                        ),
                        'raw_data' => array(
                            'title' => __('Json data', 'json_shipping'),
                            'type' => 'textarea',
                            'description' => __("Data for delivery zones in json format", 'json_shipping'),
                            'css' => 'height: 500px;',
                        ),
                    );
                }

                public function process_admin_options()
                {
                    $this->init_settings();

                    $post_data = $this->get_post_data();
                    foreach ($this->get_form_fields() as $key => $field) {
                        if ('title' !== $this->get_field_type($field)) {
                            try {
                                $this->settings[$key] = $this->get_field_value($key, $field, $post_data);
                            } catch (Exception $e) {
                                $this->add_error($e->getMessage());
                            }
                        }
                    }
                    return update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings), 'yes');
                }

                /**
                 * This function is used to calculate the shipping cost. Within this function we can check for weights, dimensions and other parameters.
                 *
                 * @access public
                 * @param mixed $package
                 * @return void
                 */
                public function calculate_shipping($package = array())
                {
                    $data = json_decode($this->settings['raw_data'], true);
                    $countryCode = $package['destination']['country'];
                    $zones = $data[$countryCode]['zones'] ?? [];
                    $prices = $data[$countryCode]['prices'] ?? [];
                    $rule = $data[$countryCode]['rule'] ?? [];
                    $excludes = $data[$countryCode]['excludes'] ?? [];
                    $zoneCodeName = '';
                    $postcode = 0;
                    $weight = 0;
                    $totalPrice = 0;
                    $clearWeigthOnPallet = (int)$this->settings['total_weight'] - (int)$this->settings['pallet_weight'];

                    foreach ($package['contents'] as $item_id => $values) {
                        $_product = $values['data'];
                        $weight = $weight + $_product->get_weight() * $values['quantity'];
                    }

                    $weight = wc_get_weight($weight, 'kg');

                    switch ($rule) {
                        case 'all':
                        case "all range":
                            $postcode = (int)$package['destination']['postcode'];
                            break;
                        case "first two range":
                            $postcode = (int)substr($package['destination']['postcode'], 0, 2);
                            break;
                    }

                    foreach ($zones as $zoneKey => $zoneVal) {

                        if (is_string($zoneVal)) {
                            $zoneCodeName = $zoneKey;
                            continue;
                        } else {
                            if (is_array($zoneVal)) {
                                foreach ($zoneVal as $item) {

                                    if ($postcode >= (int)$item['min'] && $postcode <= (int)$item['max']) {

                                        $zoneCodeName = $zoneKey;
                                    }
                                }
                            }
                        }
                    }

                    if($zoneCodeName != "") {
                        $zonePrice = $prices[$zoneCodeName];
                    } else {
                        $zonePrice = max($prices);
                    }
					
                    $pallets = ceil($weight / $clearWeigthOnPallet);
                    $totalPrice = $zonePrice * $pallets;
                    $message = sprintf($this->settings['title'], $pallets);

                    $rate = array(
                        'id' => $this->id,
                        'label' => $message,
                        'cost' => $totalPrice,
                    );

                    $this->add_rate($rate);
                }
            }
        }
    }

    add_action('woocommerce_shipping_init', 'json_shipping_method');

    function add_json_shipping_method($methods)
    {
        $methods[] = 'json_Shipping_Method';
        return $methods;
    }

    add_filter('woocommerce_shipping_methods', 'add_json_shipping_method');

    function json_shipping_validate_order($fields, $errors)
    {
        //Get current language
        $current_lang = defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE ? ICL_LANGUAGE_CODE:'et';

        // Get active languages from WPML
        $active_languages = ['et' => 'et']; //Default
        if ( in_array( 'sitepress-multilingual-cms/sitepress.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            $active_languages = apply_filters( 'wpml_active_languages', NULL, 'skip_missing=0&orderby=code&order=asc' );
        }
        
        $json_shipping = new json_Shipping_Method();
        $data = json_decode($json_shipping->settings['raw_data'], true);
        $excludes = $data[$fields['billing_country']]['excludes'] ?? [];
        $country = $fields['billing_country'];
        $countries = [];
        foreach ($data as $code => $item) {
            $countries[] = $code;
        }
        if(!in_array($country, $countries)) {
            $errors->add('validation', $json_shipping->settings['country_error']);
        } else {
            if (is_array($excludes)) {
                if (in_array($fields['billing_postcode'], $excludes)) {
                    $errors->add('validation', $json_shipping->settings['zip_code_error_' . $current_lang]);
                }
            } else {
                if ($excludes == $fields['billing_postcode']) {
                    $errors->add('validation', $json_shipping->settings['zip_code_error_' . $current_lang]);
                }
            }
        }
    }
    add_action('woocommerce_after_checkout_validation', 'json_shipping_validate_order', 25, 2);
    
	add_filter( 'woocommerce_validate_postcode', 'filter_function_name_2095', 10, 3 );
    function filter_function_name_2095( $valid, $postcode, $country ){
        if ( strlen( trim( preg_replace( '/[\s\-A-Za-z0-9]/', '', $postcode ) ) ) > 0 ) {
            return false;
        }

        switch ( $country ) {
            case 'DK':
            case 'LV':
                $valid = (bool) preg_match( '/^([1-9][0-9]{3})$/', $postcode );
                break;
            case 'SE':
            case 'EE':
            case 'LT':
                $valid = (bool) preg_match( '/^([1-9][0-9]{4})$/', $postcode );
                break;
            case 'PL':
                $valid = (bool) preg_match( '/^([0-9]{2})([-])([0-9]{3})$/', $postcode );
                break;
            case 'FI':
                $valid = (bool) preg_match( '/^([0-9]{5})$/', $postcode );
                break;

            default:
                $valid = true;
                break;
        }

        return $valid;
    }
	
}