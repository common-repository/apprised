<?php
/**
 * @package: apprised-plugin
 */

/**
 * Plugin Name: Apprised
 * Description: Apprised is a social proof marketing platform that works with your Wordpress and WooCommerce websites out of the box
 * Version: 1.3.5
 * Author: Apprised
 * Author URI: https://apprised.app
 * License: GPLv3 or later
 * Text Domain: apprised-plugin
 */

if (!defined('ABSPATH')) {
    die;
}

/** constants */
class ApprisedConstants
{
    public static function options_group()
    {
        return 'apprised_options';
    }

    public static function option_api_key()
    {
        return 'api_key';
    }
}

/* hooks */
add_action('admin_menu', 'apprised_admin_menu'); //1.5.0
add_action('admin_init', 'apprised_admin_init'); //2.5.0
add_action('admin_notices', 'apprised_admin_notice_html'); //3.1.0
add_action('wp_head', 'apprised_inject_code'); //1.2.0
// add_action('woocommerce_checkout_create_order', 'apprised_woocommerce_order_created', 20, 2);
add_action('woocommerce_checkout_order_processed', 'apprised_order_processed');

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('woocommerce_created_customer', 'apprised_woo_user_register', 999, 3);
} else {
    add_action('user_register', 'Apprised_user_register', 999);
}

function apprised_admin_menu()
{
    add_menu_page('Apprised Settings', 'Apprised', 'manage_options', 'apprised', 'apprised_menu_page_html', 'dashicons-chart-area');
}

function apprised_admin_init()
{
    wp_enqueue_style('apprised_admin_style', plugin_dir_url(__FILE__).'style.css');
    register_setting(ApprisedConstants::options_group(), ApprisedConstants::option_api_key());
    wp_register_style('dashicons-apprised', plugin_dir_url(__FILE__).'/assets/css/dashicons-apprised.css');
    wp_enqueue_style('dashicons-apprised');
}

function apprised_inject_code()
{
    $apiKey = apprised_get_api_key();
    $src= "https://my.apprised.app/pixel/".$apiKey;
    ?>

    
    <!-- Start of Async Apprised Code (Wordpress) -->
    <script async 
    src="<?=$src ?>"></script>
    <!-- End of Async Apprised Code -->

    <?php
}

function apprised_order_processed($id)
{
    $order = wc_get_order($id);
    $items = $order->get_items();
    $products = array();
    foreach ($items as $item) {
        $quantity = $item->get_quantity();
        $product = $item->get_product();
        $images_arr = wp_get_attachment_image_src($product->get_image_id(), array('72', '72'), false);
        $image = null;
        if ($images_arr !== null && $images_arr[0] !== null) {
            $image = $images_arr[0];
            if (is_ssl() && strpos($image, "https") == false) {
				$image = str_replace('http', 'https', $image);
            }
        }
        $p = array(
            'id' => $product->get_id(),
            'quantity' => (int) $quantity,
            'price' => (int) $product->get_price(),
            'name' => $product->get_name(),
            'link' => get_permalink($product->get_id()),
            'image' => $image,
        );
        array_push($products, $p);
    }
    apprised_send_webhook($order, $products);
}

function Apprised_user_register($id)
{
    $user = new WP_User($id);
    $meta = get_user_meta($id);
    if (!isset($user)) {
        return;
    }

    // apprised_log('wp user event', $user);
    apprised_send_user($user->user_email, $meta);
}

function apprised_woo_user_register($id, $data, $pass)
{
    $user = new WP_User($id);
    $meta = get_user_meta($id);
    // apprised_log('woo user event', $user);
    apprised_send_user($data['user_email'], $meta);
}

/** hooks - END */

