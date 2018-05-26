<?php

if (!defined('EVENT_ESPRESSO_VERSION')) {
    exit('NO direct script access allowed');
}

/**
 * ----------------------------------------------
 * Class  EEG_VoguePay
 *
 * @package			Event Espresso
 * @author			Oluwafemi Fagbemi <fems.david@hotmail.com>
 * @version		 	1.1.0
 *
 * ----------------------------------------------
 */
class EEG_VoguePay extends EE_Offsite_Gateway {

    /**
     * Merchant API Username.
     *  @var string
     */
    protected $_api_username;

    /**
     * Merchant ID.
     *  @var string
     */
    protected $_api_merchantid;

    /**
     * Merchant API Password.
     *  @var string
     */
    protected $_api_password;

    /**
     * API Signature.
     *  @var string
     */
    protected $_api_signature;

    /**
     * Request Shipping address on checkout page.
     *  @var string
     */
    protected $_request_shipping_addr;

    /**
     * Business/personal logo.
     *  @var string
     */
    protected $_image_url;

    /**
     * gateway URL variable
     *
     * @var string
     */
    protected $_base_gateway_url = '';

    /**
     * EEG_Paypal_Express constructor.
     */
    public function __construct() {
        $this->_currencies_supported = array(
            'USD',
            'AUD',
            'BRL',
            'CAD',
            'CZK',
            'DKK',
            'EUR',
            'HKD',
            'HUF',
            'ILS',
            'JPY',
            'MYR',
            'MXN',
            'NOK',
            'NZD',
            'PHP',
            'PLN',
            'GBP',
            'RUB',
            'SGD',
            'SEK',
            'CHF',
            'TWD',
            'THB',
            'TRY',
            'NGN'
        );
        parent::__construct();
    }

    /**
     * Sets the gateway URL variable based on whether debug mode is enabled or not.

     *
     * @param array $settings_array
     */
    public function set_settings($settings_array) {
        parent::set_settings($settings_array);
        // Redirect URL.
        $this->_uses_separate_IPN_request = false;
        $this->_base_gateway_url = 'https://voguepay.com/pay/';
    }

