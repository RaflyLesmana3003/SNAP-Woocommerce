<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * WC_Gateway_Midtrans_Abstract class.
 *
 * @extends WC_Payment_Gateway
 */
abstract class WC_Gateway_Midtrans_Abstract extends WC_Payment_Gateway {    
  /**
   * Constructor
   */
  public function __construct() {
    $this->has_fields   = true;
    $this->icon         = apply_filters( 'woocommerce_midtrans_icon', '' );
    // Load the settings
    $this->init_form_fields();
    $this->init_settings();
    $this->supports = array(
      'products',
      'refunds'
    );
    // Get Settings
    $this->title              = $this->get_option( 'title' );
    $this->description        = $this->get_option( 'description' );
    $this->environment        = $this->get_option( 'select_midtrans_environment' );
    $this->client_key = ($this->environment == 'production') ? $this->get_option( 'client_key_v2_production' ) : $this->get_option( 'client_key_v2_sandbox' );
    $this->server_key = ($this->environment == 'production') ? $this->get_option( 'server_key_v2_production' ) : $this->get_option( 'server_key_v2_sandbox' );
    $this->enable_3d_secure   = $this->get_option( 'enable_3d_secure' );
    $this->enable_savecard   = $this->get_option( 'enable_savecard' );
    $this->enable_redirect   = $this->get_option( 'enable_redirect' );
    $this->ignore_pending_status   = $this->get_option( 'ignore_pending_status' );
    $this->custom_expiry   = $this->get_option( 'custom_expiry' );
    $this->custom_fields   = $this->get_option( 'custom_fields' );
    $this->enable_map_finish_url   = $this->get_option( 'enable_map_finish_url' );
    $this->ganalytics_id   = $this->get_option( 'ganalytics_id' );
    $this->enable_immediate_reduce_stock   = $this->get_option( 'enable_immediate_reduce_stock' );
    $this->to_idr_rate = apply_filters( 'midtrans_to_idr_rate', $this->get_option( 'to_idr_rate' ));
    $this->log = new WC_Logger();

    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
    
    // Hook for adding JS script to admin config page 
    add_action( 'admin_print_scripts-woocommerce_page_woocommerce_settings', array( &$this, 'midtrans_admin_scripts' ));
    add_action( 'admin_print_scripts-woocommerce_page_wc-settings', array( &$this, 'midtrans_admin_scripts' ));
    // Hook for adding custom HTML on thank you page (for payement/instruction url)
    add_action( 'woocommerce_thankyou', array( $this, 'view_order_and_thankyou_page' ) );
    // Hook for adding custom HTML on view order menu from customer (for payement/instruction url)
    add_action( 'woocommerce_view_order', array( $this, 'view_order_and_thankyou_page' ) );
    // Hook for refund request from Midtrans Dashboard or API refund
    add_action( 'create-refund-request',  array( $this, 'midtrans_refund' ), 10, 4 );
    // Custom hook to customize rate convertion
    add_filter('midtrans_to_idr_rate', function ($midtrans_rate) {
      return $midtrans_rate;
    });
    $this->id != 'midtrans' ?: new WC_Gateway_Midtrans_Notif_Handler( $this->environment, $this->server_key );
  }

  /**
   * Enqueue Javascripts
   * Add JS script file to admin page
  */
  public function midtrans_admin_scripts() {
    wp_enqueue_script( 'admin-midtrans', MIDTRANS_PLUGIN_DIR . 'js/admin-scripts.js', array('jquery') );
  }

  /**
   * Initialise Gateway Settings Form Fields
   */
  public function init_form_fields() {
    $this->form_fields = require( dirname(__FILE__) . '/../class/midtrans-admin-settings.php' );
    // Currency conversion rate if currency is not IDR
    if (get_woocommerce_currency() != 'IDR') {
      $this->form_fields['to_idr_rate'] = array(
        'title' => __("Current Currency to IDR Rate", 'midtrans-woocommerce'),
        'type' => 'text',
        'description' => 'The current currency to IDR rate',
        'default' => '10000',
      );
    }
  }

