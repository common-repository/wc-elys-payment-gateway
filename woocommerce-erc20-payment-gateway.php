<?php
/*
 * Plugin Name: Payment Gateway for ELYS on WooCommerce
 * Version: 0.0.6
 * Plugin URI: https://www.elyseos.com/home
 * Description: This Plugin will add ELYS Token Payment Gateway
 * Author: Elyseos
 * Author URI: https://www.elyseos.com/
 * Requires at least: 5.8.1
 * Tested up to: 5.8.1
 *
 * Text Domain: wc-elys-payment-gateway
 * Domain Path: /lang/
 */

if (!defined('ABSPATH')) {
	exit;
}
/**
 * 添加链接到插件的 Meta 信息区域
 */
add_filter('plugin_row_meta', 'inkerk_add_link_to_plugin_meta', 10, 4);

function inkerk_add_link_to_plugin_meta($links_array, $plugin_file_name, $plugin_data, $status) {
	/**
	 * 使用 if 判断当前操作的插件是否是我们自己的插件。
	 */
	if (strpos($plugin_file_name, basename(__FILE__))) {
		// 在数组最后加入对应的链接
		// 如果希望显示在前面，可以参考一下 array_unshift 函数。
	//	$links_array[] = '<a href="https://inkerk.github.io/blog/2018/11/01/woocommerce-erc20-payment-gateway-plugin/">FAQ</a>';
	}
	return $links_array;
}
/**
 * 添加插件名称设置
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'inkerk_erc20_add_settings_link');
function inkerk_erc20_add_settings_link($links) {
	$settings_link = '<a href="admin.php?page=wc-settings&tab=checkout">' . __('Settings') . '</a>';
	array_push($links, $settings_link);
	return $links;
}
/**
 * 加载 i18n 语言包
 */
add_action('init', 'inkerk_erc20_load_textdomain');
function inkerk_erc20_load_textdomain() {
	/**
	 * 这里的第一个参数为 __($str,'param') 中的 param ，即区分不同语言包域的参数
	 */
	load_plugin_textdomain('wc-elys-payment-gateway', false, basename(dirname(__FILE__)) . '/lang');
}

/**
 * 添加新的 Gateway
 */
add_filter('woocommerce_payment_gateways', 'inkerk_erc20_add_gateway_class');
function inkerk_erc20_add_gateway_class($gateways) {
	$gateways[] = 'WC_Inkerk_Erc20_Gateway';
	return $gateways;
}
/**
 * 监听插件的支付完成请求
 */
add_action('init', 'inkerk_thankyour_request');
function inkerk_thankyour_request() {
	/**
	 * 判定用户请求是否是特定路径。如果此处路径修改，需要对应修改 payments.js 中的代码
	 */
	if ($_SERVER["REQUEST_URI"] == '/hook/wc_erc20') {
		$data = $_POST;
		$order_id = $data['orderid'];
		$tx = $data['tx'];
		if (strlen($tx) != 66 || substr($tx,0,2) != '0x'){
			return ;
		}
		/**
		 * 获取到订单
		 */
		$order = wc_get_order($order_id);
		/**
		 * 标记订单支付完成
		 */
		$order->payment_complete();
		/**
		 * 添加订单备注，并表明 tx 的查看地址
		 */
		$order->add_order_note(__("Order payment completed", 'wc-elys-payment-gateway') . "Tx:<a target='_blank' href='http://ftmscan.com.io/tx/" . $tx . "'>" . $tx . "</a>");
		/**
		 * 需要退出，不然会显示页面内容。退出就显示空白，也开业在界面打印一段 JSON。
		 */
		exit();
	}

}
/*
 * 插件加载以及对应的 class
 */
