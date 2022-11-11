<?php
/**
 * Plugin Name: Woo - Hubspot complete order property
 * Description: Save the complete order property products
 * Version: 1.0.0
 * Author: Rocketship LLC
 * Author URI: https://getrocketship.com
 * Requires at least: 4.1.0
 * Tested up to: 5.9.3
 *
 * Text Domain: woo-hubspot-complete-order-property-products
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

defined( 'WOOHUBSPOTOPR_PATH' ) or define( 'WOOHUBSPOTOPR_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Check if WooCommerceis active
 **/
if (in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) )){

    class WOOHUBSPOTOPR_HubSpot{

        private $key = '';
        public $instance;
        
        function __construct(){
            $this->instance = \SevenShores\Hubspot\Factory::createWithOAuth2Token($this->key);
        }
    }

    add_action( 'woocommerce_order_status_completed', 'woohubspotopr_get_products_hubspot', 10, 1 );

    //Sync hubspot with Woo (past completed orders)
    if($_GET['run-review-old-orders'] == 'run-now'){
        add_action( 'init', function(){
            //Require Composer
            require( WOOHUBSPOTOPR_PATH . '/vendor/autoload.php' );

            
            $args = array(
                'status' => array('wc-completed'),
                'orderby' => 'date',
                'order' => 'ASC',
                'type' => 'shop_order',
                'limit' => -1
            );
            $orders = wc_get_orders( $args );
            $size = count($orders);
            $hubspot = new WOOHUBSPOTOPR_HubSpot();
            
            ob_start();
            echo '<ul>';
            for ($i=0; $i < $size; $i+=100) { 
                $args = array(
                    'status' => array('wc-completed'),
                    'orderby' => 'date',
                    'order' => 'ASC',
                    'type' => 'shop_order',
                    'offset' => $i,
                    'limit' => 100
                );
                $orders = wc_get_orders( $args );
                $array = [];
                $ids = [];
                foreach ($orders as $index => $order) {
                    if($order && method_exists($order, 'get_billing_email')){
                        $arrayNew = [];
                        $arrayNew['email'] = $order->get_billing_email();
                        $arrayNew['properties'][] = [
                            'property' => 'complete_order_products_html',
                            'value' => woohubspotopr_get_complete_product_html($order)
                        ];
                        $array[] = $arrayNew;
                        $ids[] = $order->get_id();
                    }
                }
                var_dump($array);
                echo '<li>'.$ids.' - <em>';
                try {
                    $hubspot->instance->contacts()->createOrUpdateBatch($array);
                    echo 'Done';
                } catch (\Throwable $e) {
                    echo $e->getMessage();
                }
                echo '</em></li>';
            }
            $content = ob_get_clean();
            $file = "results.html";
            $txt = fopen($file, "w") or die("Unable to open file!");
            fwrite($txt, $content);
            fclose($txt);

            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename='.basename($file));
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            header("Content-Type: text/plain");
            readfile($file);
        }, 10, 1 );
    }

    function woohubspotopr_get_products_hubspot($order_id){
        //Require Composer
        require( WOOHUBSPOTOPR_PATH . '/vendor/autoload.php' );
        $hubspot = new WOOHUBSPOTOPR_HubSpot();
        woohubspotopr_get_products_html($order_id, $hubspot);
    }

    function woohubspotopr_get_products_html( $order_id, $hubspot ) {
        $order = wc_get_order( $order_id );
        if ( $order && method_exists($order, 'get_billing_email')) {
            $products_html = woohubspotopr_get_complete_product_html($order);

            try{
                $contact = $hubspot->instance->contacts()->getByEmail($order->get_billing_email(),['property'=>'hs_object_id']);
                $hubspot->instance->contacts()->update(
                    $contact->data->vid,
                    [['property' => 'complete_order_products_html', 'value' => $products_html]]
                );
                //echo 'Updated';
            } catch (\Throwable $e){
                //echo $e->getMessage();
            }
        }
    }

    function woohubspotopr_get_complete_product_html($order){

        $products_html = '<div><hr style="background-color: #f2f2f2; height: 1px; border: 0;"></div><!--[if mso]><center><table width="100%" style="width:500px;"><![endif]--><table style="font-size: 14px; font-family: Arial, sans-serif; line-height: 20px; text-align: left; table-layout: fixed;" width="100%"><thead><tr><th style="text-align: left;word-wrap: unset;"><span style="display: none;">' . __( 'Image', 'makewebbetter-hubspot-for-woocommerce' ) . '</span></th><th style="text-align: left;word-wrap: unset;"><span style="display: none;">' . __( 'Item', 'makewebbetter-hubspot-for-woocommerce' ) . '</span></th><th><span style="display: none;">' . __( 'Feedback', 'makewebbetter-hubspot-for-woocommerce' ) . '</span></th></tr></thead><tbody>';
        $products_list_html = '';
        foreach ( $order->get_items() as $item_id => $item ) {
            $_product = wc_get_product( $item->get_product_id() );
            if ( empty( $_product ) ) {
                continue;
            }
            $image = wp_get_attachment_image_src( get_post_thumbnail_id($item->get_product_id()), 'thumbnail' );
            if($image):
                $img = $image[0];
            else:
                $img = wc_placeholder_img_src();
            endif;
            $products_list_html .= '<tr><td width="20" style="max-width: 100%; text-align: left;padding: 10px; text-align: center;"><img height="80" width="80" src="' . $img . '"></td><td width="50" style="max-width: 100%; text-align: left; font-weight: normal;font-size: 10px;word-wrap: unset;"><a style="display: inline-block; text-decoration: none; font-size: 14px; color: #000; font-weight: bold;" target="_blank" href="' . get_permalink( $item->get_product_id() ) . '#review-section">' . $item->get_name() . '</a></td><td><a href="'.get_permalink( $item->get_product_id() ).'#review-section" style="padding: 10px; color: #000; border: 1px solid #000; text-decoration: none; display: block;
            text-align: center;">Write a Review</a></td></tr>';
        }

        $products_html .= $products_list_html;
        $products_html .= '</tbody></table><!--[if mso]></table></center><![endif]--><div><hr style="background-color: #f2f2f2; height: 1px; border: 0;"></div>';
        if($products_list_html != ''){
            return $products_html;
        }
        return '';
    }
}