    /**
     * @param EEI_Payment $payment
     * @param array       $billing_info
     * @param string      $return_url
     * @param string      $notify_url
     * @param string      $cancel_url
     * @return \EE_Payment|\EEI_Payment
     * @throws \EE_Error
     */
    public function set_redirection_info($payment, $billing_info = array(), $return_url = NULL, $notify_url = NULL, $cancel_url = NULL) {
        //die($return_url);
        if (!$payment instanceof EEI_Payment) {
            $payment->set_gateway_response(__('Error. No associated payment was found.', 'event_espresso'));
            $payment->set_status($this->_pay_model->failed_status());
            return $payment;
        }
        $transaction = $payment->transaction();
        if (!$transaction instanceof EEI_Transaction) {
            $payment->set_gateway_response(__('Could not process this payment because it has no associated transaction.', 'event_espresso'));
            $payment->set_status($this->_pay_model->failed_status());
            return $payment;
        }
        $order_description = substr($this->_format_order_description($payment), 0, 127);
        $primary_registration = $transaction->primary_registration();
        $primary_attendee = $primary_registration instanceof EE_Registration ? $primary_registration->attendee() : false;
        $locale = explode('-', get_bloginfo('language'));

        // Gather request parameters.
        $token_request_dtls = array();


        // Show itemized list.
        if ($this->_money->compare_floats($payment->amount(), $transaction->total(), '==')) {
            $item_num = 1;
            $itemized_sum = 0;
            $total_line_items = $transaction->total_line_item();
            // Go through each item in the list.
            foreach ($total_line_items->get_items() as $line_item) {
                if ($line_item instanceof EE_Line_Item) {
                    // PayPal doesn't like line items with 0.00 amount, so we may skip those.
                    if (EEH_Money::compare_floats($line_item->total(), '0.00', '==')) {
                        continue;
                    }

                    $unit_price = $line_item->unit_price();
                    $line_item_quantity = $line_item->quantity();
                    // This is a discount.
                    if ($line_item->is_percent()) {
                        $unit_price = $line_item->total();
                        $line_item_quantity = 1;
                    }
                    // Item Name.
                    $token_request_dtls['item_' . $item_num] = substr($this->_format_line_item_name($line_item, $payment), 0, 127);
                    // Item description.
                    $token_request_dtls['description_' . $item_num] = substr($this->_format_line_item_desc($line_item, $payment), 0, 127);
                    // Cost of individual item.
                    $token_request_dtls['price_' . $item_num] = $this->format_currency($unit_price);
                    $itemized_sum += $line_item->total();
                    ++$item_num;
                }
            }


            $itemized_sum_diff_from_txn_total = round($transaction->total() - $itemized_sum - $total_line_items->get_total_tax(), 2);
            // If we were not able to recognize some item like promotion, surcharge or cancellation,
            // add the difference as an extra line item.
            if ($this->_money->compare_floats($itemized_sum_diff_from_txn_total, 0, '!=')) {
                // Item Name.
                $token_request_dtls['item_' . $item_num] = substr(__('Other (promotion/surcharge/cancellation)', 'event_espresso'), 0, 127);
                // Item description.
                $token_request_dtls['description_' . $item_num] = '';
                // Cost of individual item.
                $token_request_dtls['price_' . $item_num] = $this->format_currency($itemized_sum_diff_from_txn_total);
                $item_num++;
            }
        } else {
            // Just one Item.
            // Item Name.
            $token_request_dtls['item_1'] = substr($this->_format_partial_payment_line_item_name($payment), 0, 127);
            // Item description.
            $token_request_dtls['description_1'] = substr($this->_format_partial_payment_line_item_desc($payment), 0, 127);
            // Cost of individual item.
            $token_request_dtls['price_1'] = $this->format_currency($payment->amount());
        }


        $token_request_dtls = apply_filters(
                'FHEE__EEG_VoguePay__set_redirection_info__arguments', $token_request_dtls, $this
        );

        

        $merchantRef = uniqid(str_replace(".", "", date('mdHis')), true);
        $params = array(
            "v_merchant_id" => $this->_api_merchantid,
            "merchant_ref" => $merchantRef,
            "memo" => $order_description,
            //"notify_url" => $notify_url,
            "success_url" => $return_url,
            "fail_url" => $return_url,
            "developer_code" => "59a1d79e27ca7",
            "total" => $payment->amount(),
            "cur" => $payment->currency_code(),
        );



        $payment->set_redirect_args($params);
        $payment->set_redirect_url($this->_base_gateway_url);


        return $payment;
    }

