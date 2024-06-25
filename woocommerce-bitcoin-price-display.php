<?php
/*
Plugin Name: WooCommerce Bitcoin Price Display
Description: Displays prices in Bitcoin (sats) for WooCommerce products using BTCPay Server
Version: 1.1
Author: BtcPins
*/

// Don't allow direct access
if (!defined('ABSPATH')) exit;

// Initialize PHP session

function set_secure_cookie($name, $value, $expire = 0, $path = '/', $domain = '', $secure = true, $httponly = true) {
    $same_site = 'Lax'; // or 'Strict' depending on your needs
    
    if (PHP_VERSION_ID < 70300) {
        setcookie($name, $value, $expire, $path . '; samesite=' . $same_site, $domain, $secure, $httponly);
    } else {
        setcookie($name, $value, [
            'expires' => $expire,
            'path' => $path,
            'domain' => $domain,
            'samesite' => $same_site,
            'secure' => $secure,
            'httponly' => $httponly,
        ]);
    }
}

function wc_bitcoin_price_display_init_session() {
    if (!session_id() && !headers_sent()) {
        session_start();
    }
}
add_action('init', 'wc_bitcoin_price_display_init_session');

class WC_Bitcoin_Price_Display {
    private $btcpay_server;
    private $store_id;
    private $api_key;
    private $cache_key = 'wc_bitcoin_price_display_rate';
    private $cache_expiration = 600; // 10 minutes in seconds
    private $shop_currency;
	
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=wc-bitcoin-price-display">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
	
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
		add_action('update_option_woocommerce_currency', array($this, 'clear_rate_cache'));
		add_action('woocommerce_init', array($this, 'init_shop_currency'));
		add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'add_bitcoin_shipping'), 10, 2);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        add_filter('woocommerce_get_price_html', array($this, 'add_bitcoin_price'), 10, 2);
        add_filter('woocommerce_cart_item_price', array($this, 'add_bitcoin_price_cart'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'add_bitcoin_price_cart'), 10, 3);
        add_filter('woocommerce_cart_subtotal', array($this, 'add_bitcoin_subtotal'), 10, 3);
        add_filter('woocommerce_cart_totals_order_total_html', array($this, 'add_bitcoin_total'), 10, 1);

        add_filter('woocommerce_cart_item_price', array($this, 'update_mini_cart_price'), 10, 2);
        add_filter('woocommerce_widget_cart_item_quantity', array($this, 'update_mini_cart_price'), 10, 2);
        add_filter('woocommerce_cart_item_name', array($this, 'filter_mini_cart_item_name'), 10, 3);

        add_filter('woocommerce_add_to_cart_fragments', array($this, 'update_mini_cart_fragments'));
		add_filter('woocommerce_cart_totals_shipping_total', array($this, 'add_bitcoin_shipping_total'));
        add_filter('woocommerce_cart_totals_shipping_total', array($this, 'add_bitcoin_cart_shipping_total'));
        add_filter('woocommerce_order_shipping_to_display', array($this, 'add_bitcoin_order_shipping_total'), 10, 2);
		add_filter('woocommerce_shipping_rate_format_price', array($this, 'add_bitcoin_shipping'), 10, 2);
		add_filter('woocommerce_shipping_rate_cost', array($this, 'modify_shipping_rate_cost'), 10, 2);
		add_filter('woocommerce_package_rates', array($this, 'add_bitcoin_to_available_shipping_methods'), 100);
		add_filter('woocommerce_cart_tax_totals', array($this, 'add_bitcoin_tax_totals'), 10, 1);
        add_filter('woocommerce_cart_totals_taxes_total_html', array($this, 'add_bitcoin_cart_tax_total'), 10, 1);
        add_filter('woocommerce_order_tax_totals', array($this, 'add_bitcoin_order_tax_totals'), 10, 2);
        add_filter('woocommerce_get_price_including_tax', array($this, 'add_bitcoin_to_price_including_tax'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_footer', array($this, 'add_price_toggle'));
        add_action('wp_ajax_toggle_price_display', array($this, 'ajax_toggle_price_display'));
        add_action('wp_ajax_nopriv_toggle_price_display', array($this, 'ajax_toggle_price_display'));
		add_action('wp_ajax_update_bitcoin_prices', array($this, 'ajax_price_update'));
		add_action('wp_ajax_nopriv_update_bitcoin_prices', array($this, 'ajax_price_update'));

        if (!isset($_SESSION['price_display'])) {
            $_SESSION['price_display'] = 'bitcoin';
        }

        $this->load_settings();
    }
	
    public function init_shop_currency() {
        $this->shop_currency = get_woocommerce_currency();
    }

	
    public function register_settings() {
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_btcpay_server');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_store_id');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_api_key');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_mode');
		register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_rounding');

	}

    public function add_settings_page() {
        add_options_page('Bitcoin Price Display Settings', 'Bitcoin Price Display', 'manage_options', 'wc-bitcoin-price-display', array($this, 'settings_page'));
    }