  /**
   * Hook function that will be called on thank you page
   * Output HTML for payment/instruction URL
   * @param  string $order_id generated by WC
   * @return string HTML
   */
  public function view_order_and_thankyou_page( $order_id ) {
    require_once( dirname(__FILE__) . '/../class/order-view-and-thankyou-page.php');
  }

  /**
   * Refund a charge
   * @param  int $order_id
   * @param  float $amount
   * @return bool
   */
  public function process_refund($order_id, $amount = null, $reason = '') {
    $order = wc_get_order( $order_id );
    $refundResponse = $this->refund( $order, $order_id, $amount, $reason );

    if ($refundResponse == '200') return true;
    else {
      $this->setLogError($refundResponse);
      return new WP_Error( 'midtrans_refund_error', $refundResponse);
    }
  }

  /**
   * Process a payment object refund
   *
   * @param WC_Order $order
   * @param int $order_id
   * @param int $amount
   * @param null $amount
   * @param string $reason
   * @return bool|\WP_Error
   */
  public function refund( $order, $order_id, $amount, $reason ) {
    $refund_params = array(
      'refund_key' => 'RefundID' . $order_id . '-' . current_time('timestamp'),
      'amount' => $amount,
      'reason' => $reason
    );

    try {
      $response = WC_Midtrans_API::createRefund($order_id, $refund_params, $this->id);
    } catch (Exception $e) {
      $this->setLogError( $e->getMessage() );
      $error_message = strpos($e->getMessage(), '412') ? $e->getMessage() . ' Note: Refund via Midtrans only for specific payment method, please consult to your midtrans PIC for more information' : $e->getMessage();
      return $error_message;
    }

    if ($response->status_code == 200) {
      $refund_message = sprintf(__('Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-midtrans'), wc_price($response->refund_amount), $response->refund_key, $reason);
      $order->add_order_note($refund_message);
      return $response->status_code;
    }
  }

