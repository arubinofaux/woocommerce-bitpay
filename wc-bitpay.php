<?php
/**
 * Plugin Name: WooCommerce BitPay
 * Plugin URI: http://claudiosmweb.com/
 * Description: WooCommerce BitPay is a bitcoin payment gateway for WooCommerce
 * Author: claudiosanches
 * Author URI: http://claudiosmweb.com/
 * Version: 1.0
 * License: GPLv2 or later
 * Text Domain: wcbitpay
 * Domain Path: /languages/
 */

/**
 * WooCommerce fallback notice.
 */
function wcbitpay_woocommerce_fallback_notice() {
    $message = '<div class="error">';
        $message .= '<p>' . __( 'WooCommerce BitPay Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'wcbitpay' ) . '</p>';
    $message .= '</div>';

    echo $message;
}

/**
 * Load functions.
 */
add_action( 'plugins_loaded', 'wcbitpay_gateway_load', 0 );

function wcbitpay_gateway_load() {

    if ( !class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'wcbitpay_woocommerce_fallback_notice' );

        return;
    }

    /**
     * Load textdomain.
     */
    load_plugin_textdomain( 'wcbitpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    /**
     * Add the gateway to WooCommerce.
     *
     * @access public
     * @param array $methods
     * @return array
     */
    add_filter( 'woocommerce_payment_gateways', 'wcbitpay_add_gateway' );

    function wcbitpay_add_gateway( $methods ) {
        $methods[] = 'WC_BitPay_Gateway';
        return $methods;
    }

    /**
     * WC BitPay Gateway Class.
     *
     * Built the BitPay method.
     */
    class WC_BitPay_Gateway extends WC_Payment_Gateway {

        /**
         * Gateway's Constructor.
         *
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id                  = 'bitpay';
            $this->icon                = plugins_url( 'images/bitcoin.png', __FILE__ );
            $this->has_fields          = false;
            $this->invoice_url         = 'https://bitpay.com/api/invoice';
            $this->method_title        = __( 'BitPay', 'wcbitpay' );

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user setting variables.
            $this->title              = $this->settings['title'];
            $this->description        = $this->settings['description'];
            $this->api_key            = $this->settings['api_key'];
            $this->notification_email = $this->settings['notification_email'];
            $this->invoice_prefix     = !empty( $this->settings['invoice_prefix'] ) ? $this->settings['invoice_prefix'] : 'WC-';
            $this->debug              = $this->settings['debug'];

            // Actions.
            add_action( 'init', array( &$this, 'check_ipn_response' ) );
            add_action( 'valid_bitpay_ipn_request', array( &$this, 'successful_request' ) );
            add_action( 'woocommerce_receipt_bitpay', array( &$this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );

            // Valid for use.
            $this->enabled = ( 'yes' == $this->settings['enabled'] ) && !empty( $this->api_key ) && $this->is_valid_for_use();

            // Checking if api_key is not empty.
            $this->api_key == '' ? add_action( 'admin_notices', array( &$this, 'api_key_missing_message' ) ) : '';

            // Active logs.
            if ( $this->debug == 'yes' ) {
                $this->log = $woocommerce->logger();
            }
        }

        /**
         * Checking if this gateway is enabled and available in the user's currency.
         *
         * @return bool
         */
        public function is_valid_for_use() {

            $supported_currencies = array(
                'USD', 'EUR', 'GBP', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CNY',
                'CZK', 'DKK', 'HKD', 'HRK', 'HUF', 'IDR', 'ILS', 'INR', 'JPY',
                'KRW', 'LTL', 'LVL', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN',
                'RON', 'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'ZAR', 'BTC'
            );

            if ( !in_array( get_woocommerce_currency(), $supported_currencies ) ) {
                return false;
            }

            return true;
        }

        /**
         * Admin Panel Options
         *
         * @return Admin option form.
         */
        public function admin_options() {
            ?>
            <h3><?php _e( 'BitPay standard', 'wcbitpay' ); ?></h3>
            <p><?php _e( 'BitPay standard displays a BitPay button with payment information in Bitcoin.', 'wcbitpay' ); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
         * Start Gateway Settings Form Fields.
         *
         * @return array Form fields.
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'wcbitpay' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable BitPay standard', 'wcbitpay' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __( 'Title', 'wcbitpay' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'wcbitpay' ),
                    'default' => __( 'BitPay', 'wcbitpay' )
                ),
                'description' => array(
                    'title' => __( 'Description', 'wcbitpay' ),
                    'type' => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'wcbitpay' ),
                    'default' => __( 'Pay with Bitcoin', 'wcbitpay' )
                ),
                'api_key' => array(
                    'title' => __( 'API Key ID', 'wcbitpay' ),
                    'type' => 'text',
                    'description' => __( 'Please enter your BitPay API Key ID.', 'wcbitpay' ) . ' ' . sprintf( __( 'You can to get this information in: %sBitPay Account%s.', 'wcbitpay' ), '<a href="https://bitpay.com/api-keys" target="_blank">', '</a>' ),
                    'default' => ''
                ),
                'notification_email' => array(
                    'title' => __( 'Notification email', 'wcbitpay' ),
                    'type' => 'text',
                    'description' => __( 'BitPay will send an email to this email address when the invoice status changes.', 'wcbitpay' ),
                    'default' => ''
                ),
                'invoice_prefix' => array(
                    'title' => __( 'Invoice Prefix', 'wcbitpay' ),
                    'type' => 'text',
                    'description' => __( 'Please enter a prefix for your invoice numbers. If you use your BitPay account for multiple stores ensure this prefix is unqiue as BitPay will not allow orders with the same invoice number.', 'wcbitpay' ),
                    'default' => 'WC-'
                ),
                'testing' => array(
                    'title' => __( 'Gateway Testing', 'wcbitpay' ),
                    'type' => 'title',
                    'description' => '',
                ),
                'debug' => array(
                    'title' => __( 'Debug Log', 'wcbitpay' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable logging', 'wcbitpay' ),
                    'default' => 'no',
                    'description' => __( 'Log BitPay events, such as API requests, inside <code>woocommerce/logs/bitpay.txt</code>', 'wcbitpay'  ),
                )
            );
        }

        /**
         * Generate the args to form.
         *
         * @param  array $order Order data.
         * @return array
         */
        public function get_form_args( $order ) {

            $invoice = $this->invoice_prefix . $order->id;

            $args = array(
                'price'             => (float) $order->order_total,
                'currency'          => get_woocommerce_currency(),
                'posData'           => '{"posData": "' . $invoice . '", "hash": "'
                                        . crypt( $invoice, $this->api_key ) . '"}',
                'orderID'           => $order->id,
                'redirectURL'       => esc_url( $this->get_return_url( $order ) )
            );

            if ( is_ssl() ) {
                $args['notificationURL'] = str_replace( 'http:', 'https:', get_permalink( woocommerce_get_page_id( 'pay' ) ) );
            }

            if ( $this->notification_email ) {
                $args['notificationEmail'] = $this->notification_email;
            }

            $args = apply_filters( 'woocommerce_bitpay_args', $args );

            return $args;
        }

        /**
         * Generate the form.
         *
         * @param mixed $order_id
         * @return string
         */
        public function generate_form( $order_id ) {

            $order = new WC_Order( $order_id );

            $args = $this->get_form_args( $order );

            if ( $this->debug == 'yes' ) {
                $this->log->add( 'bitpay', 'Payment arguments for order #' . $order_id . ': ' . print_r( $args, true ) );
            }

            $details = $this->create_invoice( $args );

            if ( $details ) {

                // Displays BitPay iframe.
                $html = '<iframe src="' . $details->url . '&view=iframe" style="display: block; border: none; margin: 0 auto 25px; width: 500px;"></iframe>';

                $html .= '<a id="submit-payment" href="' . $args['redirectURL'] . '" class="button alt">' . __( 'Payment done, close the order', 'wcbitpay' ) . '</a> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', 'wcbitpay' ) . '</a>';

                if ( $this->debug == 'yes' ) {
                    $this->log->add( 'bitpay', 'Payment link generated with success from BitPay' );
                }

                // Register order details.
                update_post_meta( $order->id, 'BitPay ID', esc_attr( $details->id ) );
                update_post_meta( $order->id, 'BTC Price', esc_attr( $details->btcPrice ) );

                return $html;

            } else {
                if ( $this->debug == 'yes' ) {
                    $this->log->add( 'bitpay', 'Set details error.' );
                }

                return $this->btc_order_error( $order );
            }

        }

        /**
         * Order error button.
         *
         * @param  object $order Order data.
         *
         * @return string        Error message and cancel button.
         */
        protected function btc_order_error( $order ) {

            // Display message if there is problem.
            $html = '<p>' . __( 'An error has occurred while processing your payment, please try again. Or contact us for assistance.', 'wcbitpay' ) . '</p>';

            $html .='<a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Click to try again', 'wcbitpay' ) . '</a>';

            return $html;
        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = new WC_Order( $order_id );

            return array(
                'result'    => 'success',
                'redirect'  => add_query_arg( 'order', $order->id, add_query_arg( 'key', $order->order_key, get_permalink( woocommerce_get_page_id( 'pay' ) ) ) )
            );
        }

        /**
         * Output for the order received page.
         *
         * @return void
         */
        public function receipt_page( $order ) {
            echo $this->generate_form( $order );
        }

        /**
         * Create order invoice.
         *
         * @param  array $args Order argumments.
         *
         * @return mixed       Object with order details or false.
         */
        public function create_invoice( $args ) {

            // Built wp_remote_post params.
            $params = array(
                'body'       => json_encode( $args ),
                'method'     => 'POST',
                'sslverify'  => false,
                'timeout'    => 30,
                'headers'    => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode( $this->api_key )
                )
            );

            $response = wp_remote_post( $this->invoice_url, $params );

            // Log response.
            if ( $this->debug == 'yes' && isset( $response['body'] ) ) {
                $this->log->add( 'bitpay', 'BitPay Server Response:' . print_r( json_decode( $response['body'] ), true ) );
            }

            // Check to see if the request was valid.
            if ( !is_wp_error( $response ) && $response['response']['code'] == 200 ) {

                return json_decode( $response['body'] );
            }

            return false;
        }

        /**
         * Check ipn validity
         *
         * @param  array $post $_POST data.
         *
         * @return mixed       Array with post data or false.
         */
        public function check_ipn_request_is_valid( $post ) {

            $json = json_decode( $post, true );

            if ( is_string( $json ) || !array_key_exists( 'posData', $json ) ) {
                if ( $this->debug == 'yes' ) {
                    $this->log->add( 'bitpay', 'Authentication Failed - Bad Data:' . print_r( $post , true ) );
                }

                return false;
            }

            $data = json_decode( $json['posData'], true );

            if( $data['hash'] != crypt( $data['posData'], $this->api_key ) ) {
                if ( $this->debug == 'yes' ) {
                    $this->log->add( 'bitpay', 'Authentication Failed - Bad Hash!' );
                }

                return false;
            }

            if ( $this->debug == 'yes' ) {
                $this->log->add( 'bitpay', 'Received valid posData from BitPay' );
            }

            $json['posData'] = $data['posData'];

            return $json;
        }

        /**
         * Check API Response.
         *
         * @return void
         */
        public function check_ipn_response() {

            if ( is_ssl() ) {

                if ( isset( $_POST['posData'] ) ) {

                    @ob_clean();

                    $posted = stripslashes_deep( $_POST );

                    if ( $this->check_ipn_request_is_valid() ) {

                        header( 'HTTP/1.0 200 OK' );

                        do_action( 'valid_bitpay_ipn_request', $posted );

                    } else {

                        wp_die( __( 'Request Failure', 'wcbitpay' ) );

                    }
                }
            }
        }

        /**
         * Successful Payment!
         *
         * @param array $posted IPN data.
         *
         * @return void
         */
        public function successful_request( $posted ) {

            if ( !empty( $posted['posData'] ) ) {
                $order_key = $posted['posData']['posData'];
                $order_id = (int) str_replace( $this->invoice_prefix, '', $order_key );

                $order = new WC_Order( $order_id );

                // Checks whether the invoice number matches the order.
                // If true processes the payment.
                if ( $order->id === $order_id ) {

                    if ( $this->debug == 'yes' ) {
                        $this->log->add( 'bitpay', 'Payment status from order #' . $order->id . ': ' . $posted['status'] );
                    }

                    switch ( $posted['status'] ) {
                        case 'confirmed':
                            $order->add_order_note( __( 'Payment confirmed by BitPay.', 'wcbitpay' ) );
                            // Changing the order for processing and reduces the stock.
                            $order->payment_complete();

                            break;
                        case 'complete':
                            $order->add_order_note( __( 'Payment complete in BitPay.', 'wcbitpay' ) );

                            break;
                        case 'expired':
                            $order->update_status( 'cancelled', __( 'Payment expired.', 'wcbitpay' ) );

                            break;
                        case 'invalid':
                            $order->update_status( 'cancelled', __( 'Payment canceled by BitPay.', 'wcbitpay' ) );

                            break;

                        default:
                            // No action xD.
                            break;
                    }
                }
            }
        }

        /**
         * Adds error message when not configured the api_key.
         *
         * @return string Error Mensage.
         */
        public function api_key_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should inform your API Key ID of BitPay. %sClick here to configure!%s' , 'wcbitpay' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

    } // class WC_BitPay_Gateway.
} // function wcbitpay_gateway_load.
