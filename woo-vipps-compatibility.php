<?php
/*
This file is part of the plugin Checkout with Vipps for WooCommerce
Copyright (c) 2019 WP-Hosting AS

MIT License

Copyright (c) 2019 WP-Hosting AS

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// This file collects actions, hooks and filters that are specific to third-party plugins that need extra support.

// Support Yith WooCommerce Name your price. We need to load the front-end filters when doing express checkout - otherwise price will be zero.
// Unfortunately, we can't do this before priority 10 for plugins loaded to support their 'premium' stuff. IOK 2021-09-29
add_action('plugins_loaded', function () {
    if (function_exists('YITH_Name_Your_Price_Frontend')) {
        if (is_admin() && defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] == 'do_express_checkout') {
            YITH_Name_Your_Price_Frontend();
        }
    }

    // Snap Pixel for WooCommerce adds javascript to ajax functions if the do add-to-cart. We do that for single product express checkout,
    // so better remove that action. IOK 2022-05-13
    if (class_exists('snap_pixel_functions')) {
        add_action('woocommerce_add_to_cart', function ($args) {
            $doing_express_checkout = (did_action('wp_ajax_nopriv_do_single_product_express_checkout') || did_action('wp_ajax_do_single_product_express_checkout'));
            if (!$doing_express_checkout) return;
            global $wp_filter;
            $carthooks = @$wp_filter['woocommerce_add_to_cart'];
            if ($carthooks && $carthooks[10]) {
                foreach($carthooks[10] as $callback) {
                    $f = $callback['function'];
                    if (is_array($f) && $f[1] == 'snap_pixel_code_add_to_cart')  {
                        remove_action('woocommerce_add_to_cart', array($f[0], $f[1]), 10);
                    }
                }
            }
        },1, 2);

    }

}, 20);

// IOK 2020-03-17: Klarna Checkout now supports external payment methods, such as Vipps. This is great, but we need first to check
// that any user hasn't already installed the free plugin for this created by Krokedil. If they have, this filter will be present:
add_action('plugins_loaded', function () {
    if (class_exists('KCO') && defined('KCO_WC_VERSION') && version_compare(KCO_WC_VERSION, '2.0.0', '>=') && Vipps::instance()->gateway()->enabled == 'yes') {
        if (has_filter('kco_wc_api_request_args', 'kcoepm_create_order_vipps')) {
            // Vipps external payment support is already present - do nothing. IOK 2021-09-29
        } else {
            require_once(dirname(__FILE__) . "/VippsKCSupport.class.php");
            VippsKCSupport::init();
        }
    }


    // IOK 2022-06-28 This plugin erroneously tries to create an order on the order-received page when reached via the 
    // Vipps Express Checkout route. Reported, but avoiding the issue by disabling it on this page.
    if (class_exists('DIBS_Easy')) {
        add_action('template_redirect', function () {
            if (is_order_received_page()) {
                global $wp;
                if (!isset($wp->query_vars['order-received'])) return;
                $order_id  = absint($wp->query_vars['order-received']);
                $order = wc_get_order($order_id);
                if (!$order || is_wp_error($order)) {
                    return;
                }
                if ($order->get_payment_method() != 'vipps') {
                    return;
                }
                add_filter('option_woocommerce_dibs_easy_settings', function ($value, $option) {
                    if (!empty($value)) {
                        $value['enabled'] = 'no';
                        $value['description'] = '#yolo';
                        $value['test_mode'] = 'no';
                            return $value;
                    } else {
                    }
                    return $value;
                }, 10, 2);
            }
        });
    }

    // IOK 2022-11-24 support adwords-tracking with Monster Insights
    if (class_exists('MonsterInsights_eCommerce_WooCommerce_Integration')) {
        add_action('woo_vipps_express_checkout_order_created', function ($orderid) {
            $mi = MonsterInsights_eCommerce_WooCommerce_Integration::get_instance();
	    $vipps = Vipps::instance();
            if (method_exists($mi, 'save_user_cid')) {
		$vipps->log("Saving monster insights tracking info to order $orderid", 'debug');
                $mi->save_user_cid($orderid);
	    } else {
		$vipps->log("Tried adding monster insights session to order $orderid, but save_user_cid does not exist in object", 'debug');
	    }
        });
    }

});



// Anti-support for WooCommerce subscriptions; but allow turning it off using an (advanced) setting or filter. IOK 2021-10-26
add_filter('woo_vipps_is_available', function ($ok, $gateway) {
    if (!$ok) return $ok;
    // Can't do these, so remove Vipps as payment method  IOK 2021-05-14
    if (class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription()) {
      $ok = ($gateway->get_option('support_subscription_cart') == 'yes');
      $ok = apply_filters('woo_vipps_support_subscription_cart', $ok);
    }
    return $ok;
}, 10, 2);



