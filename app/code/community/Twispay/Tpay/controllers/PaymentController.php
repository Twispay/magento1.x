<?php
if (! function_exists('boolval' )) {
  function boolval($val) {
    return(bool) $val;
  }
}


class Twispay_Tpay_PaymentController extends Mage_Core_Controller_Front_Action{
  /* The name of the logging file. */
  private $logFileName = 'tpay.log';

  /* The URLs for production and staging API. */
  private static $live_host_name = 'https://secure.twispay.com';
  private static $stage_host_name = 'https://secure-stage.twispay.com';

  /**
   * Function that populates the JSON that needs to be sent to the server
   *  for a normal purchase.
   * 
   * @return void
   */
  public function purchaseAction(){
    try{
      Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__('log_info_order_redirect'), Zend_Log::NOTICE, $this->logFileName);
      $this->loadLayout();

      /* Get latest orderId and extract the order. */
      $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
      $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
      Mage::Log(__FUNCTION__ . ': orderId=' . $orderId, Zend_Log::DEBUG, $this->logFileName);

      /* Set order status to payment pending. */
      $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true);
      $order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
      $order->save();

      $storeId = Mage::app()->getStore()->getStoreId();
      /* Read the configuration values. */
      $liveMode = Mage::getStoreConfig('payment/tpay/liveMode', $storeId);
      Mage::Log(__FUNCTION__ . ': storeId=' . $storeId . ' liveMode=' . $liveMode, Zend_Log::DEBUG, $this->logFileName);

