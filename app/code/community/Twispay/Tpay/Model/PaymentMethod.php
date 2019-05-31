<?php

class Twispay_Tpay_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract{
  private $logFileName = 'tpay.log';

  /* Availability options */
  protected $_code = 'tpay';
  protected $_formBlockType = 'tpay/form_tpay';
  protected $_isGateway = true;
  protected $_canAuthorize = true;
  protected $_canCapture = true;
  protected $_canCapturePartial = true;
  protected $_canRefund = false;
  protected $_canVoid = false;
  protected $_canUseInternal = true;
  protected $_canUseCheckout = true;
  protected $_canUseForMultishipping = true;
  protected $_canSaveCc = false;
  protected $_isInitializeNeeded = false;


  /**
   * @return Mage_Checkout_Model_Session
   */
  protected function _getCheckout() {
    return Mage::getSingleton('checkout/session');
  }


  /**
   * Dummy function that is called when the config 'Payment Action'
   *  option is set to "Authorize Only" and will automatically
   *  authorize all the received orders.
   * 
   * Background result:
   *   - order with status PROCESSING is created;
   *   - transaction is registered into magento with the transaction id;
   *   - order comment is added automatically for authorization;
   */
  public function authorize(Varien_Object $payment, $amount) {
    Mage::Log(Mage::helper('tpay')->__('Authorize payment'), Zend_Log::NOTICE, $this->logFileName);
    return $this;
  }


  /**
   * Dummy function that is called when the config 'Payment Action'
   *  option is set to "Authorize and Capture" and will automatically
   *  authorize and capture all the received orders.
   * 
   * Background result:
   *   - order with status PROCESSING is created;
   *   - transaction is registered into magento with the transaction id;
   *   - order comment is added automatically for authorization;
   *   - invoice is created with status PAID;
   */
  public function capture(Varien_Object $payment, $amount) {
    Mage::Log(Mage::helper('tpay')->__('Authorize and capture payment'), Zend_Log::NOTICE, $this->logFileName);
    return $this;
  }


  /**
   * Construct the redirect URL.
   */
  public function getOrderPlaceRedirectUrl() {
    $redirectUrl = Mage::getUrl('tpay/payment/redirect');
    Mage::Log (Mage::helper('tpay')->__('Getting the redirect URL: %s', $redirectUrl), Zend_Log::NOTICE, $this->logFileName);
    return $redirectUrl;
  }
}