public function settings_page() {
    ?>
    <div class="wrap">
        <h1>Bitcoin Price Display Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('wc_bitcoin_price_display_settings'); ?>
            <?php do_settings_sections('wc_bitcoin_price_display_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">BTCPay Server Address</th>
                    <td>
                        <input type="text" name="wc_bitcoin_price_display_btcpay_server" id="wc_bitcoin_price_display_btcpay_server" 
                               value="<?php echo esc_attr(get_option('wc_bitcoin_price_display_btcpay_server')); ?>" 
                               placeholder="https://your-btcpay-server.com" />
                        <p class="description">Enter your BTCPay Server URL (including https:// but without a trailing slash).</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Store ID</th>
                    <td><input type="text" name="wc_bitcoin_price_display_store_id" value="<?php echo esc_attr(get_option('wc_bitcoin_price_display_store_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="password" name="wc_bitcoin_price_display_api_key" value="<?php echo esc_attr(get_option('wc_bitcoin_price_display_api_key')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Display Mode</th>
                    <td>
                        <select name="wc_bitcoin_price_display_mode">
                            <option value="toggle" <?php selected(get_option('wc_bitcoin_price_display_mode'), 'toggle'); ?>>Toggle between original and Bitcoin</option>
                            <option value="bitcoin_only" <?php selected(get_option('wc_bitcoin_price_display_mode'), 'bitcoin_only'); ?>>Bitcoin price only</option>
                            <option value="side_by_side" <?php selected(get_option('wc_bitcoin_price_display_mode'), 'side_by_side'); ?>>Side by side</option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Rounding</th>
                    <td>
                        <select name="wc_bitcoin_price_display_rounding">
                            <option value="1" <?php selected(get_option('wc_bitcoin_price_display_rounding'), '1'); ?>>1 Satoshi</option>
                            <option value="10" <?php selected(get_option('wc_bitcoin_price_display_rounding'), '10'); ?>>10 Satoshis</option>
                            <option value="100" <?php selected(get_option('wc_bitcoin_price_display_rounding'), '100'); ?>>100 Satoshis</option>
                            <option value="1000" <?php selected(get_option('wc_bitcoin_price_display_rounding'), '1000'); ?>>1,000 Satoshis</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#wc_bitcoin_price_display_btcpay_server').on('change', function() {
            var url = $(this).val().trim();
            if (url && url.substr(-1) === '/') {
                url = url.substr(0, url.length - 1);
            }
            if (url && !url.startsWith('https://')) {
                url = 'https://' + url;
            }
            $(this).val(url);
        });
    });
    </script>
    <?php
}

 private function load_settings() {
        $this->btcpay_server = get_option('wc_bitcoin_price_display_btcpay_server');
        $this->store_id = get_option('wc_bitcoin_price_display_store_id');
        $this->api_key = get_option('wc_bitcoin_price_display_api_key');
    }
	
