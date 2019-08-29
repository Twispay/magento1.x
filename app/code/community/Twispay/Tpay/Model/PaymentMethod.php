<?php

class Twispay_Tpay_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
                                       implements Mage_Payment_Model_Recurring_Profile_MethodInterface {
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
    Mage::Log(Mage::helper('tpay')->__(' Authorize payment'), Zend_Log::DEBUG, $this->logFileName);
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
    Mage::Log(Mage::helper('tpay')->__(' Authorize and capture payment'), Zend_Log::DEBUG, $this->logFileName);
    return $this;
  }


  /**
   * Function that is called when a refund is done.
   */
  public function refund(Varien_Object $payment, $amount) {
    Mage::Log(__FUNCTION__ . ': amount=' . print_r($amount, true), Zend_Log::DEBUG, $this->logFileName);

    /* Extract the transaction and transaction data. */
    $transactionId = $this->_getParentTransactionId($payment);
    $transaction = $payment->getTransaction($transactionId);
    $transactionData = $transaction->getData()['additional_information']['raw_details_info'];

    /* Get the config values. */
    $apiKey = Mage::helper('tpay')->getApiKey();
    Mage::Log(__FUNCTION__ . ': apiKey=' . print_r($apiKey, true), Zend_Log::DEBUG, $this->logFileName);
    $url = Mage::helper('tpay')->getApiUrl();
    Mage::Log(__FUNCTION__ . ': url=' . print_r($url, true), Zend_Log::DEBUG, $this->logFileName);

    if ('' == $apiKey) {
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Refund failed: Incomplete or missing configuration.'));
      $this->_redirect('adminhtml/sales_order/view', $transactionData['orderId']);
    }

    /* Create the URL. */
    $url = $url . '/transaction/' . $transactionId;

    /* Create the DELETE data arguments. */
    $postData = 'amount=' . $amount . '&' . 'message=' . 'Refund for order ' . $transactionData['orderId'];

    /* Make the server request. */
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: application/json', 'Authorization: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POST, count($postData));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    /* Send the request. */
    $response = curl_exec($ch);
    curl_close($ch);
    /* Decode the response. */
    $response = json_decode($response);

    /* Check if the decryption was successful, the response code is 200 and message is 'Success'. */
    if ((NULL !== $response) && (200 == $response->code) && ('Success' == $response->message)) {
      /* Create a refund transaction */
      $payment->setTransactionId($response->data->transactionId);
      $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND, null, false, 'OK');
      $payment->setTransactionAdditionalInfo( Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS
                                            , [ 'orderId'               => $transactionData['orderId']
                                              , 'refundedTransactionId' => $transactionData['transactionId']
                                              , 'transactionId'         => $response->data->transactionId
                                              , 'amount'                => $amount]);
      $payment->setIsTransactionClosed(TRUE);
    } else {
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Refund failed: Server returned error: %s', $response->code));
      $this->_redirect('adminhtml/sales_order/view', $transactionData['orderId']);
    }

    return $this;
  }


  /**
   * Construct the redirect URL.
   */
  public function getOrderPlaceRedirectUrl() {
    $redirectUrl = Mage::getUrl('tpay/payment/purchase');
    Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' Getting the redirect URL: %s', $redirectUrl), Zend_Log::DEBUG, $this->logFileName);
    return $redirectUrl;
  }



  /**
     * Validate data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @throws Mage_Core_Exception
     */
    public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile){
      Mage::Log(__FUNCTION__, Zend_Log::DEBUG, $this->logFileName);
      return $this;
    }

    /**
     * Function that redirects to the action that constructs the redirect form.
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @param Mage_Payment_Model_Info $paymentInfo
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $paymentInfo){
      Mage::Log(__FUNCTION__, Zend_Log::DEBUG, $this->logFileName);
    }

    /**
     * Fetch details
     *
     * @param string $referenceId
     * @param Varien_Object $result
     */
    public function getRecurringProfileDetails($referenceId, Varien_Object $result){
      Mage::Log(__FUNCTION__, Zend_Log::DEBUG, $this->logFileName);
    }

    /**
     * Check whether can get recurring profile details
     *
     * @return bool
     */
    public function canGetRecurringProfileDetails(){
      Mage::Log(__FUNCTION__, Zend_Log::DEBUG, $this->logFileName);
      return FALSE;
    }

    /**
     * Update data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile){
      Mage::Log(__FUNCTION__, Zend_Log::DEBUG, $this->logFileName);
    }

    /**
     * Manage status
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfileStatus(Mage_Payment_Model_Recurring_Profile $profile){
      Mage::Log(__FUNCTION__, Zend_Log::DEBUG, $this->logFileName);

      switch ($profile->getNewState()) {
        case Mage_Sales_Model_Recurring_Profile::STATE_CANCELED:
          /* Extract the child order. */
          $order = Mage::helper('tpay')->getRecurringProfileChildOrder($profile);
          /* Cancel the recurring profile. */
          Mage::helper('tpay')->cancelRecurringProfile($profile, $order, 'Manual cancel.');
        break;

        default:
          Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' New state: '), Zend_Log::DEBUG, $this->logFileName);
      }
    }
}