/** helpers */
function apprised_send_user($email, $meta)
{
    $apiKey = apprised_get_api_key();
    if (!isset($email) || !isset($apiKey)) {
        // apprised_log('no email or api key');
        return;
    }

    $data = array(
        'email' => is_email(sanitize_email($email))?sanitize_email($email):"",
        'siteUrl' => get_site_url(),
    );

    if (isset($meta['first_name'][0])) {
        $first_name = sanitize_text_field($meta['first_name'][0]);
        if ( strlen( $first_name ) > 20 )
        {
             $first_name = substr( $first_name, 0, 20 );
        }
        $data['firstName'] = $first_name;
    } elseif (isset($_POST['first_name']) && strlen($_POST['first_name']) > 0) {
        
        $first_name =  sanitize_text_field($_POST['first_name']);
        if ( strlen( $first_name ) > 20 )
        {
             $first_name = substr( $first_name, 0, 20 );
        }
        $data['firstName'] = $first_name;
    }

     if (isset($meta['last_name'][0])) {
        $last_name = sanitize_text_field($meta['last_name'][0]);
        if ( strlen( $last_name ) > 20 )
        {
             $last_name = substr( $last_name, 0, 20 );
        }
        $data['lastName'] = $last_name;
    } elseif (isset($_POST['last_name']) && strlen($_POST['last_name']) > 0) {
        
        $last_name =  sanitize_text_field($_POST['last_name']);
        if ( strlen( $last_name ) > 20 )
        {
             $last_name = substr( $last_name, 0, 20 );
        }
        $data['lastName'] = $last_name;
    }

    if (isset($_SERVER['REMOTE_ADDR'])) {
        $data['ip'] = $_SERVER['REMOTE_ADDR'];
    }

    $headers = array(
        'Content-Type' => 'application/json',
        'Authorization' => "Bearer $apiKey",
    );

    $url = 'https://my.apprised.app/api/WoocommerceConversion';
    $json = json_encode($data);
    // apprised_log('sending data', $json);
    $res = wp_remote_post($url, array(
        'headers' => $headers,
        'body' => $json,
    ));
}

function apprised_send_webhook($order, $products)
{
    $apiKey = apprised_get_api_key();
    if (!isset($apiKey)) {
        return;
    }

    $headers = array(
        'Content-Type' => 'application/json',
        'authorization' => "Bearer $apiKey",
    );
    $data = array(
        'firstName' => sanitize_text_field($order->get_billing_first_name()),
        'lastName' => sanitize_text_field($order->get_billing_last_name()),
        'email' =>sanitize_email( $order->get_billing_email()),
        'ip' => $order->get_customer_ip_address(),
        'siteUrl' => get_site_url(),
        'total' => (int) $order->get_total(),
        'currency' => $order->get_currency(),
        'products' => $products,
    );
    $url = 'https://my.apprised.app/api/WoocommerceConversion';
    $res = wp_remote_post($url, array(
        'headers' => $headers,
        'body' => json_encode($data),
    ));
}

function apprised_get_api_key()
{
    $apiKey = get_option(ApprisedConstants::option_api_key());
    if (isset($apiKey) )  {
        
        return $apiKey;
    }

    return null;
}

function apprised_menu_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $apiKey = apprised_get_api_key(); ?>

	<div class="wrap" id="ps-settings">
		<!-- <h1><?=esc_html(get_admin_page_title()); ?></h1> -->
		<a href="https://apprised.app">
			<img class="top-logo" src="<?php echo plugin_dir_url(__FILE__).'assets/apprisedlogo.png'; ?>">
		</a>
		<form action="options.php" method="post">
			<?php
				settings_fields(ApprisedConstants::options_group());
				do_settings_sections(ApprisedConstants::options_group()); 
			?>

			<div class="ps-settings-container">
				<?php if (isset($apiKey)) { ?>
					<div class="ps-success">Apprised is Installed</div>
					<div class="ps-warning">If you still see <strong>"waiting for data..."</strong> open your website in <strong>incognito</strong> or <strong>clear cache</strong></div>
				<?php } else { ?>
					<div class="account-link">If you don't have an account - <a href="https://my.apprised.app/register?utm_source=woocommerce&utm_medium=plugin&utm_campaign=woocommerce-signup#/signup" target="_blank">signup here!</a></div>
				<?php } ?>

				<div class="label">Your API Key:</div>
				<input type="text" placeholder="required" name="<?php echo esc_html(ApprisedConstants::option_api_key()); ?>" value="<?php echo esc_attr(get_option(ApprisedConstants::option_api_key())); ?>" />
				<div class="m-t"><a href="https://my.apprised.app/install" target="_blank">Where is my API Key?</a></div>
			</div>

			<?php submit_button('Save'); ?>
		
        </form>
    </div>

    <?php
}

function apprised_admin_notice_html()
{
    $apiKey = apprised_get_api_key();
    if ($apiKey !== null) {
        return;
    }

    // $screen = get_current_screen();
    // if($screen !== null && strpos($screen->id, 'apprised') > 0) return;

    ?>

	<div class="notice notice-error is-dismissible">
        <p class="ps-error">Apprised is not configured! <a href="admin.php?page=apprised">Click here</a></p>
    </div>

	<?php
}

function apprised_log($message, $data = null)
{
    if (isset($data)) {
        error_log($message.': '.print_r($data, true));
        echo print_r($message);
    } else {
        error_log($message);
        echo $message;
    }
}

function apprised_var_dump_str($data)
{
    ob_start();
    var_dump($data);

    return ob_get_clean();
}

/* helpers - END */

?>
