<?php
/*
Plugin Name: WooCommerce Bitcoin Price Display
Description: Displays prices in Bitcoin (sats) for WooCommerce products using BTCPay Server (compatible with BTCPay Server 1.x and 2.0)
Version: 1.4.0
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
	private $range_option;
    private $rounding_thousand;
    private $thousand_suffix;
	
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
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

		if (is_admin()) {
			add_action('admin_init', array($this, 'remove_bitcoin_price_admin'));
		} else {
			add_filter('woocommerce_get_price_html', array($this, 'add_bitcoin_price'), 10, 2);
			add_filter('woocommerce_cart_item_price', array($this, 'add_bitcoin_price_cart'), 10, 3);
			add_filter('woocommerce_cart_item_subtotal', array($this, 'add_bitcoin_price_cart'), 10, 3);
			add_filter('woocommerce_cart_subtotal', array($this, 'add_bitcoin_subtotal'), 10, 3);
			add_filter('woocommerce_cart_totals_order_total_html', array($this, 'add_bitcoin_total'), 10, 1);
			add_filter('woocommerce_add_to_cart_fragments', array($this, 'update_mini_cart_fragments'));
			add_filter('woocommerce_cart_item_price', array($this, 'update_mini_cart_price'), 10, 2);
			add_filter('woocommerce_widget_cart_item_quantity', array($this, 'update_mini_cart_price'), 10, 2);
			add_filter('woocommerce_cart_item_name', array($this, 'filter_mini_cart_item_name'), 10, 3);
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
			add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'add_bitcoin_shipping'), 10, 2);
		}

		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('wp_footer', array($this, 'add_price_toggle'));
		add_action('wp_ajax_toggle_price_display', array($this, 'ajax_toggle_price_display'));
		add_action('wp_ajax_nopriv_toggle_price_display', array($this, 'ajax_toggle_price_display'));
		add_action('wp_ajax_update_bitcoin_prices', array($this, 'ajax_price_update'));
		add_action('wp_ajax_nopriv_update_bitcoin_prices', array($this, 'ajax_price_update'));
		add_action('update_option_wc_bitcoin_price_display_discount', array($this, 'clear_cache_on_discount_change'), 10, 3);


		if (!isset($_SESSION['price_display'])) {
			$_SESSION['price_display'] = 'bitcoin';
		}

		$this->load_settings();
		
		// Initialize new options
		$this->range_option = get_option('wc_bitcoin_price_display_range_option', 'both');
		$this->rounding_thousand = get_option('wc_bitcoin_price_display_rounding_thousand', '0');
		$this->thousand_suffix = get_option('wc_bitcoin_price_display_thousand_suffix', 'K');
		
		// Initialize cache arrays
		$this->formatted_sats_cache = [];
		$this->variable_price_cache = [];
		$this->cart_price_cache = [];
		$this->subtotal_cache = [];
		$this->total_cache = [];
		$this->shipping_total_cache = [];
		$this->price_including_tax_cache = [];
		$this->cart_tax_total_cache = [];
		$this->order_tax_totals_cache = [];
		$this->cart_shipping_total_cache = [];
		$this->order_shipping_total_cache = [];
		$this->shipping_method_cache = [];
		$this->shipping_rate_cost_cache = [];
		$this->mini_cart_price_cache = [];
		$this->mini_cart_item_cache = [];
		$this->mini_cart_fragments_cache = [];
		
		// Add the new version detection notices for admins
		if (is_admin()) {
			add_action('admin_init', array($this, 'init_admin_notices'));
		}
		
		// Version check when saving settings
		add_action('update_option_wc_bitcoin_price_display_btcpay_server', array($this, 'clear_all_caches'));
		add_action('update_option_wc_bitcoin_price_display_store_id', array($this, 'clear_all_caches'));
		add_action('update_option_wc_bitcoin_price_display_api_key', array($this, 'clear_all_caches'));
	
	}
	
	public function remove_admin_price_filters() {
		if ($this->is_admin_product_page()) {
			remove_filter('woocommerce_get_price_html', array($this, 'add_bitcoin_price'), 10);
			remove_filter('woocommerce_price_format', array($this, 'custom_price_format'), 10);
		}
	}
	
	public function remove_bitcoin_price_admin() {
		remove_filter('woocommerce_get_price_html', array($this, 'add_bitcoin_price'), 10);
	}
	
    public function init_shop_currency() {
        $this->shop_currency = get_woocommerce_currency();
    }

    public function add_settings_page() {
        add_options_page('Bitcoin Price Display Settings', 'Bitcoin Price Display', 'manage_options', 'wc-bitcoin-price-display', array($this, 'settings_page'));
    }

    public function register_settings() {
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_btcpay_server');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_store_id');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_api_key');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_mode');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_rounding');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_both_prices_option');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_sats_prefix', 'stripslashes');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_sats_suffix', 'stripslashes');
		register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_fa_icon');
		register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_range_option');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_rounding_thousand');
        register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_thousand_suffix');
		register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_fa_icon_color');
		register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_fa_icon_animation');
		register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_discount', 'floatval');
		register_setting('wc_bitcoin_price_display_settings', 'wc_bitcoin_price_display_discount', array(
			'type' => 'number',
			'sanitize_callback' => array($this, 'sanitize_discount'),
		));
	}
	
		/**
	 * Detect the version of BTCPay Server
	 * 
	 * @return string|bool 'v1', 'v2' or false on failure
	 */
	public function detect_btcpay_version() {
		// Check for cached version
		$version = get_transient('wc_bitcoin_price_display_btcpay_version');
		if ($version !== false) {
			return $version;
		}
		
		// Try to get server info
		$url = "{$this->btcpay_server}/api/v1/server/info";
		
		$args = array(
			'headers' => array(
				'Authorization' => 'Token ' . $this->api_key,
			)
		);

		$response = wp_remote_get($url, $args);
		
		if (is_wp_error($response)) {
			error_log("Error detecting BTCPay version: " . $response->get_error_message());
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		
		// Check version from server info
		if (isset($data['version'])) {
			$version_number = $data['version'];
			$major_version = intval(explode('.', $version_number)[0]);
			
			$detected_version = ($major_version >= 2) ? 'v2' : 'v1';
			
			// Cache the result for 24 hours
			set_transient('wc_bitcoin_price_display_btcpay_version', $detected_version, 86400);
			
			return $detected_version;
		}
		
		// Default to v1 if detection fails
		return 'v1';
	}

	/**
	 * Check if the connected BTCPay Server is version 2.0 or higher
	 * 
	 * @return bool
	 */
	public function is_btcpay_v2() {
		return $this->detect_btcpay_version() === 'v2';
	}

	/**
	 * Clear all cached data including version detection
	 */
	public function clear_all_caches() {
		$this->clear_rate_cache();
		delete_transient('wc_bitcoin_price_display_btcpay_version');
	}

	/**
	 * Display admin notices for BTCPay Server 2.0 compatibility
	 */
	public function display_version_notice() {
		if (!current_user_can('manage_options')) {
			return;
		}
		
		// Check if we've already shown the notice
		if (get_option('wc_bitcoin_price_display_v2_notice_dismissed')) {
			return;
		}
		
		// Only proceed if BTCPay credentials are set
		if (empty($this->btcpay_server) || empty($this->store_id) || empty($this->api_key)) {
			return;
		}
		
		// Check if we're using v2
		if ($this->is_btcpay_v2()) {
			?>
			<div class="notice notice-info is-dismissible" id="btcpay-v2-notice">
				<p><strong>BTCPay Server 2.0 Detected:</strong> Your Bitcoin Price Display plugin has detected BTCPay Server 2.0 and is using the updated API format.</p>
				<p>If you experience any issues with price display, please check your API permissions in BTCPay Server.</p>
				<p>For BTCPay Server 2.0, your API key needs the <code>btcpay.store.canviewstoresettings</code> permission.</p>
				<button type="button" class="notice-dismiss-permanent" data-notice="btcpay-v2-notice">
					<span class="screen-reader-text">Dismiss this notice.</span>
				</button>
			</div>
			<script>
				jQuery(document).ready(function($) {
					$('.notice-dismiss-permanent').on('click', function() {
						var noticeId = $(this).data('notice');
						$('#' + noticeId).fadeOut();
						
						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'dismiss_btcpay_v2_notice',
								nonce: '<?php echo wp_create_nonce('dismiss_btcpay_v2_notice'); ?>'
							}
						});
					});
				});
			</script>
			<?php
		}
	}

	/**
	 * Ajax handler to dismiss the BTCPay v2 notice
	 */
	public function ajax_dismiss_v2_notice() {
		check_ajax_referer('dismiss_btcpay_v2_notice', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die('Unauthorized');
		}
		
		update_option('wc_bitcoin_price_display_v2_notice_dismissed', 1);
		wp_die();
	}

	/**
	 * Initialize admin notices
	 */
	public function init_admin_notices() {
		add_action('admin_notices', array($this, 'display_version_notice'));
		add_action('wp_ajax_dismiss_btcpay_v2_notice', array($this, 'ajax_dismiss_v2_notice'));
	}

	public function settings_page() {
		if (isset($_POST['submit'])) {
			check_admin_referer('wc_bitcoin_price_display_settings-options');
			
			$btcpay_server = isset($_POST['wc_bitcoin_price_display_btcpay_server']) ? sanitize_url($_POST['wc_bitcoin_price_display_btcpay_server']) : '';
			$store_id = isset($_POST['wc_bitcoin_price_display_store_id']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_store_id']) : '';
			$api_key = isset($_POST['wc_bitcoin_price_display_api_key']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_api_key']) : '';
			$display_mode = isset($_POST['wc_bitcoin_price_display_mode']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_mode']) : 'toggle';
			$rounding = isset($_POST['wc_bitcoin_price_display_rounding']) ? intval($_POST['wc_bitcoin_price_display_rounding']) : 1;
			$both_prices_option = isset($_POST['wc_bitcoin_price_display_both_prices_option']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_both_prices_option']) : 'after_inline';
			$sats_prefix = isset($_POST['wc_bitcoin_price_display_sats_prefix']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_sats_prefix']) : '~';
			$sats_suffix = isset($_POST['wc_bitcoin_price_display_sats_suffix']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_sats_suffix']) : 'Sats';
			$range_option = isset($_POST['wc_bitcoin_price_display_range_option']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_range_option']) : 'both';
			$rounding_thousand = isset($_POST['wc_bitcoin_price_display_rounding_thousand']) ? '1' : '0';
			$thousand_suffix = isset($_POST['wc_bitcoin_price_display_thousand_suffix']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_thousand_suffix']) : 'K';
			$fa_icon = isset($_POST['wc_bitcoin_price_display_fa_icon']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_fa_icon']) : '';
			$fa_icon_color = isset($_POST['wc_bitcoin_price_display_fa_icon_color']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_fa_icon_color']) : '';
			$fa_icon_animation = isset($_POST['wc_bitcoin_price_display_fa_icon_animation']) ? sanitize_text_field($_POST['wc_bitcoin_price_display_fa_icon_animation']) : '';
			$discount = get_option('wc_bitcoin_price_display_discount', 0);
			$discount = isset($_POST['wc_bitcoin_price_display_discount']) ? $this->sanitize_discount($_POST['wc_bitcoin_price_display_discount']) : 0;
			
			update_option('wc_bitcoin_price_display_btcpay_server', $btcpay_server);
			update_option('wc_bitcoin_price_display_store_id', $store_id);
			update_option('wc_bitcoin_price_display_api_key', $api_key);
			update_option('wc_bitcoin_price_display_mode', $display_mode);
			update_option('wc_bitcoin_price_display_rounding', $rounding);
			update_option('wc_bitcoin_price_display_both_prices_option', $both_prices_option);
			update_option('wc_bitcoin_price_display_sats_prefix', $sats_prefix);
			update_option('wc_bitcoin_price_display_sats_suffix', $sats_suffix);
			update_option('wc_bitcoin_price_display_range_option', $range_option);
			update_option('wc_bitcoin_price_display_rounding_thousand', $rounding_thousand);
			update_option('wc_bitcoin_price_display_thousand_suffix', $thousand_suffix);
			update_option('wc_bitcoin_price_display_fa_icon', $fa_icon);
			update_option('wc_bitcoin_price_display_fa_icon_color', $fa_icon_color);
			update_option('wc_bitcoin_price_display_fa_icon_animation', $fa_icon_animation);
			update_option('wc_bitcoin_price_display_discount', $discount);
			
			$this->clear_rate_cache();
			
			add_settings_error('wc_bitcoin_price_display_messages', 'wc_bitcoin_price_display_message', __('Settings Saved', 'wc-bitcoin-price-display'), 'updated');
		}
		
		// Handle cache clearing
		if (isset($_POST['action']) && $_POST['action'] === 'clear_btcpay_cache') {
			check_admin_referer('clear_btcpay_cache', 'clear_cache_nonce');
			$this->clear_all_caches();
			add_settings_error('wc_bitcoin_price_display_messages', 'cache_cleared', __('Cache cleared successfully', 'wc-bitcoin-price-display'), 'updated');
		}
		
		settings_errors('wc_bitcoin_price_display_messages');
		
		$btcpay_server = get_option('wc_bitcoin_price_display_btcpay_server', '');
		$store_id = get_option('wc_bitcoin_price_display_store_id', '');
		$api_key = get_option('wc_bitcoin_price_display_api_key', '');
		$display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
		$rounding = get_option('wc_bitcoin_price_display_rounding', 1);
		$both_prices_option = get_option('wc_bitcoin_price_display_both_prices_option', 'after_inline');
		$sats_prefix = get_option('wc_bitcoin_price_display_sats_prefix', '~');
		$sats_suffix = get_option('wc_bitcoin_price_display_sats_suffix', 'Sats');
		$range_option = get_option('wc_bitcoin_price_display_range_option', 'both');
		$rounding_thousand = get_option('wc_bitcoin_price_display_rounding_thousand', '0');
		$thousand_suffix = get_option('wc_bitcoin_price_display_thousand_suffix', 'K');
		$fa_icon = get_option('wc_bitcoin_price_display_fa_icon', 'fa-bitcoin');
		$fa_icon_color = get_option('wc_bitcoin_price_display_fa_icon_color', '');
		$fa_icon_animation = get_option('wc_bitcoin_price_display_fa_icon_animation', '');
		$discount = get_option('wc_bitcoin_price_display_discount', 0);
		
		// Get BTCPay version
		$btcpay_version = $this->detect_btcpay_version();
		$version_text = ($btcpay_version === 'v2') ? 'BTCPay Server 2.0+' : 'BTCPay Server 1.x';
		$version_class = ($btcpay_version === 'v2') ? 'updated' : 'notice';
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			
			<?php if ($btcpay_server && $store_id && $api_key): ?>
			<div class="<?php echo esc_attr($version_class); ?> notice inline">
				<p><strong>Detected: </strong><?php echo esc_html($version_text); ?></p>
			</div>
			<?php endif; ?>
			
			<form action="options-general.php?page=wc-bitcoin-price-display" method="post">
				<?php settings_fields('wc_bitcoin_price_display_settings'); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">BTCPay Server Address</th>
						<td>
							<input type="text" name="wc_bitcoin_price_display_btcpay_server" value="<?php echo esc_attr($btcpay_server); ?>" class="regular-text" />
							<p class="description">Enter your BTCPay Server URL (including https:// but without a trailing slash).</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Store ID</th>
						<td>
							<input type="text" name="wc_bitcoin_price_display_store_id" value="<?php echo esc_attr($store_id); ?>" class="regular-text" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">API Key</th>
						<td>
							<input type="password" name="wc_bitcoin_price_display_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Display Mode</th>
						<td>
							<select name="wc_bitcoin_price_display_mode">
								<option value="toggle" <?php selected($display_mode, 'toggle'); ?>>Toggle between original and Bitcoin</option>
								<option value="bitcoin_only" <?php selected($display_mode, 'bitcoin_only'); ?>>Bitcoin price only</option>
								<option value="both_prices" <?php selected($display_mode, 'both_prices'); ?>>Both Prices</option>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Rounding</th>
						<td>
							<select name="wc_bitcoin_price_display_rounding">
								<option value="1" <?php selected($rounding, 1); ?>>1 Satoshi</option>
								<option value="10" <?php selected($rounding, 10); ?>>10 Satoshis</option>
								<option value="100" <?php selected($rounding, 100); ?>>100 Satoshis</option>
								<option value="1000" <?php selected($rounding, 1000); ?>>1,000 Satoshis</option>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Both Prices Display Option</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span>Both Prices Display Option</span></legend>
								<p><strong>Choose how to display both prices:</strong></p>
								<label>
									<input type="radio" name="wc_bitcoin_price_display_both_prices_option" value="after_inline" <?php checked($both_prices_option, 'after_inline'); ?>>
									$10.00 / 30,000 sats
								</label><br>
								<label>
									<input type="radio" name="wc_bitcoin_price_display_both_prices_option" value="before_inline" <?php checked($both_prices_option, 'before_inline'); ?>>
									30,000 sats / $10.00
								</label><br>
								<label style="display: flex; align-items: center;">
									<input type="radio" name="wc_bitcoin_price_display_both_prices_option" value="below" <?php checked($both_prices_option, 'below'); ?>>
									<span style="display: inline-block; text-align: center; margin-left: 5px;">
										$10.00<br>30,000 sats
									</span>
								</label><br>
								<label style="display: flex; align-items: center;">
									<input type="radio" name="wc_bitcoin_price_display_both_prices_option" value="above" <?php checked($both_prices_option, 'above'); ?>>
									<span style="display: inline-block; text-align: center; margin-left: 5px;">
										30,000 sats<br>$10.00
									</span>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Price Range Display</th>
						<td>
							<select name="wc_bitcoin_price_display_range_option">
								<option value="both" <?php selected($range_option, 'both'); ?>>Show both prices</option>
								<option value="lowest" <?php selected($range_option, 'lowest'); ?>>Show lowest price with +</option>
							</select>
							<p class="description">Choose how to display price ranges for variable products.</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Round to Nearest Thousand</th>
						<td>
							<input type="checkbox" name="wc_bitcoin_price_display_rounding_thousand" value="1" <?php checked($rounding_thousand, '1'); ?> />
							<p class="description">Round to the nearest thousand and remove trailing zeros.</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Thousand Suffix</th>
						<td>
							<input type="text" name="wc_bitcoin_price_display_thousand_suffix" value="<?php echo esc_attr($thousand_suffix); ?>" />
							<p class="description">Suffix to denote thousands (e.g., "K" for 50K).</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Sats Amount Prefix</th>
						<td>
							<input type="text" name="wc_bitcoin_price_display_sats_prefix" id="sats_prefix" value="<?php echo esc_attr(stripslashes($sats_prefix)); ?>" class="regular-text" />
							<p class="description">Enter the text or character to display before the sats amount (e.g., "~" or "approximately").</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Sats Amount Suffix</th>
						<td>
							<input type="text" name="wc_bitcoin_price_display_sats_suffix" id="sats_suffix" value="<?php echo esc_attr(stripslashes($sats_suffix)); ?>" class="regular-text" />
							<p class="description">Enter the text to display after the sats amount (e.g., "Sats" or "satoshis").</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Font Awesome Icon</th>
						<td>
							<input type="text" name="wc_bitcoin_price_display_fa_icon" id="fa_icon" value="<?php echo esc_attr($fa_icon); ?>" class="regular-text" />
							<p class="description">
								Enter the full Font Awesome icon class. For the circular Bitcoin symbol, use 'fa-brands fa-bitcoin'. 
								Other options include 'fa-solid fa-bitcoin-sign' or 'fa-brand fa-btc' for the B symbol. 
								Make sure you include both the style prefix (e.g., 'fa-brands' or 'fa-solid') and the icon name.
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Icon Color</th>
						<td>
							<input type="text" name="wc_bitcoin_price_display_fa_icon_color" id="fa_icon_color" value="<?php echo esc_attr($fa_icon_color); ?>" class="regular-text" />
							<p class="description">Enter a color for the icon (e.g., '#ff8000' or 'orange'). Leave blank for default color.</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Icon Animation</th>
						<td>
							<select name="wc_bitcoin_price_display_fa_icon_animation" id="fa_icon_animation">
								<option value="" <?php selected($fa_icon_animation, ''); ?>>None</option>
								<option value="fa-spin" <?php selected($fa_icon_animation, 'fa-spin'); ?>>Spin</option>
								<option value="fa-pulse" <?php selected($fa_icon_animation, 'fa-pulse'); ?>>Pulse</option>
								<option value="fa-flip" <?php selected($fa_icon_animation, 'fa-flip'); ?>>Flip</option>
								<option value="fa-beat" <?php selected($fa_icon_animation, 'fa-beat'); ?>>Beat</option>
								<option value="fa-bounce" <?php selected($fa_icon_animation, 'fa-bounce'); ?>>Bounce</option>
								<option value="fa-shake" <?php selected($fa_icon_animation, 'fa-shake'); ?>>Shake</option>
							</select>
							<p class="description">Select an animation for the icon.</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Bitcoin Discount (%)</th>
						<td>
							<input type="number" name="wc_bitcoin_price_display_discount" value="<?php echo esc_attr($discount); ?>" class="small-text" step="0.01" min="0" max="100" />
							<p class="description">Enter the percentage discount for Bitcoin payments (0-100). Leave empty for no discount.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			
			<!-- Troubleshooting section -->
			<div class="card" style="max-width: 100%;">
				<h2>Troubleshooting</h2>
				<p>If you're using BTCPay Server 2.0 and experiencing issues:</p>
				<ol>
					<li>Ensure your API key has the <code>btcpay.store.canviewstoresettings</code> permission</li>
					<li>Check that your Server URL and Store ID are correct</li>
					<li>Try clicking "Clear Cache" below to reset the plugin's stored data</li>
				</ol>
				<form method="post" action="">
					<?php wp_nonce_field('clear_btcpay_cache', 'clear_cache_nonce'); ?>
					<input type="hidden" name="action" value="clear_btcpay_cache">
					<input type="submit" class="button button-secondary" value="Clear Cache">
				</form>
			</div>
		</div>
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
				'prefix' => get_option('wc_bitcoin_price_display_sats_prefix', '~'),
				'suffix' => get_option('wc_bitcoin_price_display_sats_suffix', 'Sats'),
				'faIcon' => get_option('wc_bitcoin_price_display_fa_icon', 'fa-brands fa-bitcoin'),
				'faIconColor' => get_option('wc_bitcoin_price_display_fa_icon_color', ''),
				'faIconAnimation' => get_option('wc_bitcoin_price_display_fa_icon_animation', ''),
				'bothPricesOption' => get_option('wc_bitcoin_price_display_both_prices_option', 'after_inline'),
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('bitcoin_price_toggle_nonce')
			));
			
			wp_enqueue_style('bitcoin-price-display', plugin_dir_url(__FILE__) . 'css/bitcoin-price-display.css');
			
			// Enqueue Font Awesome if not already enqueued by the theme
			if (!wp_style_is('font-awesome', 'enqueued')) {
				wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
			}
		}
	}
	
	public function sanitize_discount($input) {
		$value = floatval($input);
		return min(max($value, 0), 100); // Ensures the value is between 0 and 100
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

	private $formatted_sats_cache = [];

	public function format_sats($sats) {
		// Check cache first
		if (isset($this->formatted_sats_cache[$sats])) {
			return $this->formatted_sats_cache[$sats];
		}
		
		// Handle zero value
		if ($sats === 0) {
			$formatted_output = '0 ' . esc_html(get_option('wc_bitcoin_price_display_sats_suffix', 'Sats'));
			$this->formatted_sats_cache[$sats] = $formatted_output;
			return $formatted_output;
		}
		
		$prefix = get_option('wc_bitcoin_price_display_sats_prefix', '~');
		$suffix = get_option('wc_bitcoin_price_display_sats_suffix', 'Sats');
		$fa_icon = get_option('wc_bitcoin_price_display_fa_icon', 'fa-bitcoin');
		$rounding_thousand = get_option('wc_bitcoin_price_display_rounding_thousand', '0') === '1';
		$thousand_suffix = get_option('wc_bitcoin_price_display_thousand_suffix', 'K');
		
		if ($rounding_thousand && $sats >= 1000) {
			$sats = round($sats / 1000) * 1000; // Round to nearest thousand
			$formatted_sats = number_format($sats / 1000, 0, '.', ',');
			$formatted_sats .= $thousand_suffix; // Add the thousand suffix
		} else {
			$formatted_sats = number_format($sats, 0, '.', ',');
		}
		
		$output = '<span class="wc-bitcoin-price" data-sats="' . esc_attr($sats) . '" data-suffix="' . esc_attr($suffix) . '">';
		if (!empty($fa_icon)) {
			$output .= '<i class="fa ' . esc_attr($fa_icon) . '"></i> ';
		}
		$output .= '<span class="bitcoin-amount">' . esc_html($prefix) . ' ' . esc_html($formatted_sats) . ' ' . esc_html($suffix) . '</span>';
		$output .= '</span>';
		
		// Cache and return the formatted output
		$this->formatted_sats_cache[$sats] = $output;
		return $output;
	}	
	
	public function get_bitcoin_price() {
		$cached_rate = get_transient($this->cache_key);
		if ($cached_rate !== false) {
			$discounted_rate = $this->apply_discount($cached_rate);
			error_log("Cached BTC Price: " . $cached_rate . ", Discounted: " . $discounted_rate);
			return $discounted_rate;
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
			error_log("Error fetching BTC price: " . $response->get_error_message());
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (isset($data[0]['rate'])) {
			$rate = floatval($data[0]['rate']);
			set_transient($this->cache_key, $rate, $this->cache_expiration);
			$discounted_rate = $this->apply_discount($rate);
			error_log("Fetched BTC Price: " . $rate . ", Discounted: " . $discounted_rate);
			return $discounted_rate;
		}

		error_log("Failed to fetch BTC price");
		return false;
	}

	private function apply_discount($rate) {
		$discount = get_option('wc_bitcoin_price_display_discount', 0);
		if ($discount > 0 && $discount <= 100) {
			// Increase the rate to reduce the sats amount
			return $rate / (1 - $discount / 100);
		}
		return $rate;
	}	
	
	public function convert_to_sats($price) {
		$btc_price = $this->get_bitcoin_price();
		if ($btc_price === false) {
			return 'N/A';
		}
		$btc_amount = $price / $btc_price;
		$sats = $btc_amount * 100000000; // Convert to satoshis
		$rounding = get_option('wc_bitcoin_price_display_rounding', 1);
		return round($sats, -1 * strlen($rounding) + 1); // Round to nearest chosen value
	}
	
	public function clear_cache_on_discount_change($old_value, $new_value, $option) {
		if ($option === 'wc_bitcoin_price_display_discount' && $old_value !== $new_value) {
			$this->clear_rate_cache();
		}
	}
	
    public function get_current_price_display() {
        $display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
        if ($display_mode === 'bitcoin_only') {
            return 'bitcoin';
        } elseif ($display_mode === 'both_prices') {
            return 'both';
        } else {
            return isset($_SESSION['price_display']) ? $_SESSION['price_display'] : 'bitcoin';
        }
    }
	
	private function is_admin_product_page() {
		return is_admin() && function_exists('get_current_screen') && get_current_screen() && in_array(get_current_screen()->id, ['product', 'edit-product']);
	}

	public function format_price($original_price, $bitcoin_price) {
		$display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
		$side_by_side_option = get_option('wc_bitcoin_price_display_both_prices_option', 'after_inline');
		
		if (!empty($original_price)) {
			$original_formatted = is_numeric($original_price) ? wc_price($original_price, array('currency' => $this->shop_currency)) : $original_price;
		} else {
			$original_formatted = '';
		}

		switch ($display_mode) {
			case 'bitcoin_only':
				return '<span class="price-wrapper"><span class="bitcoin-price">' . $bitcoin_price . '</span></span>';
			case 'both_prices':
				if (empty($original_formatted)) {
					return '<span class="price-wrapper"><span class="bitcoin-price">' . $bitcoin_price . '</span></span>';
				}
				switch ($side_by_side_option) {
					case 'before_inline':
						return '<span class="price-wrapper">' . $bitcoin_price . ' / ' . $original_formatted . '</span>';
					case 'after_inline':
						return '<span class="price-wrapper">' . $original_formatted . ' / ' . $bitcoin_price . '</span>';
					case 'below':
						return '<span class="price-wrapper">' . $original_formatted . '<br>' . $bitcoin_price . '</span>';
					case 'above':
						return '<span class="price-wrapper">' . $bitcoin_price . '<br>' . $original_formatted . '</span>';
					default:
						return '<span class="price-wrapper">' . $original_formatted . ' / ' . $bitcoin_price . '</span>';
				}
			case 'toggle':
			default:
				return '<span class="price-wrapper">' .
					   '<span class="original-price">' . $original_formatted . '</span>' .
					   '<span class="bitcoin-price">' . $bitcoin_price . '</span>' .
					   '</span>';
		}
	}
	
	private $shipping_method_cache = [];

	public function add_bitcoin_shipping($method_label, $method) {
		if ($method instanceof WC_Shipping_Rate) {
			$cache_key = md5($method->get_id() . $method->get_cost() . get_option('wc_bitcoin_price_display_mode', 'toggle'));
			
			if (isset($this->shipping_method_cache[$cache_key])) {
				return $this->shipping_method_cache[$cache_key];
			}
			
			$cost = $method->get_cost();
			if ($cost > 0) {
				$sats = $this->convert_to_sats($cost);
				$bitcoin_price = $this->format_sats($sats);
				$display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
				
				if ($display_mode === 'bitcoin_only') {
					$formatted_label = $method->get_label() . ': ' . $bitcoin_price;
				} else {
					$formatted_price = $this->format_price($cost, $bitcoin_price);
					$formatted_label = $method->get_label() . ': ' . $formatted_price;
				}
				
				$this->shipping_method_cache[$cache_key] = $formatted_label;
				return $formatted_label;
			}
		}
		return $method_label;
	}
	
	private $shipping_rate_cost_cache = [];

	public function modify_shipping_rate_cost($cost, $shipping_rate) {
		if ($shipping_rate instanceof WC_Shipping_Rate) {
			$cache_key = md5($shipping_rate->get_id() . $cost);
			
			if (isset($this->shipping_rate_cost_cache[$cache_key])) {
				$shipping_rate->add_meta_data('bitcoin_price', $this->shipping_rate_cost_cache[$cache_key]);
				return $cost;
			}
			
			$sats = $this->convert_to_sats($cost);
			$bitcoin_price = $this->format_sats($sats);
			$shipping_rate->add_meta_data('bitcoin_price', $bitcoin_price);
			
			$this->shipping_rate_cost_cache[$cache_key] = $bitcoin_price;
		}
		return $cost;
	}
	
	private $shipping_total_cache = [];

	public function add_bitcoin_shipping_total($total) {
		$cart = WC()->cart;
		$cache_key = md5($cart->get_cart_hash() . $cart->get_shipping_total() . $cart->get_shipping_tax());
		
		if (isset($this->shipping_total_cache[$cache_key])) {
			return $this->shipping_total_cache[$cache_key];
		}
		
		$shipping_total = $cart->get_shipping_total();
		$taxes = $cart->get_shipping_tax();
		$total_with_tax = $shipping_total + $taxes;
		
		$sats = $this->convert_to_sats($total_with_tax);
		$bitcoin_price = $this->format_sats($sats);
		$formatted_total = $this->format_price($total_with_tax, $bitcoin_price);
		
		$this->shipping_total_cache[$cache_key] = $formatted_total;
		
		return $formatted_total;
	}
	
	private $shipping_methods_cache = [];

	public function add_bitcoin_to_available_shipping_methods($methods) {
		$cache_key = md5(serialize($methods) . get_option('wc_bitcoin_price_display_mode', 'toggle'));
		
		if (isset($this->shipping_methods_cache[$cache_key])) {
			return $this->shipping_methods_cache[$cache_key];
		}
		
		$display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
		foreach ($methods as $method) {
			if ($method->cost > 0) {
				$sats = $this->convert_to_sats($method->cost);
				$bitcoin_price = $this->format_sats($sats);
				if ($display_mode === 'bitcoin_only') {
					$method->label .= ' (' . strip_tags($bitcoin_price) . ')';
				} else {
					$formatted_price = $this->format_price($method->cost, $bitcoin_price);
					$method->label .= ' (' . strip_tags($formatted_price) . ')';
				}
			}
		}
		
		$this->shipping_methods_cache[$cache_key] = $methods;
		
		return $methods;
	}
	
	private $variable_price_cache = [];
	
	public function add_bitcoin_price($price_html, $product) {
		$product_id = $product->get_id();
		
		if (isset($this->variable_price_cache[$product_id])) {
			return $this->variable_price_cache[$product_id];
		}
		
		$display_mode = get_option('wc_bitcoin_price_display_mode', 'toggle');
		$range_option = get_option('wc_bitcoin_price_display_range_option', 'both');
		if ($product->is_type('variable')) {
			$prices = $product->get_variation_prices(true);
			$min_price = current($prices['price']);
			$max_price = end($prices['price']);
			$min_sats = $this->convert_to_sats($min_price);
			$max_sats = $this->convert_to_sats($max_price);
			$bitcoin_price = '';
			$original_price = '';
			if ($min_price === $max_price) {
				// If min and max prices are the same, show only one price
				$bitcoin_price = $this->format_sats($min_sats);
				$original_price = wc_price($min_price);
			} else {
				// If prices differ, show range based on settings
				if ($display_mode === 'bitcoin_only') {
					if ($range_option === 'both') {
						$bitcoin_price = $this->format_sats($min_sats) . ' - ' . $this->format_sats($max_sats);
					} else {
						$bitcoin_price = $this->format_sats($min_sats) . '+';
					}
				} else {
					$original_price = wc_price($min_price) . ' - ' . wc_price($max_price);
					if ($range_option === 'both') {
						$bitcoin_price = $this->format_sats($min_sats) . ' - ' . $this->format_sats($max_sats);
					} else {
						$bitcoin_price = $this->format_sats($min_sats) . '+';
					}
				}
			}
		} else {
			// For simple products
			$price = $product->get_price();
			$sats = $this->convert_to_sats($price);
			$bitcoin_price = $this->format_sats($sats);
			$original_price = wc_price($price);
		}
		
		$formatted_price = $this->format_price($original_price, $bitcoin_price);
		
		if ($product->is_type('variable')) {
			$this->variable_price_cache[$product_id] = $formatted_price;
		}

		return $formatted_price;
	}
	
	private $cart_price_cache = [];

	public function add_bitcoin_price_cart($price_html, $cart_item, $cart_item_key) {
		$cache_key = md5($cart_item_key . $cart_item['line_subtotal']);
		
		if (isset($this->cart_price_cache[$cache_key])) {
			return $this->cart_price_cache[$cache_key];
		}
		
		$price = $cart_item['line_subtotal'];
		$sats = $this->convert_to_sats($price);
		$bitcoin_price = $this->format_sats($sats);
		$formatted_price = $this->format_price($price, $bitcoin_price);
		
		$this->cart_price_cache[$cache_key] = $formatted_price;
		
		return $formatted_price;
	}

	private $subtotal_cache = [];

	public function add_bitcoin_subtotal($subtotal, $compound, $cart) {
		$cache_key = md5($cart->get_cart_hash() . $compound . $cart->get_subtotal());
		
		if (isset($this->subtotal_cache[$cache_key])) {
			return $this->subtotal_cache[$cache_key];
		}
		
		$current_currency_subtotal = $cart->get_subtotal();
		$sats = $this->convert_to_sats($current_currency_subtotal);
		$bitcoin_subtotal = $this->format_sats($sats);
		$formatted_subtotal = $this->format_price($current_currency_subtotal, $bitcoin_subtotal);
		
		$this->subtotal_cache[$cache_key] = $formatted_subtotal;
		
		return $formatted_subtotal;
	}
	
	private $tax_totals_cache = [];

	public function add_bitcoin_tax_totals($tax_totals) {
		$cart = WC()->cart;
		$cache_key = md5($cart->get_cart_hash() . serialize($tax_totals));
		
		if (isset($this->tax_totals_cache[$cache_key])) {
			return $this->tax_totals_cache[$cache_key];
		}
		
		foreach ($tax_totals as $code => $tax) {
			$original_amount = $tax->amount;
			$sats = $this->convert_to_sats($original_amount);
			$bitcoin_amount = $this->format_sats($sats);
			$tax->formatted_amount = $this->format_price($original_amount, $bitcoin_amount);
		}
		
		$this->tax_totals_cache[$cache_key] = $tax_totals;
		
		return $tax_totals;
	}

	private $price_including_tax_cache = [];

	public function add_bitcoin_to_price_including_tax($price_html, $product) {
		if (!$product || !is_a($product, 'WC_Product')) {
			return $price_html; // Return original price if product is invalid
		}

		$cache_key = md5($product->get_id() . '_' . $product->get_price() . '_' . get_option('wc_bitcoin_price_display_mode', 'toggle'));

		if (isset($this->price_including_tax_cache[$cache_key])) {
			return $this->price_including_tax_cache[$cache_key];
		}

		try {
			$price = wc_get_price_including_tax($product);
			if ($price === false || $price === '') {
				return $price_html; // Return original price if unable to get price
			}

			$sats = $this->convert_to_sats($price);
			$bitcoin_price = $this->format_sats($sats);
			$formatted_price = $this->format_price($price, $bitcoin_price);

			$this->price_including_tax_cache[$cache_key] = $formatted_price;

			return $formatted_price;
		} catch (Exception $e) {
			error_log('Error in add_bitcoin_to_price_including_tax: ' . $e->getMessage());
			return $price_html; // Return original price on error
		}
	}

	private $cart_tax_total_cache = [];

	public function add_bitcoin_cart_tax_total($tax_total) {
		$cart = WC()->cart;
		$cache_key = md5($cart->get_cart_hash() . $cart->get_taxes_total());
		
		if (isset($this->cart_tax_total_cache[$cache_key])) {
			return $this->cart_tax_total_cache[$cache_key];
		}
		
		$cart_tax = $cart->get_taxes_total();
		$sats = $this->convert_to_sats($cart_tax);
		$bitcoin_tax = $this->format_sats($sats);
		$formatted_tax_total = $this->format_price($cart_tax, $bitcoin_tax);
		
		$this->cart_tax_total_cache[$cache_key] = $formatted_tax_total;
		
		return $formatted_tax_total;
	}

private $order_tax_totals_cache = [];

	public function add_bitcoin_order_tax_totals($tax_totals_html, $order) {
		$cache_key = md5($order->get_id() . serialize($order->get_tax_totals()));
		
		if (isset($this->order_tax_totals_cache[$cache_key])) {
			return $this->order_tax_totals_cache[$cache_key];
		}
		
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
		
		$this->order_tax_totals_cache[$cache_key] = $tax_totals_html;
		
		return $tax_totals_html;
	}

	private $total_cache = [];

	public function add_bitcoin_total($total) {
		$cart = WC()->cart;
		$cache_key = md5($cart->get_cart_hash() . $cart->get_total(''));
		
		if (isset($this->total_cache[$cache_key])) {
			return $this->total_cache[$cache_key];
		}
		
		// Get the total in the shop's current currency
		$current_currency_total = $cart->get_total('');
		$sats = $this->convert_to_sats($current_currency_total);
		$bitcoin_total = $this->format_sats($sats);
		$formatted_total = $this->format_price($current_currency_total, $bitcoin_total);
		
		$this->total_cache[$cache_key] = $formatted_total;
		
		return $formatted_total;
	}
	
	private $mini_cart_price_cache = [];

	public function update_mini_cart_price($price_html, $product) {
		if (is_a($product, 'WC_Product')) {
			$cache_key = md5($product->get_id() . $product->get_price());
			
			if (isset($this->mini_cart_price_cache[$cache_key])) {
				return $this->mini_cart_price_cache[$cache_key];
			}
			
			$price = $product->get_price();
			$sats = $this->convert_to_sats($price);
			$bitcoin_price = $this->format_sats($sats);
			$formatted_price = $this->format_price($price, $bitcoin_price);
			
			$this->mini_cart_price_cache[$cache_key] = $formatted_price;
			
			return $formatted_price;
		}
		return $price_html;
	}

	private $mini_cart_item_cache = [];

	public function filter_mini_cart_item_name($product_title, $cart_item, $cart_item_key) {
		$cache_key = md5($cart_item_key . $cart_item['data']->get_id() . $cart_item['quantity'] . $cart_item['data']->get_price());
		
		if (isset($this->mini_cart_item_cache[$cache_key])) {
			return $this->mini_cart_item_cache[$cache_key];
		}
		
		$product = $cart_item['data'];
		$quantity = $cart_item['quantity'];
		$price = $product->get_price() * $quantity;
		$sats = $this->convert_to_sats($price);
		$bitcoin_price = $this->format_sats($sats);
		
		$formatted_price = $this->format_price($price, $bitcoin_price);
		
		$formatted_item = $product_title . ' <span class="quantity">× ' . $quantity . '</span> <span class="amount">' . $formatted_price . '</span>';
		
		$this->mini_cart_item_cache[$cache_key] = $formatted_item;
		
		return $formatted_item;
	}
	
	public function clear_rate_cache() {
		delete_transient($this->cache_key);
		$this->formatted_sats_cache = [];
		$this->variable_price_cache = [];
		$this->cart_price_cache = [];
		$this->subtotal_cache = [];
		$this->total_cache = [];
		$this->shipping_total_cache = [];
		$this->price_including_tax_cache = [];
		$this->cart_tax_total_cache = [];
		$this->order_tax_totals_cache = [];
		$this->tax_totals_cache = [];
		$this->cart_shipping_total_cache = [];
		$this->order_shipping_total_cache = [];
		$this->shipping_method_cache = [];
		$this->shipping_rate_cost_cache = [];
		$this->mini_cart_price_cache = [];
		$this->mini_cart_item_cache = [];
		$this->mini_cart_fragments_cache = [];
		error_log("Cleared Bitcoin rate cache and all related caches due to currency change");
	}

	private $mini_cart_fragments_cache = [];

	public function update_mini_cart_fragments($fragments) {
		$cart = WC()->cart;
		$cache_key = md5($cart->get_cart_hash() . $cart->get_cart_subtotal() . $cart->get_subtotal());
		
		if (isset($this->mini_cart_fragments_cache[$cache_key])) {
			return $this->mini_cart_fragments_cache[$cache_key];
		}
		
		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();
		$fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';

		// Update cart subtotal
		$cart_subtotal = $cart->get_cart_subtotal();
		$sats_subtotal = $this->convert_to_sats($cart->get_subtotal());
		$formatted_sats_subtotal = $this->format_sats($sats_subtotal);
		$fragments['p.woocommerce-mini-cart__total'] = '<p class="woocommerce-mini-cart__total total">' . 
			sprintf(_x('Subtotal: %s', 'Cart subtotal', 'woocommerce'), $cart_subtotal) . 
			' <span class="wc-bitcoin-price">(' . $formatted_sats_subtotal . ')</span></p>';

		$this->mini_cart_fragments_cache[$cache_key] = $fragments;
		
		return $fragments;
	}

	private $cart_shipping_total_cache = [];

	public function add_bitcoin_cart_shipping_total($total) {
		if (is_cart() || is_checkout()) {
			$cart = WC()->cart;
			$cache_key = md5($cart->get_cart_hash() . $cart->get_shipping_total());
			
			if (isset($this->cart_shipping_total_cache[$cache_key])) {
				return $this->cart_shipping_total_cache[$cache_key];
			}
			
			$price = $cart->get_shipping_total();
			$sats = $this->convert_to_sats($price);
			$bitcoin_price = $this->format_sats($sats);
			$formatted_total = $this->format_price($price, $bitcoin_price);
			
			$this->cart_shipping_total_cache[$cache_key] = $formatted_total;
			
			return $formatted_total;
		}
		return $total;
	}

	private $order_shipping_total_cache = [];

	public function add_bitcoin_order_shipping_total($total, $order) {
		$cache_key = md5($order->get_id() . $order->get_shipping_total());
		
		if (isset($this->order_shipping_total_cache[$cache_key])) {
			return $this->order_shipping_total_cache[$cache_key];
		}
		
		$price = $order->get_shipping_total();
		$sats = $this->convert_to_sats($price);
		$bitcoin_price = $this->format_sats($sats);
		$formatted_total = $this->format_price($price, $bitcoin_price);
		
		$this->order_shipping_total_cache[$cache_key] = $formatted_total;
		
		return $formatted_total;
	}
}

function initialize_wc_bitcoin_price_display() {
    global $wc_bitcoin_price_display;
    $wc_bitcoin_price_display = new WC_Bitcoin_Price_Display();
}
add_action('plugins_loaded', 'initialize_wc_bitcoin_price_display');