public function enqueue_scripts() {
    if (is_woocommerce() || is_cart() || is_checkout() || is_product() || is_shop() || is_front_page()) {
        wp_enqueue_script('jquery');
        $script_url = plugin_dir_url(__FILE__) . 'js/bitcoin-price-toggle.js';
        wp_enqueue_script('bitcoin-price-toggle', $script_url, array('jquery'), time(), true);
        
        $display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
        $current_display = $this->get_current_price_display();
        
        wp_localize_script('bitcoin-price-toggle', 'bitcoinPriceData', array(
            'initialDisplay' => $current_display,
            'displayMode' => $display_mode,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bitcoin_price_toggle_nonce')
        ));
        
        wp_enqueue_style('bitcoin-price-display', plugin_dir_url(__FILE__) . 'css/bitcoin-price-display.css');
    }
}

public function ajax_price_update() {
    check_ajax_referer('bitcoin_price_toggle_nonce', 'nonce');
    
    $fragments = array();
    
    // Update mini-cart
    ob_start();
    woocommerce_mini_cart();
    $mini_cart = ob_get_clean();
    $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
    
    // Update cart totals
    if (is_cart()) {
        ob_start();
        woocommerce_cart_totals();
        $cart_totals = ob_get_clean();
        $fragments['div.cart_totals'] = '<div class="cart_totals">' . $cart_totals . '</div>';
    }
    
    wp_send_json_success($fragments);
}

    public function add_price_toggle() {
        $display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
        if ($display_mode === 'toggle' && (is_woocommerce() || is_cart() || is_checkout() || is_product() || is_shop() || is_front_page())) {
            $current_display = $this->get_current_price_display();
            $button_text = $current_display === 'bitcoin' ? 'Show ' . $this->shop_currency : 'Show Bitcoin';
            ?>
            <div id="price-display-toggle" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;">
                <button id="toggle-price-display" class="bitcoin-toggle-btn"><?php echo esc_html($button_text); ?></button>
            </div>
            <?php
        }
    }
	
public function ajax_toggle_price_display() {
    $current_display = isset($_SESSION['price_display']) ? $_SESSION['price_display'] : 'bitcoin';
    $new_display = ($current_display === 'bitcoin') ? 'original' : 'bitcoin';
    $_SESSION['price_display'] = $new_display;
    $button_text = $new_display === 'bitcoin' ? 'Show ' . $this->shop_currency : 'Show Bitcoin';
    
    // Ensure the session is written
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    wp_send_json_success(array('display' => $new_display, 'button_text' => $button_text));
}

public function format_sats($sats) {
    if ($sats === 'N/A') {
        return 'N/A';
    }
    return '~' . number_format($sats, 0, '.', ',') . ' Sats';
}

public function get_bitcoin_price() {
        $cached_rate = get_transient($this->cache_key);
        if ($cached_rate !== false) {
            return $cached_rate;
        }

        $currency_pair = "BTC_{$this->shop_currency}";
        $url = "{$this->btcpay_server}/api/v1/stores/{$this->store_id}/rates?currencyPair={$currency_pair}";
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_key,
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data[0]['rate'])) {
            $rate = floatval($data[0]['rate']);
            set_transient($this->cache_key, $rate, $this->cache_expiration);
            return $rate;
        }

        return false;
}

    public function convert_to_sats($price) {
        $btc_price = $this->get_bitcoin_price();
        if ($btc_price === false) {
            return 'N/A';
        }
        $btc_amount = $price / $btc_price;
        $sats = round($btc_amount * 100000000); // Convert to satoshis
        $rounding = get_option('wc_bitcoin_price_display_rounding', 1000);
        return round($sats, -1 * strlen($rounding) + 1); // Round to nearest chosen value
    }
	
public function get_current_price_display() {
    $display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
    if ($display_mode === 'bitcoin_only') {
        return 'bitcoin';
    } elseif ($display_mode === 'side_by_side') {
        return 'both';
    } else {
        return isset($_SESSION['price_display']) ? $_SESSION['price_display'] : 'bitcoin';
    }
}

