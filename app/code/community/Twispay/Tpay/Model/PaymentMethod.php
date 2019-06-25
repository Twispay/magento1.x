<?php

class Twispay_Tpay_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
                                       implements Mage_Payment_Model_Recurring_Profile_MethodInterface{
  private $logFileName = 'tpay.log';

  /* Availability options */
  protected $_code = 'tpay';
  protected $_formBlockType = 'tpay/form_tpay';
  protected $_isGateway               = true;
  protected $_canAuthorize            = true;
  protected $_canCapture              = true;
  protected $_canCapturePartial       = true;
  protected $_canRefund               = true;
  protected $_canRefundInvoicePartial = true;
  protected $_canVoid                 = false;
  protected $_canUseInternal          = true;
  protected $_canUseCheckout          = true;
  protected $_canUseForMultishipping  = true;
  protected $_canSaveCc               = false;
  protected $_isProxy                 = false;
  protected $_canFetchTransactionInfo = true;
  protected $_isInitializeNeeded      = false;


  /**
   * @return Mage_Checkout_Model_Session
   */
  protected function _getCheckout() {
    return Mage::getSingleton('checkout/session');
  }


  /**
   * Parent transaction id getter
   *
   * @param Varien_Object $payment
   * @return string
   */
  protected function _getParentTransactionId(Varien_Object $payment) {
    return $payment->getParentTransactionId() ? $payment->getParentTransactionId() : $payment->getLastTransId();
  }


  /**
   * Dummy function that is called when the config 'Payment Action'
   *  option is set to "Authorize Only" and will automatically
   *  authorize all the received orders.
   * 
   * Background operations:
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
   * Background operations:
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
   * Function that is called when the 'Credit Memo' button from the 
   *  invoince viewing screen.
   */
  public function refund(Varien_Object $payment, $amount) {
    Mage::Log(Mage::helper('tpay')->__('refund'), Zend_Log::NOTICE, $this->logFileName);

    /* Extract the transaction and transaction data. */
    $transactionId = $this->_getParentTransactionId($payment);
    $transaction = $payment->getTransaction($transactionId);
    $transactionData = $transaction->getData()['additional_information']['raw_details_info'];

    $storeId = $transactionData['storeId'];
    Mage::Log(__FUNCTION__ . ': storeId=' . print_r($storeId, true), Zend_Log::DEBUG, $this->logFileName);
    /* Read the configuration values. */
    $liveMode = Mage::getStoreConfig('payment/tpay/liveMode', $storeId);
    Mage::Log(__FUNCTION__ . ': liveMode=' . print_r($liveMode, true), Zend_Log::DEBUG, $this->logFileName);

    /* Check if live mode is active. */
    if (1 == $liveMode) {
      $apiKey = Mage::getStoreConfig('payment/tpay/liveApiKey', $storeId);
      $url = 'https://api.twispay.com/transaction/' . $transactionId;
    } else {
      $apiKey = Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId);
      $url = 'https://api-stage.twispay.com/transaction/' . $transactionId;
    }
    Mage::Log(__FUNCTION__ . ': apiKey=' . print_r($apiKey, true), Zend_Log::DEBUG, $this->logFileName);
    Mage::Log(__FUNCTION__ . ': url=' . print_r($url, true), Zend_Log::DEBUG, $this->logFileName);

    if ('' == $apiKey) {
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('Refund failed: Incomplete or missing configuration.'));
      $this->_redirect('adminhtml/sales_order/view', $transactionData['orderId']);
    }

    /* Make the server request. */
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: application/json', 'Authorization: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['amount' => $amount]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    /* Send the request. */
    $response = curl_exec($ch);
    /* Decode the response. */
    $response = json_decode($response);
    curl_close($ch);

    Mage::Log(__FUNCTION__ . ': url=' . print_r($response, true), Zend_Log::DEBUG, $this->logFileName);
    Mage::Log(__FUNCTION__ . ': url=' . print_r($response->code, true), Zend_Log::DEBUG, $this->logFileName);
    Mage::Log(__FUNCTION__ . ': url=' . print_r($response->message, true), Zend_Log::DEBUG, $this->logFileName);
    /* Check if the response code is 200 and message is 'Success'. */
    if ((200 == $response->code) && ('Success' == $response->message)) {
      /* Create a refund transaction */
      $payment->setTransactionId($response->data->transactionId);
      $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND, null, false, 'OK');
      // $payment->setTransactionAdditionalInfo( Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,array('key1'=>'value1','key2'=>'value2'));
      $payment->setTransactionAdditionalInfo( Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS
                                            , [ 'orderId'               => $transactionData['orderId']
                                              , 'refundedTransactionId' => $transactionData['transactionId']
                                              , 'transactionId'         => $response->data->transactionId]);
      $payment->setIsTransactionClosed(TRUE);
    } else {
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('Refund failed: Server communication error.'));
      $this->_redirect('adminhtml/sales_order/view', $transactionData['orderId']);
    }

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



  /**
     * Validate data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @throws Mage_Core_Exception
     */
    public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile){
      Mage::Log(Mage::helper('tpay')->__('validateRecurringProfile'), Zend_Log::NOTICE, $this->logFileName);
    }

    /**
     * Submit to the gateway
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @param Mage_Payment_Model_Info $paymentInfo
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $paymentInfo){
      Mage::Log(Mage::helper('tpay')->__('submitRecurringProfile'), Zend_Log::NOTICE, $this->logFileName);
    }

    /**
     * Fetch details
     *
     * @param string $referenceId
     * @param Varien_Object $result
     */
    public function getRecurringProfileDetails($referenceId, Varien_Object $result){
      Mage::Log(Mage::helper('tpay')->__('getRecurringProfileDetails'), Zend_Log::NOTICE, $this->logFileName);
    }

    /**
     * Check whether can get recurring profile details
     *
     * @return bool
     */
    public function canGetRecurringProfileDetails(){
      Mage::Log(Mage::helper('tpay')->__('canGetRecurringProfileDetails'), Zend_Log::NOTICE, $this->logFileName);
      return TRUE;
    }

    /**
     * Update data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile){
      Mage::Log(Mage::helper('tpay')->__('updateRecurringProfile'), Zend_Log::NOTICE, $this->logFileName);
    }

    /**
     * Manage status
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfileStatus(Mage_Payment_Model_Recurring_Profile $profile){
      Mage::Log(Mage::helper('tpay')->__('updateRecurringProfileStatus'), Zend_Log::NOTICE, $this->logFileName);
    }
}
