<?php
if (! function_exists('boolval' )) {
  function boolval($val) {
    return(bool) $val;
  }
}


class Twispay_Tpay_PaymentController extends Mage_Core_Controller_Front_Action{
  /* The name of the logging file. */
  private $logFileName = 'tpay.log';

  /**
   * Function that populates the JSON that needs to be sent to the server
   *  for a normal purchase.
   *
   * @return void
   */
  public function purchaseAction(){
    try{
      Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__(' Extract order details to send to Twispay server.'), Zend_Log::NOTICE, $this->logFileName);
      $this->loadLayout();

      /* Get latest orderId and extract the order. */
      $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
      $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
      Mage::Log(__FUNCTION__ . ': orderId=' . $orderId, Zend_Log::DEBUG, $this->logFileName);

      /* Set order status to payment pending. */
      $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true);
      $order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
      // $order->addStatusToHistory($order->getStatus(), __('Redirecting to Twispay payment gateway'));
      $order->save();

      /* Get the config values. */
      $siteId = Mage::helper('tpay')->getSiteId();
      $apiKey = Mage::helper('tpay')->getApiKey();
      $url = Mage::helper('tpay')->getUrl();

      if(('' == $siteId) || ('' == $apiKey)){
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Payment failed: Incomplete or missing configuration.'));
        $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
      }
      Mage::Log(__FUNCTION__ . ': siteId=' . $siteId . ' apiKey=' . $apiKey . ' url=' . $url, Zend_Log::DEBUG, $this->logFileName);

      /* Extract the billind and shipping addresses. */
      $billingAddress = $order->getBillingAddress();
      $shippingAddress = $order->getShippingAddress();

      /** Save the timestamp of this payment. */
      $timestamp = date('YmdHis');