    /**

     *  @param array $update_info {
     * 	  @type string $gateway_txn_id
     * 	  @type string status an EEMI_Payment status
     *  }
     *  @param EEI_Transaction $transaction
     *  @return EEI_Payment
     */
    public function handle_payment_update($update_info, $transaction) {



        $payment = $transaction instanceof EEI_Transaction ? $transaction->last_payment() : null;


        if ($payment instanceof EEI_Payment) {
            $this->log(array('Return from VoguePay' => $update_info), $payment);
            $transaction = $payment->transaction();
            if (!$transaction instanceof EEI_Transaction) {
                $payment->set_gateway_response(__('Could not process this payment because it has no associated transaction.', 'event_espresso'));
                $payment->set_status($this->_pay_model->failed_status());
                return $payment;
            }




            $primary_registrant = $transaction->primary_registration();
            $payment_details = $payment->details();



            $docheckout_request_dtls = array(
                'v_transaction_id' => $update_info["transaction_id"],
                'type' => 'json',
            );
            if($this->_debug_mode){
               $docheckout_request_dtls["demo"]="true"; 
            }

            // Payment Checkout/Capture.
            $docheckout_request_response = $this->_VoguePaycheckTransactionStatusRequest($docheckout_request_dtls, 'Transaction Status Verification', $payment);
           
            //mail("impactenabled@gmail.com", "Gateway Response", print_r($docheckout_request_response,1));
            
            $docheckout_rstatus = $this->_VoguePaycheckTransactionStatusResponse($docheckout_request_response);
            
            
            $docheckout_response_args = ( isset($docheckout_rstatus['args']) && is_array($docheckout_rstatus['args']) ) ? $docheckout_rstatus['args'] : array();
            if ($docheckout_rstatus['status'] && $docheckout_response_args["status"] == "Approved") {
                // All is well, payment approved.
                //check if this user actually pay the required amount
                if ($this->_money->compare_floats(((float) $payment->amount()), ((float) $docheckout_response_args["total"]), '!=')) {
                    $payment->set_status($this->_pay_model->declined_status());
                    return $payment;
                }

                $primary_registration_code = $primary_registrant instanceof EE_Registration ? $primary_registrant->reg_code() : '';
                $payment->set_extra_accntng($primary_registration_code);
                $payment->set_amount(isset($docheckout_response_args['total']) ? (float) $docheckout_response_args['total'] : 0 );
                $payment->add_extra_meta("voguepay_transaction_id", $docheckout_response_args['transaction_id'], true);
                $payment->set_gateway_response(isset($docheckout_response_args['status']) ? $docheckout_response_args['status'] : '' );
                $payment->set_status($this->_pay_model->approved_status());
            } else {
                if (isset($docheckout_response_args['status'])) {
                    $payment->set_gateway_response($docheckout_response_args['status']);
                } else {
                    $payment->set_gateway_response(__('Error occurred while trying to Capture the funds.', 'event_espresso'));
                }

                switch ($docheckout_response_args['status']) {
                    case "Failed":
                        $payment->set_status($this->_pay_model->failed_status());
                        break;
                    case "Pending":
                        $payment->set_status($this->_pay_model->pending_status());
                        break;
                    case "Disputed";
                        $payment->set_status($this->_pay_model->declined_status());
                        break;
                    default:
                        $payment->set_status($this->_pay_model->failed_status());
                }
            }
            $payment->set_details($docheckout_response_args);
        } else {
            $payment->set_gateway_response(__('Error occurred while trying to process the payment.', 'event_espresso'));
        }

        return $payment;
    }

    /**
     *  Make the Express checkout request.
     *
     * 	@param array        $request_params
     * 	@param string       $request_text
     *  @param EEI_Payment  $payment
     * 	@return mixed
     */
    public function _VoguePaycheckTransactionStatusRequest($request_params, $request_text, $payment) {

        $dtls = $request_params;

        $this->_log_clean_request($dtls, $payment, $request_text . ' Request');
        // Request Transaction Status.

        $response = wp_remote_get("https://voguepay.com/?" . http_build_query($dtls));
        $this->log(array("_VoguePaycheckTransactionStatusRequest" => $response), $payment);
        return json_decode($response,TRUE);
    }

    /**
     *  Check the response status.
     *
     * 	@param mixed        $request_response
     * 	@return array
     */
    public function _VoguePaycheckTransactionStatusResponse($request_response) {
        if (empty($request_response)) {
            // If we got here then there was an error in this request.
            return array('status' => false, 'args' => $request_response);
        }

        if (isset($request_response['merchant_id']) && isset($request_response['transaction_id']) && isset($request_response['status'])
        ) {
            // Response status OK, return response parameters for further processing.
            return array('status' => true, 'args' => $request_response);
        }
        return array('status' => false, 'args' => $request_response);
    }

    /**
     *  Log a "Cleared" request.
     *
     * @param array $request
     * @param EEI_Payment  $payment
     * @param string  		$info
     * @return void
     */
    private function _log_clean_request($request, $payment, $info) {
        $cleaned_request_data = $request;
        unset($cleaned_request_data['PWD'], $cleaned_request_data['USER'], $cleaned_request_data['SIGNATURE']);
        $this->log(array($info => $cleaned_request_data), $payment);
    }

}