public function format_price($original_price, $bitcoin_price) {
    $display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
    $original_formatted = wc_price($original_price, array('currency' => $this->shop_currency));

    switch ($display_mode) {
        case 'bitcoin_only':
            return '<span class="price-wrapper"><span class="bitcoin-price">' . $bitcoin_price . '</span></span>';
        case 'side_by_side':
            return '<span class="price-wrapper">' . $original_formatted . ' / ' . $bitcoin_price . '</span>';
        case 'toggle':
        default:
            return '<span class="price-wrapper">' .
                   '<span class="original-price">' . $original_formatted . '</span>' .
                   '<span class="bitcoin-price">' . $bitcoin_price . '</span>' .
                   '</span>';
    }
}
	
    public function add_bitcoin_shipping($method_label, $method) {
        if ($method instanceof WC_Shipping_Rate) {
            $cost = $method->get_cost();
            if ($cost > 0) {
                $sats = $this->convert_to_sats($cost);
                $bitcoin_price = $this->format_sats($sats);
                $formatted_price = $this->format_price($cost, $bitcoin_price);
                return $method->get_label() . ': ' . $formatted_price;
            }
        }
        return $method_label;
    }
	
    public function modify_shipping_rate_cost($cost, $shipping_rate) {
        if ($shipping_rate instanceof WC_Shipping_Rate) {
            $sats = $this->convert_to_sats($cost);
            $bitcoin_price = $this->format_sats($sats);
            $shipping_rate->add_meta_data('bitcoin_price', $bitcoin_price);
        }
        return $cost;
    }
	
    public function add_bitcoin_shipping_total($total) {
        $shipping_total = WC()->cart->get_shipping_total();
        $taxes = WC()->cart->get_shipping_tax();
        $total_with_tax = $shipping_total + $taxes;
        
        $sats = $this->convert_to_sats($total_with_tax);
        $bitcoin_price = $this->format_sats($sats);
        return $this->format_price($total_with_tax, $bitcoin_price);
    }
	
public function add_bitcoin_to_available_shipping_methods($methods) {
    foreach ($methods as $method) {
        if ($method->cost > 0) {
            $sats = $this->convert_to_sats($method->cost);
            $bitcoin_price = $this->format_sats($sats);
            $method->label .= ' (' . $bitcoin_price . ')';
        }
    }
    return $methods;
}
	
    public function add_bitcoin_price($price_html, $product) {
        $price = $product->get_price();
        $sats = $this->convert_to_sats($price);
        $bitcoin_price = $this->format_sats($sats);
        return $this->format_price($price, $bitcoin_price);
    }
	
public function add_bitcoin_price_cart($price_html, $cart_item, $cart_item_key) {
    $price = $cart_item['line_subtotal'];
    $sats = $this->convert_to_sats($price);
    $bitcoin_price = $this->format_sats($sats);
    return $this->format_price($price, $bitcoin_price);
}