add_action('plugins_loaded', 'inkerk_erc20_init_gateway_class');
function inkerk_erc20_init_gateway_class() {
	/**
	 * 定义 class
	 */
	class WC_Inkerk_Erc20_Gateway extends WC_Payment_Gateway {

		/**
		 * Class constructor, more about it in Step 3
		 */
		public function __construct() {
			/**
			 * 定义所需内容
			 * @var string
			 */
			$this->id = 'inkerk_erc20';
			/**
			 * 设置 - 付款 - 支付方式界面展示的支付方式名称
			 * @var [type]
			 */
			$this->method_title = __('Pay with ELYS', 'wc-elys-payment-gateway');
			/**
			 * 用户下单时显示的按钮的文字
			 */
			$this->order_button_text = __('Use Token Payment', 'wc-elys-payment-gateway');
			/**
			 * 设置 - 付款 - 支付方式界面展示的支付方式介绍
			 */
			$this->method_description = __('If you want to use this Payment Gateway, We suggest you read <a href="https://elys.money/docs/tools/woo-guide">our guide </a> before.', 'wc-elys-payment-gateway');

			$this->supports = array(
				'products',
			); // 仅支持购买

			/**
			 * 初始化设置及后台设置界面
			 */
			$this->init_settings();
			$this->init_form_fields();

			// 使用 foreach 将设置都赋值给对象，方便后续调用。
			foreach ($this->settings as $setting_key => $value) {
				$this->$setting_key = $value;
			}

			/**
			 * 各种 hook
			 */
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
			add_action('woocommerce_api_compete', array($this, 'webhook'));
			add_action('admin_notices', array($this, 'do_ssl_check'));
			add_action('woocommerce_thankyou', array($this, 'thankyou_page'));

		}

		/**
		 * 插件设置项目
		 */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'wc-elys-payment-gateway'),
					'label' => __('Enable ELYS Payment Gateway', 'wc-elys-payment-gateway'),
					'type' => 'checkbox',
					'default' => 'no',
				),
				'title' => array(
					'title' => __('Title', 'wc-elys-payment-gateway'),
					'type' => 'text',
					'description' => __('Title Will Show at Checkout Page', 'wc-elys-payment-gateway'),
					'default' => 'ELYS Payment Gateway',
					'desc_tip' => true,
				),
				'description' => array(
					'title' => __('Description', 'wc-elys-payment-gateway'),
					'type' => 'textarea',
					'description' => __('Description  Will Show at Checkout Page', 'wc-elys-payment-gateway'),
					'default' => __('Please make sure you have installed Metamask & enabled it on the Fantom Network. Have ELYS for your payment and FTM for gas costs. <a href="https://elys.money/docs/tools/woo-guide"><<How to Get ELYS>></a>', 'wc-elys-payment-gateway'),
				),
// 				'icon' => array(
// 					'title' => __('Payment icon', 'wc-elys-payment-gateway'),
// 					'type' => 'text',
// 					'default' => 'https://postimg.aliavv.com/newmbp/eb9ty.png',
// 					'description' => __('Image Height:25px', 'wc-elys-payment-gateway'),
// 				),
				'target_address' => array(
					'title' => __('Wallet Address', 'wc-elys-payment-gateway'),
					'type' => 'text',
					'description' => __('Token Will Transfer into this Wallet', 'wc-elys-payment-gateway'),
				),
// 				'abi_array' => array(
// 					'title' => __('Contract ABI', 'wc-elys-payment-gateway'),
// 					'type' => 'textarea',
// 					'description' => __('You Can get ABI From Etherscan.io', 'wc-elys-payment-gateway'),
// 				),
// 				'contract_address' => array(
// 					'title' => __('Contract Address', 'wc-elys-payment-gateway'),
// 					'type' => 'text',
// 				),