      /* Extract the customer details. */
      $customer = [ 'identifier' => 'p_m1_' . ((0 == $billingAddress->getCustomerId()) ? ($orderId) : ($billingAddress->getCustomerId())) . '_' . $timestamp
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

      /* Calculate the order amount. */
      $amount = floatval($order->getGrandTotal());
      /** Validate that the amount is greated than 0. */
      if(0 <= $amount){
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' The amount must be greater than zero for card payments.'));
        $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
      }

      /* Build the data object to be posted to Twispay. */
      $orderData = [ 'siteId' => $siteId
                   , 'customer' => $customer
                   , 'order' => [ 'orderId' => $orderId . '_' . $timestamp
                                , 'type' => 'purchase'
                                , 'amount' => number_format($amount, 2, '.', '')
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
                   , 'backUrl' => Mage::getBaseUrl() . "tpay/payment/response"
                   /* , 'customData' => [] */
      ];

      /* Encode the data and calculate the checksum. */
      $base64JsonRequest = Mage::helper('tpay')->getBase64JsonRequest($orderData);
      Mage::Log(__FUNCTION__ . ': base64JsonRequest=' . $base64JsonRequest, Zend_Log::DEBUG, $this->logFileName);
      $base64Checksum = Mage::helper('tpay')->getBase64Checksum($orderData, $apiKey);
      Mage::Log(__FUNCTION__ . ': base64Checksum=' . $base64Checksum, Zend_Log::DEBUG, $this->logFileName);

      /* Send the data to the redirect block and render the complete layout. */
      $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'tpay', ['template' => 'tpay/redirect.phtml']);

      Mage::register('url', $url);
      Mage::register('jsonRequest', $base64JsonRequest);
      Mage::register('checksum', $base64Checksum);

      $this->getLayout()->getBlock('content')->append($block);
      $this->renderLayout();

    } catch (Exception $exception) {
      Mage::logException($exception);
      Mage::log(__FUNCTION__ . $exception, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      parent::_redirect('checkout/cart');
    }
  }


  /**
   * Function that populates the message that needs to be sent to the server
   *  for a recurring profile purchase.
   *
   * @return void
   */
  public function profileAction(){
    try{
      Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__(' Extract the recurring profile details to send to Twispay server.'), Zend_Log::NOTICE, $this->logFileName);
      $this->loadLayout();

      /* Get profileId and extract the recurring profile. */
      $profileId = Mage::app()->getRequest()->getParam('profileId');
      $profile = Mage::getModel('sales/recurring_profile')->load($profileId);
      Mage::Log(__FUNCTION__ . ': profileId=' . $profileId, Zend_Log::DEBUG, $this->logFileName);

      /* Set recurring profile status to payment pending. */
      $profile->setStatus(Mage_Sales_Model_Recurring_Profile::STATE_PENDING);
      $profile->save();

      /* Create recurring profile child order. */
      Mage::helper('tpay')->createRecurringProfileChildOrder($profile);

      /* Get the config values. */
      $siteId = Mage::helper('tpay')->getSiteId();
      $apiKey = Mage::helper('tpay')->getApiKey();
      $url = Mage::helper('tpay')->getUrl();

      if(('' == $siteId) || ('' == $apiKey)){
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Payment failed: Incomplete or missing configuration.'));
        $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
      }
      Mage::Log(__FUNCTION__ . ': siteId=' . $siteId . ' apiKey=' . $apiKey . ' url=' . $url, Zend_Log::DEBUG, $this->logFileName);

      /* Extract the billind and shipping addresses. */
      $billingAddress = $profile->getBillingAddressInfo();
      $shippingAddress = $profile->getShippingAddressInfo();

      /** Save the timestamp of this payment. */
      $timestamp = date('YmdHis');

      /* Extract the customer details. */
      $customer = [ 'identifier' => 'r_m1_' . ((0 == $billingAddress['customer_id']) ? ($profileId) : ($billingAddress['customer_id'])) . '_' . $timestamp
                  , 'firstName' => ($billingAddress['firstname']) ? ($billingAddress['firstname']) : ($shippingAddress['firstname'])
                  , 'lastName' => ($billingAddress['lastname']) ? ($billingAddress['lastname']) : ($shippingAddress['lastname'])
                  , 'country' => ($billingAddress['country_id']) ? ($billingAddress['country_id']) : ($shippingAddress['country_id'])
                  // , 'state' => (('US' == $billingAddress->getCountryId()) && (NULL != $billingAddress->getRegionCode())) ? ($billingAddress->getRegionCode()) : ((('US' == $shippingAddress->getCountryId()) && (NULL != $shippingAddress->getRegionCode())) ? ($shippingAddress->getRegionCode()) : (''))
                  , 'city' => ($billingAddress['city']) ? ($billingAddress['city']) : ($shippingAddress['city'])
                  , 'address' => ($billingAddress['street']) ? (str_replace("\n", ' ', $billingAddress['street'])) : (str_replace("\n", ' ', $shippingAddress['street']))
                  , 'zipCode' => ($billingAddress['postcode']) ? ($billingAddress['postcode']) : ($shippingAddress['postcode'])
                  , 'phone' => ($billingAddress['telephone']) ? ('+' . preg_replace('/([^0-9]*)+/', '', $billingAddress['telephone'])) : (($shippingAddress['telephone']) ? ('+' . preg_replace('/([^0-9]*)+/', '', $shippingAddress['telephone'])) : (''))
                  , 'email' => ($billingAddress['email']) ? ($billingAddress['email']) : ($shippingAddress['email'])
                  /* , 'tags' => [] */
                  ];

      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */
      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! IMPORTANT !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */
      /* READ:  We presume that there will be ONLY ONE recurring profile product inside the order. */
      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */
      /* !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! */

      /* Extract the recurring profile details. */
      $profileData = $profile->getData();

      /* Calculate the order amount. */
      $amount = floatval($profileData['billing_amount']);
      /** Validate that the amount is greated than 0. */
      if(0 <= $amount){
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' The amount must be greater than zero for card payments.'));
        $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
      }

      /* Extract the trial price and the first billing date. */
      $trialAmount = (array_key_exists('init_amount', $profileData)) ? ($profileData['trial_billing_amount'] * $profileData['trial_period_max_cycles'] + $profileData['init_amount']) : ($profileData['trial_billing_amount'] * $profileData['trial_period_max_cycles']);
      $daysTillFirstBillDate = 0;
      switch ($profileData['trial_period_unit']) {
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_WEEK:
          $daysTillFirstBillDate = /*days/week*/7 * $profileData['trial_period_frequency'] * $profileData['trial_period_max_cycles'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_SEMI_MONTH:
          $daysTillFirstBillDate = /*days/week*/14 * $profileData['trial_period_frequency'] * $profileData['trial_period_max_cycles'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_MONTH:
          $daysTillFirstBillDate = /*days/week*/30 * $profileData['trial_period_frequency'] * $profileData['trial_period_max_cycles'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_YEAR:
          $daysTillFirstBillDate = /*days/week*/365 * $profileData['trial_period_frequency'] * $profileData['trial_period_max_cycles'];
          break;
        default:
          /* We change nothing in case of DAYS. */
          $daysTillFirstBillDate = $profileData['trial_period_frequency'] * $profileData['trial_period_max_cycles'];
          break;
      }

      /* Check if the trial period is free. */
      if ((0 == $trialAmount) && (0 < $daysTillFirstBillDate)) {
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Payment for order #%s canceled as free trial period are not allowed.'));
        $this->_redirect('checkout/onepage', ['_secure' => TRUE]);
      }

      $datetime = new DateTime('now');
      $datetime->setTimezone(new DateTimezone(Mage::getStoreConfig('general/locale/timezone')));
      $datetime->add(new DateInterval(/*period*/'P' . $daysTillFirstBillDate . /*days*/'D'));
      $firstBillDate = $datetime->format('c');

      /* Calculate the recurring profile's interval type and value. */
      $intervalType = '';
      $intervalValue = '';
      switch ($profileData['period_unit']) {
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_WEEK:
          /* Convert weeks to days. */
          $intervalType = Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_DAY;
          $intervalValue = /*days/week*/7 * $profileData['period_frequency'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_SEMI_MONTH:
          /* Convert 2 weeks to days. */
          $intervalType = Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_DAY;
          $intervalValue = /*days/week*/14 * $profileData['period_frequency'];
          break;
        case Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_YEAR:
          /* Convert years to months. */
          $intervalType = Mage_Payment_Model_Recurring_Profile::PERIOD_UNIT_MONTH;
          $intervalValue = /*days/week*/12 * $profileData['period_frequency'];
          break;
        default:
          /* We change nothing in case of DAYS and MONTHS */
          $intervalType = $profileData['period_unit'];
          $intervalValue = $profileData['period_frequency'];
          break;
      }

      /* Build the data object to be posted to Twispay. */
      $orderData = [ 'siteId' => $siteId
                   , 'customer' => $customer
                   , 'order' => [ 'orderId' => $profileId . '_' . $timestamp
                                , 'type' => 'recurring'
                                , 'amount' => number_format($amount, 2, '.', '') /* Total sum to pay per cycle. */
                                , 'currency' => $profileData['currency_code']
                                ]
                   , 'cardTransactionMode' => 'authAndCapture'
                   , 'invoiceEmail' => ''
                   , 'backUrl' => Mage::getBaseUrl() . "tpay/payment/response"
                   ];

      /* Add the recurring profile data. */
      $orderData['order']['intervalType'] = $intervalType;
      $orderData['order']['intervalValue'] = $intervalValue;
      if('0' != $trialAmount){
          $orderData['order']['trialAmount'] = number_format(floatval($trialAmount), 2, '.', '');
          $orderData['order']['firstBillDate'] = $firstBillDate;
      }
      $orderData['order']['description'] = $intervalValue . " " . $intervalType . " subscription " . $profileData['order_item_info']['name'];

      /* Encode the data and calculate the checksum. */
      $base64JsonRequest = Mage::helper('tpay')->getBase64JsonRequest($orderData);
      Mage::Log(__FUNCTION__ . ': base64JsonRequest=' . $base64JsonRequest, Zend_Log::DEBUG, $this->logFileName);
      $base64Checksum = Mage::helper('tpay')->getBase64Checksum($orderData, $apiKey);
      Mage::Log(__FUNCTION__ . ': base64Checksum=' . $base64Checksum, Zend_Log::DEBUG, $this->logFileName);

      /* Send the data to the redirect block and render the complete layout. */
      $block = $this->getLayout()->createBlock( 'Mage_Core_Block_Template', 'tpay', ['template' => 'tpay/redirect.phtml']);

      Mage::register('url', $url);
      Mage::register('jsonRequest', $base64JsonRequest);
      Mage::register('checksum', $base64Checksum);

      $this->getLayout()->getBlock('content')->append($block);
      $this->renderLayout();

    } catch (Exception $exception) {
      Mage::logException($exception);
      Mage::log(__FUNCTION__ . $exception, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      parent::_redirect('checkout/cart');
    }
  }


  /**
   * Function that processes the backUrl message of the server.
   *
   * @return void
   */
  public function responseAction(){
    Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__(' Process the backUrl response of the Twispay server.'), Zend_Log::NOTICE, $this->logFileName);

    /* Get the config values. */
    $apiKey = Mage::helper('tpay')->getApiKey();
    Mage::Log(__FUNCTION__ . ': apiKey=' . $apiKey, Zend_Log::DEBUG, $this->logFileName);

    if('' == $apiKey){
      Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Payment failed: Incomplete or missing configuration.'));
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }

    /* Get the server response. */
    $response = $this->getRequest()->getPost('opensslResult', NULL);
    /* Check that the 'opensslResult' POST param exists. */
    if(NULL == $response){
      /* Try to get the 'result' POST param. */
      $response = $this->getRequest()->getPost('result', NULL);
    }
    /* Check that the 'result' POST param exists. */
    if(NULL == $response){
      $message = Mage::helper('tpay')->__(' NULL response received.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError($message);
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }

    /* Decrypt the response. */
    $decrypted = Mage::helper('tpay')->twispay_tw_decrypt_message(/*tw_encryptedResponse*/$response, /*secretKey*/$apiKey);

    if(FALSE == $decrypted){
      $message = Mage::helper('tpay')->__(' Failed to decript the response.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError($message);
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }

    /* Validate the decripted response. */
    $orderValidation = Mage::helper('tpay')->twispay_tw_checkValidation($decrypted);

    if(FALSE == $orderValidation){
      $message = Mage::helper('tpay')->__(' Failed to validate the response.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError($message);
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }

    /* Check if the response is for a recurring profile and extract the correct order. */
    $order = NULL;
    $profile = NULL;
    if('r' == $decrypted['identifier'][0]){
      $profile = Mage::getModel('sales/recurring_profile')->load($decrypted['externalOrderId']);
      $order = Mage::helper('tpay')->getRecurringProfileChildOrder($profile);
    } else {
      $order = Mage::getModel('sales/order')->loadByIncrementId($decrypted['externalOrderId']);
    }

    /* Update the recurring profile status. */
    $statusUpdate = Mage::helper('tpay')->updateStatus_backUrl( $profile
                                                              , $order
                                                              , $decrypted['orderId']
                                                              , $decrypted['transactionId']
                                                              , /*status*/(empty($decrypted['status'])) ? ($decrypted['transactionStatus']) : ($decrypted['status'])
                                                              , $decrypted['identifier'][0]);

    /* Redirect user to propper checkout page. */
    if (TRUE == $statusUpdate) {
      /* Check if a transaction with the same ID exists. */
      $transactions = Mage::getModel('sales/order_payment_transaction')->getCollection()->addOrderIdFilter($order->getId());

      /* Check if the transaction has already been registered. */
      $skipTransactionAdd = FALSE;
      foreach ($transactions as $transaction) {
        if($decrypted['transactionId'] == $transaction->getTxnId()){
          $skipTransactionAdd = TRUE;
          break;
        }
      }

      if (FALSE == $skipTransactionAdd) {
        if('p' == $decrypted['identifier'][0]){
          /* Save the payment transaction. */
          Mage::helper('tpay')->addOrderTransaction($order, /*serverResponse*/$decrypted);

          /* Link transaction to existing invoice. */
          Mage::helper('tpay')->addPurchaseInvoice($order, $decrypted['transactionId']);
        } else {
          try {
            /* Save the payment transaction. */
            Mage::helper('tpay')->addOrderTransaction($order, /*serverResponse*/$decrypted);

            /* Identify the invoice case and create new invoice for transaction. */
            if((0 == $transactions->count()) && (0 < $profile->getTrialBillingAmount())) {
              /* Invoice for init + trial period. */
              Mage::helper('tpay')->addTrialInvoice($order, $decrypted['transactionId']);
            } else {
              /* Invoice normal period. */
              Mage::helper('tpay')->addNormalInvoice($order, $decrypted['transactionId']);
            }

          } catch (Exception $exception) {
            Mage::logException($exception);
            Mage::log(__FUNCTION__ . $exception, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
          }
        }
      }

      $this->_redirect('checkout/onepage/success', ['_secure' => TRUE]);
    } else {
      /* Add the contact email to the magento registry. The value can be NULL = ''. */
      Mage::getSingleton('core/session')->setContactEmail(Mage::helper('tpay')->getContactEmail());

      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }
  }


  /**
   * Function that processes the IPN (Instant Payment Notification) message of the server.
   *
   * @return void
   */
  public function serverAction(){
    Mage::Log(__FUNCTION__ . ': ' . Mage::helper('tpay')->__(' Process the IPN response of the Twispay server.'), Zend_Log::NOTICE, $this->logFileName);

    /* Get the config values. */
    $apiKey = Mage::helper('tpay')->getApiKey();
    Mage::Log(__FUNCTION__ . ': apiKey=' . $apiKey, Zend_Log::DEBUG, $this->logFileName);

    if('' == $apiKey){
      $message = Mage::helper('tpay')->__(' Payment failed: Incomplete or missing configuration.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->getResponse()->setBody($message);
      return;
    }

    /* Check if we received a response. */
    if( (NULL === Mage::app()->getRequest()->getParam('opensslResult')) && (NULL === Mage::app()->getRequest()->getParam('result')) ) {
      $message = Mage::helper('tpay')->__(' NULL response received.');
      Mage::log(__FUNCTION__ .  $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->getResponse()->setBody($message);
      return;
    }

    /* Get the server response. */
    $response = (NULL !== Mage::app()->getRequest()->getParam('opensslResult')) ? (Mage::app()->getRequest()->getParam('opensslResult')) : (Mage::app()->getRequest()->getParam('result'));

    /* Decrypt the response. */
    $decrypted = Mage::helper('tpay')->twispay_tw_decrypt_message(/*tw_encryptedResponse*/$response, /*secretKey*/$apiKey);

    if(FALSE == $decrypted){
      $message = Mage::helper('tpay')->__(' Failed to decript the response.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->getResponse()->setBody($message);
      return;
    }

    /* Validate the decripted response. */
    $orderValidation = Mage::helper('tpay')->twispay_tw_checkValidation($decrypted);

    if(TRUE !== $orderValidation){
      $message = Mage::helper('tpay')->__(' Failed to validate the response.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->getResponse()->setBody($message);
      return;
    }

    /* Check if the response is for a recurring profile and extract the correct order. */
    $order = NULL;
    $profile = NULL;
    if('r' == $decrypted['identifier'][0]){
      $profile = Mage::getModel('sales/recurring_profile')->load($decrypted['externalOrderId']);
      $order = Mage::helper('tpay')->getRecurringProfileChildOrder($profile);
    } else {
      $order = Mage::getModel('sales/order')->loadByIncrementId($decrypted['externalOrderId']);
    }

    $statusUpdate = Mage::helper('tpay')->updateStatus_IPN( $profile
                                                          , $order
                                                          , $decrypted['orderId']
                                                          , $decrypted['transactionId']
                                                          , /*status*/(empty($decrypted['status'])) ? ($decrypted['transactionStatus']) : ($decrypted['status'])
                                                          , $decrypted['identifier'][0]);

    if (TRUE == $statusUpdate) {
      /* Check if a transaction with the same ID exists. */
      $transactions = Mage::getModel('sales/order_payment_transaction')->getCollection()->addOrderIdFilter($order->getId());

      /* Check if the transaction has already been registered. */
      $skipTransactionAdd = FALSE;
      foreach ($transactions as $transaction) {
        if($decrypted['transactionId'] == $transaction->getTxnId()){
          $skipTransactionAdd = TRUE;
          break;
        }
      }

      if (FALSE == $skipTransactionAdd) {
        if('p' == $decrypted['identifier'][0]){
          /* Save the payment transaction. */
          Mage::helper('tpay')->addOrderTransaction($order, /*serverResponse*/$decrypted);

          /* Link transaction to existing invoice. */
          Mage::helper('tpay')->addPurchaseInvoice($order, $decrypted['transactionId']);
        } else {
          try {
            /* Save the payment transaction. */
            Mage::helper('tpay')->addOrderTransaction($order, /*serverResponse*/$decrypted);

            /* Identify the invoice case and create new invoice for transaction. */
            if((0 == $transactions->count()) && (0 < $profile->getTrialBillingAmount())) {
              /* Invoice for init + trial period. */
              Mage::helper('tpay')->addTrialInvoice($order, $decrypted['transactionId']);
            } else {
              /* Invoice normal period. */
              Mage::helper('tpay')->addNormalInvoice($order, $decrypted['transactionId']);
            }
          } catch (Exception $exception) {
            Mage::logException($exception);
            Mage::log(__FUNCTION__ . $exception, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
          }
        }
      }
    }

    if('r' == $decrypted['identifier'][0]){
      /* ADD AUTOMATIC CANCEL */
      /* Extract transactions again in order to include the last one that may have been added. */
      $transactions = Mage::getModel('sales/order_payment_transaction')->getCollection()->addOrderIdFilter($order->getId());

      /* Calculate the max number of payments for this recurring profile. */
      $maxPaymentCycles = (1 <= $profile->getTrialPeriodMaxCycles()) ? ($profile->getPeriodMaxCycles() + 1) : ($profile->getPeriodMaxCycles());

      if($transactions->count() == $maxPaymentCycles){
        /* Cancel the recurring profile. */
        Mage::helper('tpay')->cancelRecurringProfile($profile, $order, 'Automatic cancel.');
      }
    }

    $this->getResponse()->setBody('OK');
  }
}