  /**
   * Plugin config and cart/order properties are used as params
   * 
   * @param $order_id
   * @return object $params
   */
  public function getPaymentRequestData( $order_id ) {
    $order = new WC_Order( $order_id );
    $params = array(
    'transaction_details' => array(
      'order_id' => $order_id,
      'gross_amount' => 0,
    ));

    $customer_details = array();
    $customer_details['first_name'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_first_name');
    $customer_details['first_name'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_first_name');
    $customer_details['last_name'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_last_name');
    $customer_details['email'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_email');
    $customer_details['phone'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_phone');

    $billing_address = array();
    $billing_address['first_name'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_first_name');
    $billing_address['last_name'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_last_name');
    $billing_address['address'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_address_1');
    $billing_address['city'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_city');
    $billing_address['postal_code'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_postcode');
    $billing_address['phone'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_phone');
    $converted_country_code = WC_Midtrans_Utils::convert_country_code(WC_Midtrans_Utils::getOrderProperty($order,'billing_country'));
    $billing_address['country_code'] = (strlen($converted_country_code) != 3 ) ? 'IDN' : $converted_country_code ;

    $customer_details['billing_address'] = $billing_address;
    $customer_details['shipping_address'] = $billing_address;

    if ( isset ( $_POST['ship_to_different_address'] ) ) {
      $shipping_address = array();
      $shipping_address['first_name'] = WC_Midtrans_Utils::getOrderProperty($order,'shipping_first_name');
      $shipping_address['last_name'] = WC_Midtrans_Utils::getOrderProperty($order,'shipping_last_name');
      $shipping_address['address'] = WC_Midtrans_Utils::getOrderProperty($order,'shipping_address_1');
      $shipping_address['city'] = WC_Midtrans_Utils::getOrderProperty($order,'shipping_city');
      $shipping_address['postal_code'] = WC_Midtrans_Utils::getOrderProperty($order,'shipping_postcode');
      $shipping_address['phone'] = WC_Midtrans_Utils::getOrderProperty($order,'billing_phone');
      $converted_country_code = WC_Midtrans_Utils::convert_country_code(WC_Midtrans_Utils::getOrderProperty($order,'shipping_country'));
      $shipping_address['country_code'] = (strlen($converted_country_code) != 3 ) ? 'IDN' : $converted_country_code;
      $customer_details['shipping_address'] = $shipping_address;
    }
    
    $params['customer_details'] = $customer_details;
    $items = array();
    // Build item_details API params from $Order items
    if( sizeof( $order->get_items() ) > 0 ) {
      foreach( $order->get_items() as $item ) {
        if ( $item['qty'] ) {
          // $product = $order->get_product_from_item( $item );
          $midtrans_item = array();
          $midtrans_item['id']    = $item['product_id'];
          $midtrans_item['price']      = ceil($order->get_item_subtotal( $item, false ));
          $midtrans_item['quantity']   = $item['qty'];
          $midtrans_item['name'] = $item['name'];
          $items[] = $midtrans_item;
        }
      }
    }

    // Shipping fee as item_details
    if( $order->get_total_shipping() > 0 ) {
      $items[] = array(
        'id' => 'shippingfee',
        'price' => ceil($order->get_total_shipping()),
        'quantity' => 1,
        'name' => 'Shipping Fee',
      );
    }

    // Tax as item_details
    if( $order->get_total_tax() > 0 ) {
      $items[] = array(
        'id' => 'taxfee',
        'price' => ceil($order->get_total_tax()),
        'quantity' => 1,
        'name' => 'Tax',
      );
    }

    // Discount as item_details
    if ( $order->get_total_discount() > 0) {
      $items[] = array(
        'id' => 'totaldiscount',
        'price' => ceil($order->get_total_discount())  * -1,
        'quantity' => 1,
        'name' => 'Total Discount'
      );
    }

    // Fees as item_details
    if ( sizeof( $order->get_fees() ) > 0 ) {
      $fees = $order->get_fees();
      $i = 0;
      foreach( $fees as $item ) {
        $items[] = array(
          'id' => 'itemfee' . $i,
          'price' => ceil($item['line_total']),
          'quantity' => 1,
          'name' => $item['name'],
        );
        $i++;
      }
    }

    // Iterate through the entire item to ensure that currency conversion is applied
    if (get_woocommerce_currency() != 'IDR'){
      foreach ($items as &$item) {
        $item['price'] = $item['price'] * $this->to_idr_rate;
        $item['price'] = intval($item['price']);
      }
      unset($item);
      $params['transaction_details']['gross_amount'] *= $this->to_idr_rate;
    }

    $total_amount=0;
    // error_log('print r items[]' . print_r($items,true)); //debugan
    // Sum item details prices as gross_amount
    foreach ($items as $item) {
      $total_amount+=($item['price']*$item['quantity']);
    }
    $params['transaction_details']['gross_amount'] = $total_amount;
    $params['item_details'] = $items;
    $params['credit_card']['secure'] = ($this->enable_3d_secure == 'yes') ? true : false;

    // add custom `expiry` API params
    $custom_expiry_params = explode(" ",$this->custom_expiry);
    if ( !empty($custom_expiry_params[1]) && !empty($custom_expiry_params[0]) ){
      $time = time();
      $time += 30; // add 30 seconds to allow margin of error
      $params['expiry'] = array(
        'start_time' => date("Y-m-d H:i:s O",$time), 
        'unit' => $custom_expiry_params[1], 
        'duration'  => (int)$custom_expiry_params[0],
      );
    }
    // add custom_fields API params
    $custom_fields_params = explode(",",$this->custom_fields);
    if ( !empty($custom_fields_params[0]) ){
      $params['custom_field1'] = $custom_fields_params[0];
      $params['custom_field2'] = !empty($custom_fields_params[1]) ? $custom_fields_params[1] : null;
      $params['custom_field3'] = !empty($custom_fields_params[2]) ? $custom_fields_params[2] : null;
    }
    // add savecard API params
    if ($this->enable_savecard =='yes' && is_user_logged_in()){
      $params['user_id'] = crypt( $customer_details['email'].$customer_details['phone'] , WC_Midtrans_API::get_server_key() );
      $params['credit_card']['save_card'] = true;
    }
    return $params;
  }

  /**
   * @param $order
   * @return array $successResponse
   */
  public function getResponseTemplate( $order ) {
    // Response object template
    $successResponse = array(
      'result'  => 'success',
      'redirect' => ''
    );

    // If snap token exists on the current $Order, reuse it
    // Prevent duplication of API call, which may throw API error
    if ($order->meta_exists('_mt_payment_snap_token')){
      $successResponse['redirect'] = $order->get_checkout_payment_url( true )."&snap_token=".$order->get_meta('_mt_payment_snap_token');
    }
      return $successResponse;
  }
  
  /**
   * Handle Midtrans Refund, when refund trigger not from woocommerce
   * @param  [int] $order_id
   * @param  [int] $refund_amount
   * @param  [string] $refund_reason
   * @param  [bool] $isFullRefund
   * @return WC_Order_Refund|WP_Error
   */
  public function midtrans_refund( $order_id, $refund_amount, $refund_reason, $isFullRefund = false ) {
    $order  = wc_get_order( $order_id );
    if( ! is_a( $order, 'WC_Order') ) {
      return;
    }
    // Prepare line items which we are refunding
    $line_items = array();

    if ($isFullRefund) {
      // Get Items
      $order_items = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
      if ( ! $order_items ) {
        $this->setLogError( 'Refund not from WC dashboard error, This order id'. $order_id .'has no items' );
        return new \WP_Error( 'wc-order', 'This order has no items' );
      }

      foreach ( $order_items as $item_id => $item ) {
        $line_total = $order->get_line_total( $item, false, false );
        $qty        = $item->get_quantity();
        $tax_data   = wc_get_order_item_meta( $item_id, '_line_tax_data' );

        $refund_tax = array();
        // Check if it's shipping costs. If so, get shipping taxes.
        if ( $item instanceof \WC_Order_Item_Shipping ) {
          $tax_data = wc_get_order_item_meta( $item_id, 'taxes' );
        }
        // If taxdata is set, format as decimal.
        if ( ! empty( $tax_data['total'] ) ) {
          $refund_tax = array_filter( array_map( 'wc_format_decimal', $tax_data['total'] ) );
        }
        // Calculate line total, including tax.
        $line_total_inc_tax = wc_format_decimal( $line_total ) + ( is_numeric( reset( $refund_tax ) ) ? wc_format_decimal( reset( $refund_tax ) ) : 0 );
        // Fill item per line.
        $line_items[ $item_id ] = array(
          'qty'          => $qty,
          'refund_total' => wc_format_decimal( $line_total ),
          'refund_tax'   => array_map( 'wc_round_tax_total', $refund_tax )
        );
      }
    }

    // Create refund
    $refund = wc_create_refund( array(
      'amount'         => $refund_amount,
      'reason'         => $refund_reason,
      'order_id'       => $order_id,
      'line_items'     => $line_items,
      'restock_items' => $isFullRefund
    ) );
    if ( is_wp_error( $refund ) ) throw new Exception($refund->get_error_message());
      return $refund;
  }

  public function setLogError( $message ) {
    WC_Midtrans_Logger::log( $message, 'midtrans-error', $this->id, current_time( 'timestamp' ) );
  }

  /**
   * @return string
   */
  abstract protected function getDefaultTitle ();

  /**
   * @return string
   */
  abstract protected function getDefaultDescription ();
}