// 				'gas_notice' => array(
// 					'title' => __('Gas Notice', 'wc-elys-payment-gateway'),
// 					'type' => 'textarea',
// 					'default' => __('Set a High Gas Price to speed up your transaction.', 'wc-elys-payment-gateway'),
// 					'description' => __('Tell Customer set a high gas price to speed up transaction.', 'wc-elys-payment-gateway'),
// 				),
			);
			$this->form_fields += array(

				'ad1' => array(
					'title' => 'Support',
					'type' => 'title',
					'description' => 'For queries or support please email <a href="mailto:vendor-tools@elys.money">vendor-tools@elys.money</a>
or visit our <a href="https://discord.gg/VxUzAfkGqD">Discord Server</a> and ask for help in <a href="https://discord.gg/VxUzAfkGqD" >#Tech-Support-Vendors</a>',
				),
			);
		}
		/**
		 * 加载前台的支付用的 JavaScript
		 */
		public function payment_scripts() {
			wp_enqueue_script('inkerk_web3', plugins_url('assets/web3.min.js', __FILE__), array('jquery'), 1.1, true);
			/** wp_enqueue_script('@metamask/detect-provider', 'https://unpkg.com/@metamask/detect-provider/dist/detect-provider.min.js', array(), false, true); */
			wp_register_script('inkerk_payments', plugins_url('assets/payments.js', __FILE__), array('jquery', 'inkerk_web3'));
			wp_enqueue_script('inkerk_payments');
		}

		/**
		 * 不做表单验证，因为结算页面没有设置表单。
		 */
		public function validate_fields() {
			return true;
		}

		/**
		 * 用户结算页面的下一步操作
		 */
		public function process_payment($order_id) {
			global $woocommerce;
			$order = wc_get_order($order_id);
			/**
			 * 标记订单为未支付。
			 */
			$order->add_order_note(__('create order ,wait for payment', 'wc-elys-payment-gateway'));
			/**
			 * 设置订单状态为 unpaid ,后续可以使用 needs_payments 监测到
			 */
			$order->update_status('unpaid', __('Wait For Payment', 'wc-elys-payment-gateway'));
			/**
			 * 减少库存
			 */
			$order->reduce_order_stock();
			/**
			 * 清空购物车
			 */
			WC()->cart->empty_cart();
			/**
			 * 支付成功，进入 thank you 页面
			 */
			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url($order),
			);
		}
		/**
		 * 检查是否使用了 SSL，确保安全。
		 */
		public function do_ssl_check() {
			if ($this->enabled == "yes") {
				if (get_option('woocommerce_force_ssl_checkout') == "no") {
					echo "<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>";
				}
			}
		}
		/**
		 * thank you 页面配置
		 * 需要在此提醒用户支付。
		 */
		public function thankyou_page($order_id) {
			/**
			 * 如果未传入 order_id， 就返回。
			 */
			if (!$order_id) {
				return;
			}

			$order = wc_get_order($order_id);
			/**
			 * 监测订单是否需要支付
			 */
			if ($order->needs_payment()) {
				/**
				 * 如果需要支付，就输出订单信息t
				 */
				echo '<script>var order_id = ' . esc_attr($order_id) . ';var contract_address = "' . esc_attr($this->contract_address) . '"; var target_address = "' . esc_attr($this->target_address) . '"; </script>';
				echo __('<h2 class="h2thanks">Use Metamask to Pay this Order</h2>', 'wc-elys-payment-gateway');
				echo __('Click Button Below, Pay this order.<br>', 'wc-elys-payment-gateway');
				echo '<div><button style="display: flex; align-items: center; border-radius: 5px; padding: 10px;" onclick="requestPayment(' . (string) $order->get_total() . ')">' . '<img src="' . realpath('assets/logo.png'). '" style="padding-right: 10px;"/>' . __('Pay with ELYS from Metamask', 'wc-elys-payment-gateway') . '</button></div>';
				echo '<div id="msg"></div>';
				echo '<a id="ftmLink"></a>';

			} else {
				/**
				 * 不需要支付就显示不需要支付。
				 */
				echo __('<h2>Thank you for Payment!</h2>', 'wc-elys-payment-gateway');
			}
		}
	}
}