      /* Check if the plugin is set to the live mode. */
      $siteId = '';
      $apiKey = '';
      $url = '';
      if(1 == $liveMode){
        $siteId = Mage::getStoreConfig('payment/tpay/liveSiteId', $storeId);
        $apiKey = Mage::getStoreConfig('payment/tpay/liveApiKey', $storeId);
        $url = Twispay_Tpay_PaymentController::$live_host_name;
      } else {
        $siteId = Mage::getStoreConfig('payment/tpay/stagingSiteId', $storeId);
        $apiKey = Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId);
        $url = Twispay_Tpay_PaymentController::$stage_host_name;
      }

      if(('' == $siteId) || ('' == $apiKey)){
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('log_error_payment_failed_incomplete_missing_conf'));
        $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
      }
      Mage::Log(__FUNCTION__ . ': siteId=' . $siteId . ' apiKey=' . $apiKey . ' url=' . $url, Zend_Log::DEBUG, $this->logFileName);

      /* Extract the billind and shipping addresses. */
      $billingAddress = $order->getBillingAddress();
      $shippingAddress = $order->getShippingAddress();

      /* Extract the customer details. */
      $customer = [ 'identifier' => (0 == $billingAddress->getCustomerId()) ? ('_' . $orderId . '_' . date('YmdHis')) : ('_' . $billingAddress->getCustomerId())
                  , 'firstName' => ($billingAddress->getFirstname()) ? ($billingAddress->getFirstname()) : ($shippingAddress->getFirstname())
                  , 'lastName' => ($billingAddress->getLastname()) ? ($billingAddress->getLastname()) : ($shippingAddress->getLastname())
                  , 'country' => ($billingAddress->getCountryId()) ? ($billingAddress->getCountryId()) : ($shippingAddress->getCountryId())
                  // , 'state' => (('US' == $billingAddress->getCountryId()) && (NULL != $billingAddress->getRegionCode())) ? ($billingAddress->getRegionCode()) : ((('US' == $shippingAddress->getCountryId()) && (NULL != $shippingAddress->getRegionCode())) ? ($shippingAddress->getRegionCode()) : (''))
                  , 'city' => ($billingAddress->getCity()) ? ($billingAddress->getCity()) : ($shippingAddress->getCity())
                  , 'address' => ($billingAddress->getStreet()) ? ($billingAddress->getStreet()) : ($shippingAddress->getStreet())
                  , 'zipCode' => ($billingAddress->getPostcode()) ? ($billingAddress->getPostcode()) : ($shippingAddress->getPostcode())
                  , 'phone' => ($billingAddress->getTelephone()) ? ('+' . preg_replace('/([^0-9]*)+/', '', $billingAddress->getTelephone())) : (($shippingAddress->getTelephone()) ? ('+' . preg_replace('/([^0-9]*)+/', '', $shippingAddress->getTelephone())) : (''))
                  , 'email' => ($billingAddress->getEmail()) ? ($billingAddress->getEmail()) : ($shippingAddress->getEmail())
                  /* , 'tags' => [] */
                  ];

      /* Extract the items details. */
      $items = array();
      foreach($order->getAllItems() as $item){
        $items[] = [ 'item' => $item->getName()
                   , 'units' =>  (int) $item->getQtyOrdered()
                   , 'unitPrice' => (string) number_format((float) $item->getPriceInclTax(), 2, '.', '')
                   /* , 'type' => '' */
                   /* , 'code' => '' */
                   /* , 'vatPercent' => '' */
                   /* , 'itemDescription' => '' */
                   ];
      }

      /* Check if shiping price needs to be added. */
      if(0 < $order->getShippingAmount()){
        $items[] = [ 'item' => "Transport"
                   , 'units' =>  1
                   , 'unitPrice' => (string) number_format((float) $order->getShippingAmount(), 2, '.', '')
                   ];
      }

      /* Construct the backUrl. */
      $backUrl =  Mage::getBaseUrl() . "tpay/payment/response/";

      /* Calculate the order amount. */
      $amount = $order->getBaseGrandTotal();
      $index = strpos($amount, '.');
      if(FALSE !== $index){
        $amount = substr($amount, 0, $index + 3);
      }

      /* Build the data object to be posted to Twispay. */
      $orderData = [ 'siteId' => $siteId
                   , 'customer' => $customer
                   , 'order' => [ 'orderId' => $orderId
                                , 'type' => 'purchase'
                                , 'amount' => $amount
                                , 'currency' => $order->getOrderCurrencyCode()
                                , 'items' => $items
                                /* , 'tags' => [] */
                                /* , 'intervalType' => '' */
                                /* , 'intervalValue' => 1 */
                                /* , 'trialAmount' => 1 */
                                /* , 'firstBillDate' => '' */
                                /* , 'level3Type' => '', */
                                /* , 'level3Airline' => [ 'ticketNumber' => '' */
                                /*                      , 'passengerName' => '' */
                                /*                      , 'flightNumber' => '' */
                                /*                      , 'departureDate' => '' */
                                /*                      , 'departureAirportCode' => '' */
                                /*                      , 'arrivalAirportCode' => '' */
                                /*                      , 'carrierCode' => '' */
                                /*                      , 'travelAgencyCode' => '' */
                                /*                      , 'travelAgencyName' => ''] */
                                ]
                   , 'cardTransactionMode' => 'authAndCapture'
                   /* , 'cardId' => 0 */
                   , 'invoiceEmail' => ''
                   , 'backUrl' => $backUrl
                   /* , 'customData' => [] */
      ];

      /* Encode the data and calculate the checksum. */
      $base64JsonRequest = Mage::helper('tpay')->getBase64JsonRequest($orderData);
      Mage::Log(__FUNCTION__ . ': base64JsonRequest=' . $base64JsonRequest, Zend_Log::DEBUG, $this->logFileName);
      $base64Checksum = Mage::helper('tpay')->getBase64Checksum($orderData, $apiKey);
      Mage::Log(__FUNCTION__ . ': base64Checksum=' . $base64Checksum, Zend_Log::DEBUG, $this->logFileName);

      /* Send the data to the redirect block and render the complete layout. */
      $block = $this->getLayout()->createBlock( 'Mage_Core_Block_Template'
                                              , 'tpay'
                                              , ['template' => 'tpay/redirect.phtml']
                                              )->assign(['url' => $url, 'jsonRequest' => $base64JsonRequest, 'checksum' => $base64Checksum]);
      $this->getLayout()->getBlock('content')->append($block);
      $this->renderLayout();

    } catch(Exception $exception){
      Mage::logException($exception);
      Mage::log(__FUNCTION__ . $exception, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      parent::_redirect('checkout/cart');
    }
  }


  /**
   * Function that populates the message that needs to be sent to the server
   *  for a subscription purchase.
   * 
   * @return void
   */
  public function subscriptionAction(){
    try{
      Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__('log_info_subscription_redirect'), Zend_Log::NOTICE, $this->logFileName);
      $this->loadLayout();

      /* Get subscriptionId and extract the subscription. */
      $subscriptionId = Mage::app()->getRequest()->getParams()['subscriptionId'];
      $subscription = Mage::getModel('sales/recurring_profile')->load($subscriptionId);
      Mage::Log(__FUNCTION__ . ': subscriptionId=' . $subscriptionId, Zend_Log::DEBUG, $this->logFileName);


      /* Set subscription status to payment pending. */
      $subscription->setStatus(Mage_Sales_Model_Recurring_Profile::STATE_PENDING);
      $subscription->save();

      $storeId = Mage::app()->getStore()->getStoreId();
      /* Read the configuration values. */
      $liveMode = Mage::getStoreConfig('payment/tpay/liveMode', $storeId);
      Mage::Log(__FUNCTION__ . ': storeId=' . $storeId . ' liveMode=' . $liveMode, Zend_Log::DEBUG, $this->logFileName);

      /* Check if the plugin is set to the live mode. */
      $siteId = '';
      $apiKey = '';
      $url = '';
      if(1 == $liveMode){
        $siteId = Mage::getStoreConfig('payment/tpay/liveSiteId', $storeId);
        $apiKey = Mage::getStoreConfig('payment/tpay/liveApiKey', $storeId);
        $url = Twispay_Tpay_PaymentController::$live_host_name;
      } else {
        $siteId = Mage::getStoreConfig('payment/tpay/stagingSiteId', $storeId);
        $apiKey = Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId);
        $url = Twispay_Tpay_PaymentController::$stage_host_name;
      }

      // Mage::Log(__FUNCTION__ . ': data=' . print_r($subscription->getData(), true), Zend_Log::DEBUG, $this->logFileName);

      if(('' == $siteId) || ('' == $apiKey)){
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('log_error_payment_failed_incomplete_missing_conf'));
        $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
      }
      Mage::Log(__FUNCTION__ . ': siteId=' . $siteId . ' apiKey=' . $apiKey . ' url=' . $url, Zend_Log::DEBUG, $this->logFileName);

      /* Extract the billind and shipping addresses. */
      $billingAddress = $subscription->getBillingAddressInfo();
      $shippingAddress = $subscription->getShippingAddressInfo();

      /* Extract the customer details. */
      $customer = [ 'identifier' => ('' == $billingAddress['customer_id']) ? ('_' . $subscriptionId . '_' . date('YmdHis')) : ('_' . $billingAddress['customer_id'])
                  , 'firstName' => ($billingAddress['firstname']) ? ($billingAddress['firstname']) : ($shippingAddress['firstname'])
                  , 'lastName' => ($billingAddress['lastname']) ? ($billingAddress['lastname']) : ($shippingAddress['lastname'])
                  , 'country' => ($billingAddress['country_id']) ? ($billingAddress['country_id']) : ($shippingAddress['country_id'])
                  // , 'state' => (('US' == $billingAddress->getCountryId()) && (NULL != $billingAddress->getRegionCode())) ? ($billingAddress->getRegionCode()) : ((('US' == $shippingAddress->getCountryId()) && (NULL != $shippingAddress->getRegionCode())) ? ($shippingAddress->getRegionCode()) : (''))
                  , 'city' => ($billingAddress['city']) ? ($billingAddress['city']) : ($shippingAddress['city'])
                  , 'address' => ($billingAddress['street']) ? ($billingAddress['street']) : ($shippingAddress['street'])
                  , 'zipCode' => ($billingAddress['postcode']) ? ($billingAddress['postcode']) : ($shippingAddress['postcode'])
                  , 'phone' => ($billingAddress['telephone']) ? ('+' . preg_replace('/([^0-9]*)+/', '', $billingAddress['telephone'])) : (($shippingAddress['telephone']) ? ('+' . preg_replace('/([^0-9]*)+/', '', $shippingAddress['telephone'])) : (''))
                  , 'email' => ($billingAddress['email']) ? ($billingAddress['email']) : ($shippingAddress['email'])
                  /* , 'tags' => [] */
                  ];

      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */
      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! IMPORTANT !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */
      /* READ:  We presume that there will be ONLY ONE subscription product inside the order. */
      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */
      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */

      /* Extract the subscription details. */
      $subscriptionData = $subscription->getData();

      /* Extract the trial price and the first billing date. */
      $trialAmount = $subscriptionData['trial_billing_amount'];
      $daysTillFirstBillDate = '';
      switch ($subscriptionData['trial_period_unit']) {
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_WEEK:
          $daysTillFirstBillDate = /*days/week*/7 * $subscriptionData['trial_period_frequency'] * $subscriptionData['trial_period_max_cycles'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_SEMI_MONTH:
          $daysTillFirstBillDate = /*days/week*/14 * $subscriptionData['trial_period_frequency'] * $subscriptionData['trial_period_max_cycles'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_MONTH:
          $daysTillFirstBillDate = /*days/week*/30 * $subscriptionData['trial_period_frequency'] * $subscriptionData['trial_period_max_cycles'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_YEAR:
          $daysTillFirstBillDate = /*days/week*/365 * $subscriptionData['trial_period_frequency'] * $subscriptionData['trial_period_max_cycles'];
          break;
        default:
           /* We change nothing in case of DAYS. */
           $daysTillFirstBillDate = $subscriptionData['trial_period_frequency'] * $subscriptionData['trial_period_max_cycles'];
          break;
      }
      $datetime = new DateTime('now');
      $datetime->setTimezone(new DateTimezone(Mage::getStoreConfig('general/locale/timezone')));
      $datetime->add(new DateInterval('P' . $daysTillFirstBillDate . 'D'));
      $firstBillDate = $datetime->format('c');

      /* Calculate the subscription's interval type and value. */
      $intervalType = '';
      $intervalValue = '';
      switch ($subscriptionData['period_unit']) {
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_WEEK:
          /* Convert weeks to days. */
          $intervalType = Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_DAY;
          $intervalValue = /*days/week*/7 * $subscriptionData['period_frequency'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_SEMI_MONTH:
          /* Convert 2 weeks to days. */
          $intervalType = Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_DAY;
          $intervalValue = /*days/week*/14 * $subscriptionData['period_frequency'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_YEAR:
          /* Convert years to months. */
          $intervalType = Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_MONTH;
          $intervalValue = /*days/week*/12 * $subscriptionData['period_frequency'];
          break;
        default:
          /* We change nothing in case of DAYS and MONTHS */
          $intervalType = $subscriptionData['period_unit'];
          $intervalValue = $subscriptionData['period_frequency'];
          break;
      }

      /* Construct the backUrl. */
      $backUrl =  Mage::getBaseUrl() . "tpay/payment/response/";

      /* Build the data object to be posted to Twispay. */
      $orderData = [ 'siteId' => $siteId
                   , 'customer' => $customer
                   , 'order' => [ 'orderId' => $subscriptionData['internal_reference_id']
                                , 'type' => 'recurring'
                                , 'amount' => $subscriptionData['billing_amount'] /* Total sum to pay right now. */
                                , 'currency' => $subscriptionData['currency_code']
                                ]
                   , 'cardTransactionMode' => 'authAndCapture'
                   , 'invoiceEmail' => ''
                   , 'backUrl' => $backUrl
                   ];

      /* Add the subscription data. */
      $orderData['order']['intervalType'] = $intervalType;
      $orderData['order']['intervalValue'] = $intervalValue;
      if('0' != $trialAmount){
          $orderData['order']['trialAmount'] = $trialAmount;
          $orderData['order']['firstBillDate'] = $firstBillDate;
      }
      $orderData['order']['description'] = $intervalValue . " " . $intervalType . " subscription " . $subscriptionData['order_item_info']['name'];

      /* Encode the data and calculate the checksum. */
      $base64JsonRequest = Mage::helper('tpay')->getBase64JsonRequest($orderData);
      Mage::Log(__FUNCTION__ . ': base64JsonRequest=' . $base64JsonRequest, Zend_Log::DEBUG, $this->logFileName);
      $base64Checksum = Mage::helper('tpay')->getBase64Checksum($orderData, $apiKey);
      Mage::Log(__FUNCTION__ . ': base64Checksum=' . $base64Checksum, Zend_Log::DEBUG, $this->logFileName);

      Mage::Log(__FUNCTION__ . ': before get layout create block', Zend_Log::DEBUG, $this->logFileName);
      /* Send the data to the redirect block and render the complete layout. */
     /* Send the data to the redirect block and render the complete layout. */
     $block = $this->getLayout()->createBlock( 'Mage_Core_Block_Template'
                                             , 'tpay'
                                             , ['template' => 'tpay/redirect.phtml']
                                             )->assign(['url' => $url, 'jsonRequest' => $base64JsonRequest, 'checksum' => $base64Checksum]);
      $this->getLayout()->getBlock('content')->append($block);
      $this->renderLayout();
      Mage::Log(__FUNCTION__ . ': before FINISH', Zend_Log::DEBUG, $this->logFileName);

    } catch(Exception $exception){
      Mage::logException($exception);
      Mage::log(__FUNCTION__ . $exception, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      parent::_redirect('checkout/cart');
    }
    Mage::Log(__FUNCTION__ . ': FINISH', Zend_Log::DEBUG, $this->logFileName);
  }


  /**
   * Function that processes the backUrl message of the server.
   * 
   * @return void
   */
  public function responseAction(){
    Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__('log_info_response_action'), Zend_Log::NOTICE, $this->logFileName);

    $storeId = Mage::app()->getStore()->getStoreId();
    /* Read the configuration values. */
    $liveMode = Mage::getStoreConfig('payment/tpay/liveMode', $storeId);
    Mage::Log(__FUNCTION__ . ': storeId=' . $storeId . ' liveMode=' . $liveMode, Zend_Log::DEBUG, $this->logFileName);

    /* Check if the plugin is set to the live mode. */
    $apiKey = (1 == $liveMode) ? (Mage::getStoreConfig('payment/tpay/liveApiKey', $storeId)) : (Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId));

    if('' == $apiKey){
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('log_error_payment_failed_incomplete_missing_conf'));
      $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
    }
    Mage::Log(__FUNCTION__ . ': apiKey=' . $apiKey, Zend_Log::DEBUG, $this->logFileName);

    /* Get the server response. */
    $response = $this->getRequest()->getPost('opensslResult', NULL);
    /* Check that the 'opensslResult' POST param exists. */
    if(NULL == $response){
      /* Try to get the 'result' POST param. */
      $response = $this->getRequest()->getPost('result', NULL);
    }
    /* Check that the 'result' POST param exists. */
    if(NULL == $response){
      Mage::log(__FUNCTION__ . Mage::helper('tpay')->__('log_error_null_response'), Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('log_error_null_response'));
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }

    /* Decrypt the response. */
    $decrypted = Mage::helper('tpay')->twispay_tw_decrypt_message(/*tw_encryptedResponse*/$response, /*secretKey*/$apiKey);

    if(FALSE == $decrypted){
      Mage::log(__FUNCTION__ . Mage::helper('tpay')->__('log_error_decript_failed'), Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('log_error_decript_failed'));
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }

    /* Validate the decripted response. */
    $orderValidation = Mage::helper('tpay')->twispay_tw_checkValidation($decrypted);

    if(FALSE == $orderValidation){
      Mage::log(__FUNCTION__ . Mage::helper('tpay')->__('log_error_validation_failed'), Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__('log_error_validation_failed'));
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }

    /* Extract the transaction status. */
    $status = (empty($decrypted['status'])) ? ($decrypted['transactionStatus']) : ($decrypted['status']);

    /* Update the order status. */
    $statusUpdate = Mage::helper('tpay')->updateStatus_backUrl(/*orderId*/$decrypted['externalOrderId'], $status, /*transactionId*/$decrypted['transactionId']);

    /* Redirect user to propper checkout page. */
    if(TRUE == $statusUpdate){
      /* Extract the order. */
      $order = Mage::getModel('sales/order')->loadByIncrementId($decrypted['externalOrderId']);

      /* Save the payment transaction. */
      $payment = $order->getPayment();
      $payment->setTransactionId($decrypted['transactionId']);
      $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE, null, false, 'OK');
      $transaction->setAdditionalInformation( Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS
                                            , [ 'identifier'    => $decrypted['identifier']
                                              , 'status'        => $decrypted['status']
                                              , 'orderId'       => $decrypted['orderId']
                                              , 'transactionId' => $decrypted['transactionId']
                                              , 'customerId'    => $decrypted['customerId']
                                              , 'cardId'        => $decrypted['cardId']
                                              , 'storeId'       => $storeId]);
      $payment->setIsTransactionClosed(TRUE);
      $transaction->save();
      $order->save();

      /* Add the transaction to the invoice. */
      $invoice = $order->getInvoiceCollection()->addAttributeToSort('created_at', 'DSC')->setPage(1, 1)->getFirstItem();
      $invoice->setTransactionId($decrypted['transactionId']);
      $invoice->save();

      $this->_redirect('checkout/onepage/success', ['_secure' => TRUE]);
    } else {
      /* Read the configuration contact email. */
      $contactEmail = Mage::getStoreConfig('payment/tpay/contactEmail', $storeId);
      if('' != $contactEmail){
        /* Add the contact email to the magento registry. */
        Mage::getSingleton('core/session')->setContactEmail($contactEmail);
      }

      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }
  }


  /**
   * Function that processes the IPN (Instant Payment Notification) message of the server.
   * 
   * @return void
   */
  public function serverAction(){
    Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__('log_info_server_action'), Zend_Log::NOTICE, $this->logFileName);

    $storeId = Mage::app()->getStore()->getStoreId();
    /* Read the configuration values. */
    $liveMode = Mage::getStoreConfig('payment/tpay/liveMode', $storeId);
    Mage::Log(__FUNCTION__ . ': storeId=' . $storeId . ' liveMode=' . $liveMode, Zend_Log::DEBUG, $this->logFileName);

    /* Check if the plugin is set to the live mode. */
    $apiKey = (1 == $liveMode) ? (Mage::getStoreConfig('payment/tpay/liveApiKey', $storeId)) : (Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId));

    if('' == $apiKey){
      Mage::log(__FUNCTION__ . Mage::helper('tpay')->__('log_error_payment_failed_incomplete_missing_conf'), Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      return;
    }
    Mage::Log(__FUNCTION__ . ': apiKey=' . $apiKey, Zend_Log::DEBUG, $this->logFileName);

    /* Check if we received a response. */
    if( (FALSE == isset($_POST['opensslResult'])) && (FALSE == isset($_POST['result'])) ) {
      Mage::log(__FUNCTION__ .  Mage::helper('tpay')->__('log_error_null_response'), Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      return;
    }

    /* Get the server response. */
    $response = (isset($_POST['opensslResult'])) ? ($_POST['opensslResult']) : ($_POST['result']);

    /* Decrypt the response. */
    $decrypted = Mage::helper('tpay')->twispay_tw_decrypt_message(/*tw_encryptedResponse*/$response, /*secretKey*/$apiKey);

    if(FALSE == $decrypted){
      Mage::log(__FUNCTION__ . Mage::helper('tpay')->__('log_error_decript_failed'), Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      return;
    }

    /* Validate the decripted response. */
    $orderValidation = Mage::helper('tpay')->twispay_tw_checkValidation($decrypted);

    if(TRUE !== $orderValidation){
      Mage::log(__FUNCTION__ . Mage::helper('tpay')->__('log_error_validation_failed'), Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      return;
    }

    /* Extract the transaction status. */
    $status = (empty($decrypted['status'])) ? ($decrypted['transactionStatus']) : ($decrypted['status']);

    $statusUpdate = Mage::helper('tpay')->updateStatus_IPN($decrypted['externalOrderId'], $status, $decrypted['transactionId']);

    if (TRUE == $statusUpdate) {
      /* Check if a transaction with the same transaction ID exists. */
      $transaction = Mage::getModel('sales/order_payment_transaction')
                         ->getCollection()
                         ->addAttributeToFilter('order_id', $decrypted['externalOrderId'])
                         ->addAttributeToFilter('txn_id', $decrypted['transactionId']);

      if (0 == $transaction['totalRecords']) {
        /* Create a new transaction */
        $order = Mage::getModel('sales/order')->loadByIncrementId($decrypted['externalOrderId']);

        /* Save the payment transaction. */
        $payment = $order->getPayment();
        $payment->setTransactionId($decrypted['transactionId']);
        $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE, null, false, 'OK');
        $transaction->setAdditionalInformation( Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS
                                              , [ 'identifier'    => $decrypted['identifier']
                                                , 'status'        => $decrypted['status']
                                                , 'orderId'       => $decrypted['orderId']
                                                , 'transactionId' => $decrypted['transactionId']
                                                , 'customerId'    => $decrypted['customerId']
                                                , 'cardId'        => $decrypted['cardId']
                                                , 'storeId'       => $storeId]);
        $payment->setIsTransactionClosed(TRUE);
        $transaction->save();
        $order->save();

        /* Add the transaction to the invoice. */
        $invoice = $order->getInvoiceCollection()->addAttributeToSort('created_at', 'DSC')->setPage(1, 1)->getFirstItem();
        $invoice->setTransactionId($decrypted['transactionId']);
        $invoice->save();
      }
    }
  }
}
