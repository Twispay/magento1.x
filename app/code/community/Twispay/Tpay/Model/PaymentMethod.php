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
   * Construct the redirect URL.
   */
  public function getOrderPlaceRedirectUrl() {
    $redirectUrl = Mage::getUrl('tpay/payment/redirect');
    Mage::Log ("Step 2 Process: Getting the redirect URL: $redirectUrl", Zend_Log::DEBUG, $this->logFileName);
    return $redirectUrl;
  }


  /**
   * Function used to force the authorization of any received order.
   */
  public function authorize(Varien_Object $payment, $amount) {
    Mage::Log ( 'Step 0 Process: Authorize', Zend_Log::DEBUG, $this->logFileName );
    return $this;
  }


  /**
   * This method is called if we are authorising AND
   * capturing a transaction
   */
  public function capture(Varien_Object $payment, $amount) {
    Mage::Log ( 'Step 1 Process: Create and capture the process', Zend_Log::DEBUG, $this->logFileName );
    return $this;
  }


  public function serverurl() {
      Mage::Log ( "running serverurl", Zend_Log::DEBUG, $this->logFileName );
      return "testurlserver";
  }
}
