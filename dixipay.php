<?php
//configuration Settings 
define( 'ABPATH', dirname(__FILE__) . '/' );

require_once( ABPATH . 'wp-load.php' );
require_once( ABPATH . 'wp-includes/wp-db.php' );
global $wpdb, $woocommerce;

$data = serialize($_REQUEST);
        // generate signature from callback params
if(isset($_REQUEST['order'])) {
	// getting saved dixipay password from db
	$query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_dixipay_settings'";
    $serialize_data = $wpdb->get_var($query);
	$unserialized = unserialize($serialize_data);
	$client_password = $unserialized['client_password'];
	$sign = md5(
                strtoupper(
                        strrev($_POST['email']) .
						$client_password .
                        $_POST['order'] .
                        strrev(substr($_POST['card'], 0, 6) . substr($_POST['card'], -4))
                )
        );

// verify signature
        if ($_POST['sign'] !== $sign) {
            die("ERROR: Bad signature");
        }
		
		// verifying OrderId
		$query = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID='".$_POST['order']."'";
        if($wpdb->get_var($query) == 0) {
            die('ERROR: Bad order ID');
        }
		
		//verifying Status
		switch ($_POST['status']) {
            case 'SALE':
				$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
				array(
					$_POST['order'],
					'_dixipay_data',
					$data
				)
			   ));
                break;
            case 'SETTLED':
                break;
            case 'REFUND/REVERSAL':
                break;
            default:
                die("ERROR: Invalid callback data");
        }


}
else{
	exit("You Dn't Belong Here!");
}
        exit("OK");
    
////////////////////////////////////
?>