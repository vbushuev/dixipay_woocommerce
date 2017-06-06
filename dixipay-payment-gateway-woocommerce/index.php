<?php
/*
Plugin Name: WooCommerce DIXIPAY Gateway
Plugin URI: http://WooThemes.com/
Description: Extends WooCommerce with an DIXIPAY gateway.
Version: 1.0
Author: WooThemes
Author URI: http://WooThemes.com/
	Copyright: ? 20013-2014 WooThemes.
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

add_action('init','redirect_page');
function redirect_page() {
    if(isset($_GET['dixipay_cb']) && isset($_GET['order'])) {
        $site_url = get_bloginfo('url');
        $param = array();
        foreach($_GET as $key=>$value) {
            if($key == 'order') {
                $param['order_id'] = $value;
            } else {
                $param[$key] = $value;
            }
        }
        $location = $site_url . '?' . http_build_query($param,'&amp;');
        wp_redirect( $location, $status );
        exit;
	}
}

register_activation_hook(__FILE__, 'DIXIPAY');

function DIXIPAY() {
    global $wpdb;

	$table_name = $wpdb->prefix . "dixipay_transactions";
	$sql = "CREATE TABLE $table_name (
`id` INT( 11 ) NOT NULL AUTO_INCREMENT ,
`oid` INT( 11 ) NOT NULL DEFAULT '0',
`type` ENUM( 'Captured', 'Void', 'Refunded', 'schedule', 'deschedule', 'nothing' ) NOT NULL DEFAULT 'nothing',
`date` VARCHAR( 255 ) NOT NULL ,
`amount` DECIMAL( 20, 2 ) NOT NULL ,
PRIMARY KEY ( `id` )
);";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('plugins_loaded', 'woocommerce_dixipay_init', 0);
function woocommerce_dixipay_init() {
	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
	/**
 	 * Localisation
	 */
	load_plugin_textdomain('wc-gateway-dixipay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');

	/**
 	 * Gateway class
 	 */
	class WC_Gateway_Dixipay extends WC_Payment_Gateway {
	var $notify_url;
	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		$this->id                = 'dixipay';
		$this->icon              = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/dixipay.png';
		$this->has_fields        = false;
		$this->order_button_text = __( 'Proceed to DIXIPAY', 'woocommerce' );
		$this->gateway_url           = $this->get_option( 'gateway_url' );
		$this->testurl           = 'https://secure.test.dixipay.eu/payment/auth';
		$this->method_title      = __( 'DIXIPAY', 'woocommerce' );
		$this->notify_url        = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_dixipay', home_url( '/' ) ) );
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();
		// Define user set variables
		$this->title 			= $this->get_option( 'title' );
		$this->description 		= $this->get_option( 'description' );
		$this->api_key 	        = $this->get_option( 'api_key' );
		$this->client_key 	    = $this->get_option( 'client_key' );
		$this->client_password 	= $this->get_option( 'client_password' );
		$this->payment_type 	= $this->get_option( 'payment_type' );
		$this->merchant_code 	= $this->get_option( 'merchant_code' );
		$this->customer_account_code 	= $this->get_option( 'customer_account_code' );
		$this->lang 	        = $this->get_option( 'lang' );
		//$this->success_url 	        = $this->get_option( 'success_url' );
		$this->error_url 	        = $this->get_option( 'error_url' );
		//$this->receiver_email   = $this->get_option( 'receiver_email', $this->email );
		$this->testmode			= $this->get_option( 'testmode' );
		$this->send_shipping	= $this->get_option( 'send_shipping' );
		$this->address_override	= $this->get_option( 'address_override' );
		$this->debug			= $this->get_option( 'debug' );
		$this->form_submission_method = true;
		$this->page_style 		= $this->get_option( 'page_style' );
		$this->invoice_prefix	= $this->get_option( 'invoice_prefix', 'WC-' );
		$this->paymentaction    = $this->get_option( 'paymentaction', 'sale' );
		$this->identity_token   = $this->get_option( 'identity_token', '' );
        $this->currency         = $this->get_option('woocommerce_currency');
		// Logs
		if ( 'yes' == $this->debug ) {
			$this->log = new WC_Logger();
		}
		// Actions
		add_action( 'valid-dixipay-standard-ipn-request', array( $this, 'successful_request' ) );
	if(!isset($_GET['order'])) {
		add_action( 'woocommerce_receipt_dixipay', array( $this, 'receipt_page' ) );
	}
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_dixipay', array( $this, 'pdt_return_handler' ) );
		// Payment listener/API hook
		//add_action( 'woocommerce_api_wc_gateway_dixipay', array( $this, 'check_ipn_response' ) );
		if ( ! $this->is_valid_for_use() ) {
			$this->enabled = false;
		}
	}
	/**
	 * Check if this gateway is enabled and available in the user's country
	 *
	 * @access public
	 * @return bool
	 */
	function is_valid_for_use() {
		if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_dixipay_supported_currencies', array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB', 'RUB' ) ) ) ) {
			return false;
		}
		return true;
	}
	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		?>
		<h3><?php _e( 'DIXIPAY', 'woocommerce' ); ?></h3>
		<p><?php _e( 'DIXIPAY works by sending the user to DIXIPAY to enter their payment information.', 'woocommerce' ); ?></p>
		<?php if ( $this->is_valid_for_use() ) : ?>
			<table class="form-table">
			<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
			?>
			</table><!--/.form-table-->
		<?php else : ?>
			<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( 'DIXIPAY does not support your store currency.', 'woocommerce' ); ?></p></div>
		<?php
			endif;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable DIXIPAY', 'woocommerce' ),
				'default' => 'yes'
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'DIXIPAY', 'woocommerce' ),
				'desc_tip'    => false,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Pay via DIXIPAY; you can pay with your master card if you don\'t have a DIXIPAY account', 'woocommerce' )
			),
			'gateway_url' => array(
				'title'       => __( 'Gateway Url', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your DIXIPAY URL.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'Gateway URL'
			),
			'client_key' => array(
				'title'       => __( 'User name', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your DIXIPAY Key.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'User name'
			),
			'client_password' => array(
				'title'       => __( 'Password', 'woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your DIXIPAY Password.', 'woocommerce' ),

				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'Password'
			),
			'merchant_code' => array(
				'title'       => __( 'Merchant code', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Please Type MerchantAccountCode.', 'woocommerce' ),

				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'Merchant Account Code'
			),
			'customer_account_code' => array(
				'title'       => __( 'customerAccountCode', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Please Type customerAccountCode.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'customerAccountCode'
			),
			'api_key' => array(
				'title'       => __( 'API key', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Please Type DIXIPAY API key.', 'woocommerce' ),

				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'API key'
			),
			'payment_type' => array(
				'title'       => __( 'Payment Type', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Please Type Payment Type e.g CC.', 'woocommerce' ),

				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'Payment Type'
			),
			'lang' => array(
				'title'       => __( 'Language', 'woocommerce' ),
				'type'        => 'select',
				'description' => __( 'The language that the DIXIPAY secure processing page will be displayed in.', 'woocommerce' ),
				'default'     => 'en',
				'desc_tip'    => false,
				'options'     => array(
					'en'      => __( 'EN', 'woocommerce' ),
					'ar'      => __( 'AR', 'woocommerce' ),
					'de'      => __( 'DE', 'woocommerce' ),
					'dk'      => __( 'DK', 'woocommerce' ),
					'es'      => __( 'ES', 'woocommerce' ),
					'fr'      => __( 'FR', 'woocommerce' ),
					'it'      => __( 'IT', 'woocommerce' ),
					'jp'      => __( 'JP', 'woocommerce' ),
					'nl'      => __( 'NL', 'woocommerce' ),
					'pt'      => __( 'PT', 'woocommerce' ),
					'ru'      => __( 'RU', 'woocommerce' ),
					'tr'      => __( 'TR', 'woocommerce' ),
				)
			),
			'error_url' => array(
				'title'       => __( 'Error URL', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your Fail Page URL.', 'woocommerce' ),

				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'Error URL'
			),

		);
	}
	/**
	 * Get dixipay Args for passing to PP
	 *
	 * @access public
	 * @param mixed $order
	 * @return array
	 */
	function get_dixipay_args( $order ) {
		$order_id = $order->id;
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'dixipay', 'Generating payment form for order ' . $order->get_order_number() . '. Notify URL: ' . $this->notify_url );
		}
        $foo = $order->get_total();
        $orderTotal = number_format((float)$foo, 2, '.', '');
        $encoded_data = base64_encode(
                json_encode(
                        array(
                            'amount' => $orderTotal,
                            'name' => 'Order from WooCommerce',
                            'currency' => get_woocommerce_currency(),
							'recurring'
                        )
                )
          );
		$success_url = esc_url( add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ) );
	    $success_url = $success_url.'&dixipay_cb=1';
		$success_url = str_replace('&#038;','&',$success_url);

				$encoded_signature = md5 (strtoupper (
				strrev($this->client_key).
				strrev($this->payment_type).
				strrev($encoded_data).
				strrev($success_url).
				strrev($this->client_password)
			));

		if($this->error_url!='') {
		    $error_url = $this->error_url;
		}else {
			$error_url = esc_url( $order->get_cancel_order_url() );
		}
        //Order items
        $orderItems="";
        $orderItemsCount = 0;
        // (code=167;itemNumber=167;description=Товар;quantity=1;price=54995;unitCostAmount=54995;totalAmount=54995)
        foreach ( $order->get_items() as $item_id => $item ) {
            $product                    = $item->get_product();
            $orderItems.="(";
            if(is_object( $product ))$orderItems.="code=". $product->get_sku();
            $orderItems="itemNumber=".$item_id;
            $orderItems="description=".$item->get_name();
            $orderItems="quantity=".$item->get_quantity();
            $orderItems="price=".wc_format_decimal( $order->get_line_total( $item ), 2 );
            $orderItems="unitCostAmount=".wc_format_decimal( $order->get_line_subtotal( $item ), 2 );
            $orderItems="totalAmount=".wc_format_decimal( $order->get_line_total( $item ), 2 );
            $orderItems.=")";
            $orderItemsCount++;
        }
		// dixipay Args
		$dixipay_args = array_merge(
			array(
				//'cmd'           => '_cart',
                'requestType' =>'sale',
                'merchantAccountCode' => $this->merchant_code,//'300001',
                'userName'=>$this->client_key,
                'password'=>$this->client_password,
                'ticketNumber'=> $order_id,
                'transactionIndustryType'=>'EC',

                "customerAccountCode"=>$this->customer_account_code,
                'amount'=>wc_format_decimal( $order->get_total(), 2 ),
                'transactionIndustryType'=>'EC',
                'transactionCode'=>'000000002353',
                'apiKey'=>$this->api_key,

                "accountType"=>"R",
            	"currency"=>$order->get_currency(),
            	"lang"=>$this->lang,


                "memo"=>"xyz",
            	"holderType"=>"P",
            	"holderName"=>"DIX+PAY",
            	"holderBirthdate"=>"19700101",
            	"street"=>$order->get_billing_address_1().$order->get_billing_address_2(),
            	"city"=>$order->get_billing_city(),
            	"zipCode"=>$order->get_billing_postcode(),
            	"phone"=>$order->get_billing_phone(),
            	"email"=>$order->get_billing_email(),

                "itemCount"=>"{$orderItemsCount}",
            	"items"=>$orderItems,
			)
		);

		/////////////////////////////////////////////////
		$dixipay_args = apply_filters( 'woocommerce_dixipay_args', $dixipay_args );

		return $dixipay_args;
	}
	/**
	 * Generate the dixipay button link
	 *
	 * @access public
	 * @param mixed $order_id
	 * @return string
	 */
	function generate_dixipay_form( $order_id ) {
		$order = new WC_Order( $order_id );
		// $dixipay_adr = $this->gateway_url ;
		$dixipay_adr = "https://lk.dixipay.eu/gates/paypage" ;
		$dixipay_args = $this->get_dixipay_args( $order );
        // Create a stream
        $opts = array(
            'http'=>array(
                'method'=>"GET",
                'header'=>"Content-Type: text/plain; charset=utf-8\r\n"
            )
        );
        $responseString = file_get_contents("https://lk.dixipay.eu/gates/signature".http_build_query($dixipay_args), false, stream_context_create($opts));
        if(preg_match_all("/([^=]+)=([^\&]+)&?/uim",$responseString,$m)){
            for($i=0;$i<count($m[0]);++$i){
                $response[$m[1][$i]] = urldecode($m[2][$i]);
            }
        }
        $dixipay_args_array = array();
		$dixipay_args_array[] = '<input type="hidden" name="action" value="' . esc_attr($response["action"]). '" />';

		wc_enqueue_js( '
			$.blockUI({
					message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to DIXIPAY to make payment.', 'woocommerce' ) ) . '",
					baseZ: 99999,
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css: {
						padding:        "20px",
						zindex:         "9999999",
						textAlign:      "center",
						color:          "#555",
						border:         "3px solid #aaa",
						backgroundColor:"#fff",
						cursor:         "wait",
						lineHeight:		"24px",
					}
				});
			jQuery("#submit_dixipay_payment_form").click();
		' );

		return '<form action="' . esc_url( $dixipay_adr ) . '" method="GET" id="dixipay_payment_form" target="_top">
				' . implode( '', $dixipay_args_array ) . '
				<!-- Button Fallback -->
				<div class="payment_buttons">
					<input type="submit" class="button alt" id="submit_dixipay_payment_form" value="' . __( 'Pay via DIXIPAY', 'woocommerce' ) . '" /> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', 'woocommerce' ) . '</a>
				</div>
				<script type="text/javascript">
					jQuery(".payment_buttons").hide();
				</script>
			</form>';
	}
	/**
	 * Process the payment and return the result
	 *
	 * @access public
	 * @param int $order_id
	 * @return array
	 */
	function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );
		if ( ! $this->form_submission_method ) {
			$dixipay_args = $this->get_dixipay_args( $order );
			$dixipay_args = http_build_query( $dixipay_args, '', '&' );
			$dixipay_adr = $this->gateway_url . '?';
			return array(
				'result' 	=> 'success',
				'redirect'	=> $dixipay_adr . $dixipay_args
			);
		} else {
			return array(
				'result' 	=> 'success',
				'redirect'	=> $order->get_checkout_payment_url( true )
			);
		}
	}
	/**
	 * Output for the order received page.
	 *
	 * @access public
	 * @return void
	 */
	function receipt_page( $order ) {
		echo '<p>' . __( 'Thank you - your order is now pending payment. You should be automatically redirected to DIXIPAY to make payment.', 'woocommerce' ) . '</p>';
		echo $this->generate_dixipay_form( $order );
	}
	/**
	 * Check dixipay IPN validity
	 **/
	function check_ipn_request_is_valid( $ipn_response ) {
		// Get url
		$dixipay_adr = $this->gateway_url . '?';
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'dixipay', 'Checking IPN response is valid via ' . $dixipay_adr . '...' );
		}
		// Get recieved values from post data
		$validate_ipn = array( 'cmd' => '_notify-validate' );
		$validate_ipn += stripslashes_deep( $ipn_response );
		// Send back post vars to dixipay
		$params = array(
			'body' 			=> $validate_ipn,
			'sslverify' 	=> false,
			'timeout' 		=> 60,
			'httpversion'   => '1.1',
			'compress'      => false,
			'decompress'    => false,
			'user-agent'	=> 'WooCommerce/' . WC()->version
		);
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'dixipay', 'IPN Request: ' . print_r( $params, true ) );
		}
		// Post back to get a response
		$response = wp_remote_post( $dixipay_adr, $params );
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'dixipay', 'IPN Response: ' . print_r( $response, true ) );
		}
		// check to see if the request was valid
		if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && ( strcmp( $response['body'], "VERIFIED" ) == 0 ) ) {
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'dixipay', 'Received valid response from DIXIPAY' );
			}
			return true;
		}
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'dixipay', 'Received invalid response from DIXIPAY' );
			if ( is_wp_error( $response ) ) {
				$this->log->add( 'dixipay', 'Error response: ' . $response->get_error_message() );
			}
		}
		return false;
	}

	/**
	 * Successful Payment!
	 *
	 * @access public
	 * @param array $posted
	 * @return void
	 */
	function successful_request( $posted ) {
		$posted = stripslashes_deep( $posted );
		// Custom holds post ID
		if ( ! empty( $posted['invoice'] ) && ! empty( $posted['custom'] ) ) {
			$order = $this->get_dixipay_order( $posted['custom'], $posted['invoice'] );
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'dixipay', 'Found order #' . $order->id );
			}
			// Lowercase returned variables
			$posted['payment_status'] 	= strtolower( $posted['payment_status'] );
			$posted['txn_type'] 		= strtolower( $posted['txn_type'] );
			// Sandbox fix
			if ( 1 == $posted['test_ipn'] && 'pending' == $posted['payment_status'] ) {
				$posted['payment_status'] = 'completed';
			}
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'dixipay', 'Payment status: ' . $posted['payment_status'] );
			}
			// We are here so lets check status and do actions
			switch ( $posted['payment_status'] ) {
				case 'completed' :
				case 'pending' :
					// Check order not already completed
					if ( $order->status == 'completed' ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'dixipay', 'Aborting, Order #' . $order->id . ' is already complete.' );
						}
						exit;
					}
					// Check valid txn_type
					$accepted_types = array( 'cart', 'instant', 'express_checkout', 'web_accept', 'masspay', 'send_money' );
					if ( ! in_array( $posted['txn_type'], $accepted_types ) ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'dixipay', 'Aborting, Invalid type:' . $posted['txn_type'] );
						}
						exit;
					}
					// Validate currency
					if ( $order->get_order_currency() != $posted['mc_currency'] ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'dixipay', 'Payment error: Currencies do not match (code ' . $posted['mc_currency'] . ')' );
						}
						// Put this order on-hold for manual checking
						$order->update_status( 'on-hold', sprintf( __( 'Validation error: DIXIPAY currencies do not match (code %s).', 'woocommerce' ), $posted['mc_currency'] ) );
						exit;
					}
					// Validate amount
					if ( $order->get_total() != $posted['mc_gross'] ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'dixipay', 'Payment error: Amounts do not match (gross ' . $posted['mc_gross'] . ')' );
						}
						// Put this order on-hold for manual checking
						$order->update_status( 'on-hold', sprintf( __( 'Validation error: DIXIPAY amounts do not match (gross %s).', 'woocommerce' ), $posted['mc_gross'] ) );
						exit;
					}
					// Validate Email Address
					if ( strcasecmp( trim( $posted['receiver_email'] ), trim( $this->receiver_email ) ) != 0 ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'dixipay', "IPN Response is for another one: {$posted['receiver_email']} our email is {$this->receiver_email}" );
						}
						// Put this order on-hold for manual checking
						$order->update_status( 'on-hold', sprintf( __( 'Validation error: DIXIPAY IPN response from a different email address (%s).', 'woocommerce' ), $posted['receiver_email'] ) );
						exit;
					}
					 // Store PP Details
					if ( ! empty( $posted['payer_email'] ) ) {
						update_post_meta( $order->id, 'Payer DIXIPAY address', wc_clean( $posted['payer_email'] ) );
					}
					if ( ! empty( $posted['txn_id'] ) ) {
						update_post_meta( $order->id, 'Transaction ID', wc_clean( $posted['txn_id'] ) );
					}
					if ( ! empty( $posted['first_name'] ) ) {
						update_post_meta( $order->id, 'Payer first name', wc_clean( $posted['first_name'] ) );
					}
					if ( ! empty( $posted['last_name'] ) ) {
						update_post_meta( $order->id, 'Payer last name', wc_clean( $posted['last_name'] ) );
					}
					if ( ! empty( $posted['payment_type'] ) ) {
						update_post_meta( $order->id, 'Payment type', wc_clean( $posted['payment_type'] ) );
					}
					if ( $posted['payment_status'] == 'completed' ) {
						$order->add_order_note( __( 'IPN payment completed', 'woocommerce' ) );
						$order->payment_complete();
					} else {
						$order->update_status( 'on-hold', sprintf( __( 'Payment pending: %s', 'woocommerce' ), $posted['pending_reason'] ) );
					}
					if ( 'yes' == $this->debug ) {
						$this->log->add( 'dixipay', 'Payment complete.' );
					}
				break;
				case 'denied' :
				case 'expired' :
				case 'failed' :
				case 'voided' :
					// Order failed
					$order->update_status( 'failed', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), strtolower( $posted['payment_status'] ) ) );
				break;
				case 'refunded' :
					// Only handle full refunds, not partial
					if ( $order->get_total() == ( $posted['mc_gross'] * -1 ) ) {
						// Mark order as refunded
						$order->update_status( 'refunded', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), strtolower( $posted['payment_status'] ) ) );
						$mailer = WC()->mailer();
						$message = $mailer->wrap_message(
							__( 'Order refunded/reversed', 'woocommerce' ),
							sprintf( __( 'Order %s has been marked as refunded - DIXIPAY reason code: %s', 'woocommerce' ), $order->get_order_number(), $posted['reason_code'] )
						);
						$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s refunded/reversed', 'woocommerce' ), $order->get_order_number() ), $message );
					}
				break;
				case 'reversed' :
					// Mark order as refunded
					$order->update_status( 'on-hold', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), strtolower( $posted['payment_status'] ) ) );
					$mailer = WC()->mailer();
					$message = $mailer->wrap_message(
						__( 'Order reversed', 'woocommerce' ),
						sprintf(__( 'Order %s has been marked on-hold due to a reversal - DIXIPAY reason code: %s', 'woocommerce' ), $order->get_order_number(), $posted['reason_code'] )
					);
					$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s reversed', 'woocommerce' ), $order->get_order_number() ), $message );
				break;
				case 'canceled_reversal' :
					$mailer = WC()->mailer();
					$message = $mailer->wrap_message(
						__( 'Reversal Cancelled', 'woocommerce' ),
						sprintf( __( 'Order %s has had a reversal cancelled. Please check the status of payment and update the order status accordingly.', 'woocommerce' ), $order->get_order_number() )
					);
					$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Reversal cancelled for order %s', 'woocommerce' ), $order->get_order_number() ), $message );
				break;
				default :
					// No action
				break;
			}
			exit;
		}
	}
	/**
	 * Return handler
	 *
	 * Alternative to IPN
	 */
	public function pdt_return_handler() {
		//echo "<pre>";print_r($_REQUEST);die();
		$posted = stripslashes_deep( $_REQUEST );
		if ( ! empty( $this->identity_token ) && ! empty( $posted['cm'] ) ) {
			$order = $this->get_dixipay_order( $posted['cm'] );
			if ( 'pending' != $order->status ) {
				return false;
			}
			$posted['st'] = strtolower( $posted['st'] );
			switch ( $posted['st'] ) {
				case 'completed' :
					// Validate transaction
					$dixipay_adr = $this->gateway_url . '?';
					$pdt = array(
						'body' 			=> array(
							'cmd' => '_notify-synch',
							'tx'  => $posted['tx'],
							'at'  => $this->identity_token
						),
						'sslverify' 	=> false,
						'timeout' 		=> 60,
						'httpversion'   => '1.1',
						'user-agent'	=> 'WooCommerce/' . WC_VERSION
					);
					// Post back to get a response
					$response = wp_remote_post( $dixipay_adr, $pdt );
					if ( is_wp_error( $response ) ) {
						return false;
					}
					if ( ! strpos( $response['body'], "SUCCESS" ) === 0 ) {
						return false;
					}
					// Validate Amount
					if ( $order->get_total() != $posted['amt'] ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'dixipay', 'Payment error: Amounts do not match (amt ' . $posted['amt'] . ')' );
						}
						// Put this order on-hold for manual checking
						$order->update_status( 'on-hold', sprintf( __( 'Validation error: DIXIPAY amounts do not match (amt %s).', 'woocommerce' ), $posted['amt'] ) );
						return true;
					} else {
						// Store PP Details
						update_post_meta( $order->id, 'Transaction ID', wc_clean( $posted['tx'] ) );
						$order->add_order_note( __( 'PDT payment completed', 'woocommerce' ) );
						$order->payment_complete();
						return true;
					}
				break;
			}
		}
		return false;
	}
	/**
	 * get_dixipay_order function.
	 *
	 * @param  string $custom
	 * @param  string $invoice
	 * @return WC_Order object
	 */
	private function get_dixipay_order( $custom, $invoice = '' ) {
		$custom = maybe_unserialize( $custom );
		// Backwards comp for IPN requests
		if ( is_numeric( $custom ) ) {
			$order_id  = (int) $custom;
			$order_key = $invoice;
		} elseif( is_string( $custom ) ) {
			$order_id  = (int) str_replace( $this->invoice_prefix, '', $custom );
			$order_key = $custom;
		} else {
			list( $order_id, $order_key ) = $custom;
		}
		$order = new WC_Order( $order_id );
		if ( ! isset( $order->id ) ) {
			// We have an invalid $order_id, probably because invoice_prefix has changed
			$order_id 	= wc_get_order_id_by_order_key( $order_key );
			$order 		= new WC_Order( $order_id );
		}
		// Validate key
		if ( $order->order_key !== $order_key ) {
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'dixipay', 'Error: Order Key does not match invoice.' );
			}
			exit;
		}
		return $order;
	}


	}

	/**
 	* Add the Gateway to WooCommerce
 	**/
	function woocommerce_add_dixipay_gateway($methods) {
		$methods[] = 'WC_Gateway_Dixipay';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_dixipay_gateway' );
}
// Showing Data in Order Detail Form
function output_dixipay($post) {
	//echo "<pre>"; print_r($post);die();
		global $wpdb;
		$query = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_data' AND post_id='".$post->id."'";
        if($wpdb->get_var($query) > 0) {
			//checking status from dixipay
			  $query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_data' AND post_id='".$post->id."'";
			  $serialize_data = $wpdb->get_var($query);
			//CHM Start
			if($serialize_data) {
			 $data = unserialize($serialize_data);
			 $test = $data;//
			 $card_data=explode("****",$data['card']);
			 $card_data=$card_data[0].$card_data[1];

			 //getting password and key from db
			 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_dixipay_settings'";
			 $serialize_data = $wpdb->get_var($query);
			 $unserialized = unserialize($serialize_data);
			 $client_key = $unserialized['client_key'];
			 $client_password = $unserialized['client_password'];

			 $postUrl = "https://secure.test.dixipay.eu/api/";
			 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
			 $data = "action=GET_TRANS_DETAILS&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;

			 //Get length of post
				 $postlength = strlen($data);
				 //open connection
				 $ch = curl_init();
				 //set the url, number of POST vars, POST data
				 curl_setopt($ch,CURLOPT_URL,$postUrl);
				 curl_setopt($ch, CURLOPT_POST, true);

				 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
				 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				 curl_setopt($ch, CURLOPT_SSLVERSION,3);

				 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
				 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

				$response = curl_exec($ch);

				$res = json_decode($response, true);

				//echo "<pre>";print_r( $res );echo "</pre>";
				for($i=0;$i<count($res['transactions']);$i++){
					if($res['transactions'][$i]['type']!='AUTH'){
			  			$query="SELECT * FROM dixipay_transactions WHERE oid ='".$post->id."' AND date ='".$res['transactions'][$i]['date']."' AND type ='".$res['transactions'][$i]['type']."'";
							if ($wpdb->get_row($query)){
							}
							else{
								$wpdb->query( $wpdb->prepare(
								"INSERT INTO dixipay_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
								array(
								$post->id,
								$res['transactions'][$i]['type'],
								$res['transactions'][$i]['date'],
								$res['transactions'][$i]['amount'])));
								if($res['transactions'][$i]['type']=='REFUND'){
									$query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_order_total' AND post_id='".$post->id."'";
									if($wpdb->get_row($query)) {
					   					$meta_data = $wpdb->get_row($query);
									   	$new_total = $meta_data->meta_value - $res['transactions'][$i]['amount'];
									   	$new_total = number_format((float)$new_total, 2, '.', '');
										$wpdb->update($wpdb->postmeta,
											array( 'meta_value' => $new_total, ),
											array( 'meta_id' => $meta_data->meta_id ),
											array( '%s', '%d' ), array( '%d' )
										);
								 	  }
									if($new_total <=0){
										$status='cancelled';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );									}
									else{
										$status='refunded';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );
									}
								}

								if($res['transactions'][$i]['type']=='CAPTURE'){
										$query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_order_total' AND post_id='".$post->id."'";
										if($wpdb->get_row($query)) {
					   						$meta_data = $wpdb->get_row($query);
									   		$new_total = number_format((float)$new_total, 2, '.', '');
											$wpdb->update($wpdb->postmeta,
												array( 'meta_value' => $res['transactions'][$i]['amount']),
												array( 'meta_id' => $meta_data->meta_id ),
												array( '%s', '%d' ), array( '%d' )
											);
										}
										$status='completed';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );
								}
								if($res['transactions'][$i]['type']=='REVERSAL'){
										$status='cancelled';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );
								}
						}
					}
				}
				 curl_close($ch);
			}
			//CHM End

			// end of checking status
		   $meta_value = '';
		   $query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_order_status' AND post_id='".$post->id."'";
			   if($wpdb->get_var($query)) {
				    $meta_value = $wpdb->get_var($query);
			   }
			 ?><div style="width:500px;"><?php
				   if($meta_value!='CANCELLED') {
					   $query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message' AND post_id='".$post->id."'";
				   if($wpdb->get_var($query)) {
						$error_message = $wpdb->get_var($query);
				   }
				   //if error is already seen
				   $is_error = '';
				   $query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message_seen' AND post_id='".$post->id."'";
				   if($wpdb->get_row($query)) {
					   $meta_data = $wpdb->get_row($query);
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 1, ),
							array( 'meta_id' => $meta_data->meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
					   $is_error = $meta_data->meta_value;
				   }
				   //
				   if($error_message != '' && $is_error==0) {
					  echo "<p style='color:#F00'><b>Last Attempt Error:</b> ".$error_message."</p>";
				  }
			   ?>

                        <p class="form-field form-field-wide" style="width:50%"><label for="order_status"><?php _e( 'Order Action:', 'woocommerce' ) ?></label>
						<select id="order_action" name="order_action" class="chosen_select" onchange="checkOption()">
							<option value="refund">Refund</option>
                            <?php if($captured==0) { ?><option value="capture">Capture</option><?php } ?>
                            <option value="void">Void</option>
						</select></p>
                        <p class="form-field form-field-wide" style="width:50%"><label for="order_status"><?php _e( 'Amount:', 'woocommerce' ) ?></label>
                      <script type="text/javascript">
					  function checkOption() {
						var myselect = document.getElementById("order_action");
  						if(myselect.options[myselect.selectedIndex].value=='void') {
							document.getElementById("myText").disabled=true;
						}else {
							document.getElementById("myText").disabled=false;
						}
					  }
					  </script>
                        <input type="text" id="myText" maxlength="15" name="refund_amount" />
                        </p>

                 <?php } //}

				$query = "SELECT * FROM dixipay_transactions WHERE oid='".$post->id."'";
			   if($wpdb->get_results($query)) {
				    $row = $wpdb->get_results($query);?>


                   <h4 style="clear:both"><?php _e( 'DIXIPAY Payment Details', 'woocommerce' ); ?> </h4>
                   <table cellpadding="0" cellspacing="0" border="1" width="500px">
                     <tr>
                       <th style="width:165px">Action</th>
                       <th style="width:165px">Amount</th>
                       <th style="width:165px">Date</th>
                     </tr>
                  <?php foreach($row as $row) {
					     $style = '';
						 $str_start = '';
						 $str_end = '';
					     if($row->type=='Refunded') {$style = "color:red;"; $str_start = '('; $str_end = ')';}
						 elseif($row->type=='Captured') {$style = "color:green;"; }?>
                     <tr>
                       <td align="center" style="width:165px;<?=$style;?>"><?=$row->type?></td>
                       <td align="center" style="width:165px;<?=$style;?>"><?=$str_start.$row->amount.$str_end?></td>
                       <td align="center" style="width:165px;"><?=$row->date?></td>
                     </tr>
                   <?php } ?>
                   </table>

				<?php  }?>
                <textarea class="no-mce" wrap="off" readonly style="width:290px;height:100px;font-size:10px;"><? print_r($test);?></textarea>
                </div> <?php }

}
add_action('woocommerce_admin_order_data_after_billing_address','output_dixipay');
// Saving Data in Db
function saveData($post_id, $post=array()){
	global $wpdb;
		//chm
	if(isset($_POST['order_action']))
	{
	  // case refund
	  if($_POST['order_action']=='refund') {
		$refundAmount = $_POST['refund_amount'];
		$refund_amount = number_format((float)$refundAmount, 2, '.', '');
		if($refund_amount == ''){$refund_amount = '0';}
		$query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_data' AND post_id='".$post_id."'";
		$serialize_data = $wpdb->get_var($query);
		if($serialize_data) {
		 $data = unserialize($serialize_data);
		 $card_data=explode("****",$data['card']);
		 $card_data=$card_data[0].$card_data[1];

		 //getting password and key from db
		 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_dixipay_settings'";
		 $serialize_data = $wpdb->get_var($query);
		 $unserialized = unserialize($serialize_data);
		 $client_key = $unserialized['client_key'];
		 $client_password = $unserialized['client_password'];
		 $postUrl = "https://secure.test.dixipay.eu/api/";
		 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
		 if($refund_amount > 0) {
		  $data = "action=CREDITVOID&client_key=".$client_key."&trans_id=".$data['id']."&amount=".$refund_amount."&hash=".$hash;
		 } else {
		  $data = "action=CREDITVOID&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;
		 }
		 //echo $hash."<br/>".$data."<br/>".$postUrl;die();
		 //Get length of post
			 $postlength = strlen($data);
			 //open connection
			 $ch = curl_init();
			 //set the url, number of POST vars, POST data
			 curl_setopt($ch,CURLOPT_URL,$postUrl);
			 curl_setopt($ch, CURLOPT_POST, true);

			 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 curl_setopt($ch, CURLOPT_SSLVERSION,3);

			 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
			 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			 $response = curl_exec($ch);
			// echo $response;die();
			 $decoded = json_decode($response, true);
			 if($decoded['result'] == 'ACCEPTED') {
			   $_POST['order_status'] = 'refunded';
			   // Order data saved, now get it so we can manipulate status
		$order = new WC_Order( $post_id );
		// Order status
		$order->update_status( $_POST['order_status'] );
				   $wpdb->query( $wpdb->prepare(
					"INSERT INTO dixipay_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
					array(
						$post_id,
						'REFUNDED',
						date("m/d/Y H:i:s"),
						$refund_amount
					)
				   ));
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
					   $meta_id = $wpdb->get_var($query);
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => '', ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }
				   // updating order total
				   $query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_order_total' AND post_id='".$post_id."'";
				   if($wpdb->get_row($query)) {
					   $meta_data = $wpdb->get_row($query);
					   $new_total = $meta_data->meta_value - $refund_amount;
					   $new_total = number_format((float)$new_total, 2, '.', '');
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $new_total, ),
							array( 'meta_id' => $meta_data->meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }

			 } else {
				   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $decoded['error_message'], ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_dixipay_error_message',
							$decoded['error_message']
						)
					   ));
				   }
				   //have to fix this later
					   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message_seen' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 0, ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_dixipay_error_message_seen',
							0
						)
					   ));
				     }
			   }
			 //close connection
			 curl_close($ch);
			 //return false;
		}
	  }
	  // case capture
	  elseif($_POST['order_action']=='capture') {
		$refundAmount = $_POST['refund_amount'];
		$refund_amount = number_format((float)$refundAmount, 2, '.', '');
		if($refund_amount == ''){$refund_amount = '0';}
		$query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_data' AND post_id='".$post_id."'";
		$serialize_data = $wpdb->get_var($query);
		if($serialize_data) {
		 $data = unserialize($serialize_data);
		 $card_data=explode("****",$data['card']);
		 $card_data=$card_data[0].$card_data[1];

		 //getting password and key from db
		 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_dixipay_settings'";
		 $serialize_data = $wpdb->get_var($query);
		 $unserialized = unserialize($serialize_data);
		 $client_key = $unserialized['client_key'];
		 $client_password = $unserialized['client_password'];

		 $postUrl = "https://secure.test.dixipay.eu/api/";
		 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
		 if($refund_amount > 0) {
		   $data = "action=CAPTURE&client_key=".$client_key."&trans_id=".$data['id']."&amount=".$refund_amount."&hash=".$hash;
		 } else {
		   $data = "action=CAPTURE&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;
		 }
		 //Get length of post
			 $postlength = strlen($data);
			 //open connection
			 $ch = curl_init();
			 //set the url, number of POST vars, POST data
			 curl_setopt($ch,CURLOPT_URL,$postUrl);
			 curl_setopt($ch, CURLOPT_POST, true);

			 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
			 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			 curl_setopt($ch, CURLOPT_SSLVERSION,3);

			 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
			 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			 $response = curl_exec($ch);
			// echo $response;die();
			 $decoded = json_decode($response, true);
		if($decoded['result'] == 'SUCCESS') {
			   $_POST['order_status'] = 'refunded';
			     // Order data saved, now get it so we can manipulate status
		$order = new WC_Order( $post_id );
		// Order status
		$order->update_status( $_POST['order_status'] );
			   $wpdb->query( $wpdb->prepare(
					"INSERT INTO dixipay_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
					array(
						$post_id,
						'CAPTURED',
						date("m/d/Y H:i:s"),
						$refund_amount
					)
				   ));
			   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
					   $meta_id = $wpdb->get_var($query);
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => '', ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }
			 } else {
				   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $decoded['error_message'], ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_dixipay_error_message',
							$decoded['error_message']
						)
					   ));
				   }
				   //have to fix this later
					   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message_seen' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 0, ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_dixipay_error_message_seen',
							0
						)
					   ));
				     }
			   }
			 //close connection
			 curl_close($ch);
			// return false;
		}
	  }
	  // case void
	  else{
		$refundAmount = $_POST['_order_total'];
		$refund_amount = number_format((float)$refundAmount, 2, '.', '');
		if($refund_amount == ''){$refund_amount = '0.00';}
		$query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_data' AND post_id='".$post_id."'";
		$serialize_data = $wpdb->get_var($query);
		if($serialize_data) {
		 $data = unserialize($serialize_data);
		 $card_data=explode("****",$data['card']);
		 $card_data=$card_data[0].$card_data[1];

		 //getting password and key from db
		 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_dixipay_settings'";
		 $serialize_data = $wpdb->get_var($query);
		 $unserialized = unserialize($serialize_data);
		 $client_key = $unserialized['client_key'];
		 $client_password = $unserialized['client_password'];

		 $postUrl = "https://secure.test.dixipay.eu/api/";
		 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
		 $data = "action=CREDITVOID&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;

		 //Get length of post
			 $postlength = strlen($data);
			 //open connection
			 $ch = curl_init();
			 //set the url, number of POST vars, POST data
			 curl_setopt($ch,CURLOPT_URL,$postUrl);
			 curl_setopt($ch, CURLOPT_POST, true);

			 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
			 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			 curl_setopt($ch, CURLOPT_SSLVERSION,3);

			 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
			 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			 $response = curl_exec($ch);
			 //echo $response;die();
			 //echo 'error='.curl_error($ch);
			 $decoded = json_decode($response, true);
			 if($decoded['result'] == 'ACCEPTED') {
			   $_POST['order_status'] = 'cancelled';
			     // Order data saved, now get it so we can manipulate status
		$order = new WC_Order( $post_id );
		// Order status
		$order->update_status( $_POST['order_status'] );
				   $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
					array(
						$post_id,
						'_dixipay_order_status',
						'CANCELLED'
					)
				   ));

			   $wpdb->query( $wpdb->prepare(
					"INSERT INTO dixipay_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
					array(
						$post_id,
						'VOID',
						date("m/d/Y H:i:s"),
						$refund_amount
					)
				   ));
			    $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
					   $meta_id = $wpdb->get_var($query);
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => '', ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }
			 } else {
				   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $decoded['error_message'], ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_dixipay_error_message',
							$decoded['error_message']
						)
					   ));
				   }
				   //have to fix this later
					   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_dixipay_error_message_seen' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 0, ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_dixipay_error_message_seen',
							0
						)
					   ));
				     }
			   }

			 //close connection
			 curl_close($ch);
			// return false;
		}
	  }
	}

}
add_action('save_post','saveData');
