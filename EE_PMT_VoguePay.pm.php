<?php

if (!defined('EVENT_ESPRESSO_VERSION')) {
    exit('NO direct script access allowed');
}

/**
 * ----------------------------------------------
 * Class  EE_PMT_VoguePay
 *
 * @package			Event Espresso
 * @author			Oluwafemi Fagbemi <fems.david@hotmail.com>
 * @version		 	1.1.0
 *
 * ----------------------------------------------
 */
class EE_PMT_VoguePay extends EE_PMT_Base {

    /**
     * Class constructor.
     */
    public function __construct($pm_instance = NULL) {
        require_once( $this->file_folder() . 'EEG_VoguePay.gateway.php' );
        $this->_gateway = new EEG_VoguePay();

        $this->_pretty_name = __('VoguePay', 'event_espresso');
        $this->_template_path = $this->file_folder() . 'templates' . DS;
        $this->_default_description = __('After clicking \'Finalize Registration\', you will be forwarded to VoguePay website to  make your payment.', 'event_espresso');
        $this->_default_button_url = $this->file_url() . 'lib' . DS . 'make_payment_blue.png';

        parent::__construct($pm_instance);
    }

    /**
     * Adds the help tab.
     *
     * @see EE_PMT_Base::help_tabs_config()
     * @return array
     */
    public function help_tabs_config() {
        return array(
            $this->get_help_tab_name() => array(
                'title' => __('VoguePay Settings', 'event_espresso'),
                'filename' => 'payment_methods_overview_voguepay'
            )
        );
    }

    /**
     * Gets the form for all the settings related to this payment method type.
     *
     * @return EE_Payment_Method_Form
     */
    public function generate_new_settings_form() {
        EE_Registry::instance()->load_helper('Template');
        $form = new EE_Payment_Method_Form(
                array(
            'extra_meta_inputs' => array(
                'api_merchantid' => new EE_Text_Input(
                        array(
                    'html_label_text' => sprintf(__('Merchant ID %s', 'event_espresso'), $this->get_help_tab_link()),
                    'required' => true,
                        )
                )
            )
                )
        );
        return $form;
    }

    /**
     * 	Creates a billing form for this payment method type.
     *
     * 	@param \EE_Transaction $transaction
     * 	@return \EE_Billing_Info_Form
     */
    public function generate_new_billing_form(EE_Transaction $transaction = null) {
        if ($this->_pm_instance->debug_mode()) {
            $form = new EE_Billing_Info_Form(
                    $this->_pm_instance, array(
                'name' => 'voguepay_Info_Form',
                'subsections' => array(
                    'voguepay_debug_info' => new EE_Form_Section_Proper(
                            array(
                        'layout_strategy' => new EE_Template_Layout(
                                array(
                            'layout_template_file' => $this->_template_path . 'voguepay_debug_info.template.php',
                            'template_args' => array('debug_mode' => $this->_pm_instance->debug_mode())
                                )
                        )
                            )
                    )
                )
                    )
            );
            return $form;
        }

        return false;
    }

}