public function add_bitcoin_subtotal($subtotal, $compound, $cart) {
    $current_currency_subtotal = $cart->get_subtotal();
    $sats = $this->convert_to_sats($current_currency_subtotal);
    $bitcoin_subtotal = $this->format_sats($sats);
    return $this->format_price($current_currency_subtotal, $bitcoin_subtotal);
}

    public function add_bitcoin_tax_totals($tax_totals) {
        if (!empty($tax_totals)) {
            foreach ($tax_totals as $code => $tax) {
                $original_amount = $tax->amount;
                $sats = $this->convert_to_sats($original_amount);
                $bitcoin_amount = $this->format_sats($sats);
                $tax->formatted_amount = $this->format_price($original_amount, $bitcoin_amount);
            }
        }
        return $tax_totals;
    }

    public function add_bitcoin_cart_tax_total($tax_total) {
        $cart_tax = WC()->cart->get_taxes_total();
        $sats = $this->convert_to_sats($cart_tax);
        $bitcoin_tax = $this->format_sats($sats);
        return $this->format_price($cart_tax, $bitcoin_tax);
    }

    public function add_bitcoin_order_tax_totals($tax_totals_html, $order) {
        $tax_totals = $order->get_tax_totals();
        if (!empty($tax_totals)) {
            $tax_totals_html = '';
            foreach ($tax_totals as $code => $tax) {
                $original_amount = $tax->amount;
                $sats = $this->convert_to_sats($original_amount);
                $bitcoin_amount = $this->format_sats($sats);
                $formatted_amount = $this->format_price($original_amount, $bitcoin_amount);
                $tax_totals_html .= '<tr class="tax-rate tax-rate-' . esc_attr(sanitize_title($code)) . '">';
                $tax_totals_html .= '<th>' . esc_html($tax->label) . '</th>';
                $tax_totals_html .= '<td>' . $formatted_amount . '</td>';
                $tax_totals_html .= '</tr>';
            }
        }
        return $tax_totals_html;
    }

    public function add_bitcoin_to_price_including_tax($price_html, $product) {
        $price = wc_get_price_including_tax($product);
        $sats = $this->convert_to_sats($price);
        $bitcoin_price = $this->format_sats($sats);
        return $this->format_price($price, $bitcoin_price);
    }

public function add_bitcoin_total($total) {
    // Get the total in the shop's current currency
    $current_currency_total = WC()->cart->get_total('');
    $sats = $this->convert_to_sats($current_currency_total);
    $bitcoin_total = $this->format_sats($sats);
    return $this->format_price($current_currency_total, $bitcoin_total);
}

    public function update_mini_cart_price($price_html, $product) {
        if (is_a($product, 'WC_Product')) {
            $price = $product->get_price();
            $sats = $this->convert_to_sats($price);
            $bitcoin_price = $this->format_sats($sats);
            return $this->format_price($price, $bitcoin_price);
        }
        return $price_html;
    }

    public function filter_mini_cart_item_name($product_title, $cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        $quantity = $cart_item['quantity'];
        $price = $product->get_price() * $quantity;
        $sats = $this->convert_to_sats($price);
        $bitcoin_price = $this->format_sats($sats);
        
        $formatted_price = $this->format_price($price, $bitcoin_price);
        
        return $product_title . ' <span class="quantity">× ' . $quantity . '</span> <span class="amount">' . $formatted_price . '</span>';
    }
	
public function clear_rate_cache() {
    delete_transient($this->cache_key);
    error_log("Cleared Bitcoin rate cache due to currency change");
}

public function update_mini_cart_fragments($fragments) {
    ob_start();
    woocommerce_mini_cart();
    $mini_cart = ob_get_clean();

    $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';

    // Add total to fragments
    $cart_total = WC()->cart->get_total('');
    $sats = $this->convert_to_sats($cart_total);
    $bitcoin_total = $this->format_sats($sats);
    $formatted_total = $this->format_price($cart_total, $bitcoin_total);
	
    $fragments['p.woocommerce-mini-cart__total'] = '<p class="woocommerce-mini-cart__total total">' . 
        $formatted_total . 
        '</p>';

    return $fragments;
}

    public function add_bitcoin_cart_shipping_total($total) {
        if (is_cart() || is_checkout()) {
            $price = WC()->cart->get_shipping_total();
            $sats = $this->convert_to_sats($price);
            $bitcoin_price = $this->format_sats($sats);
            return $this->format_price($price, $bitcoin_price);
        }
        return $total;
    }

    public function add_bitcoin_order_shipping_total($total, $order) {
        $price = $order->get_shipping_total();
        $sats = $this->convert_to_sats($price);
        $bitcoin_price = $this->format_sats($sats);
        return $this->format_price($price, $bitcoin_price);
    }
}

function initialize_wc_bitcoin_price_display() {
    global $wc_bitcoin_price_display;
    $wc_bitcoin_price_display = new WC_Bitcoin_Price_Display();
}
add_action('plugins_loaded', 'initialize_wc_bitcoin_price_display');
