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
   * Function that populates the message that needs to be sent to the server.
   */
  public function redirectAction(){
    try{
      Mage::Log(__FUNCTION__ . ': Extract order details to send to Twispay server.', Zend_Log::NOTICE, $this->logFileName);
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
        $url = 'https://secure.twispay.com';
      } else {
        $siteId = Mage::getStoreConfig('payment/tpay/stagingSiteId', $storeId);
        $apiKey = Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId);
        $url = 'https://secure-stage.twispay.com';
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
                  , 'state' => (('US' == $billingAddress->getCountryId()) && (NULL != $billingAddress->getRegionCode())) ? ($billingAddress->getRegionCode()) : ((('US' == $shippingAddress->getCountryId()) && (NULL != $shippingAddress->getRegionCode())) ? ($shippingAddress->getRegionCode()) : (''))
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

      Mage::Log(__FUNCTION__ . ': orderData=' . print_r($orderData, TRUE), Zend_Log::DEBUG, $this->logFileName);

      /* Encode the data and calculate the checksum. */
      $base64JsonRequest = Mage::helper('tpay')->getBase64JsonRequest($orderData);
      Mage::Log(__FUNCTION__ . ': base64JsonRequest=' . $base64JsonRequest, Zend_Log::DEBUG, $this->logFileName);
      $base64Checksum = Mage::helper('tpay')->getBase64Checksum($orderData, $apiKey);
      Mage::Log(__FUNCTION__ . ': base64Checksum=' . $base64Checksum, Zend_Log::DEBUG, $this->logFileName);

      $link = $url;
      $payment = $order->getPayment();
      $payment->setTransactionId(time()); // Make it unique.
      $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, null, false, 'OK');
      $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, array('Context' => 'Token payment', 'Amount' => $amount, 'Status' => 0, 'Url' => $link));
      $transaction->setIsTransactionClosed(false); // Close the transaction on return?
      $transaction->save();
      $order->save();

      /* Send the data to the redirect block and render the complete layout. */
      $block = $this->getLayout()->createBlock( 'Mage_Core_Block_Template'
                                              , 'tpay'
                                              , ['template' => 'tpay/redirect.phtml']
                                              )->assign([ 'url' => $url
                                                        , 'jsonRequest' => $base64JsonRequest
                                                        , 'checksum' => $base64Checksum]);
      $this->getLayout()->getBlock('content')->append($block);
      $this->renderLayout();

    } catch(Exception $exception){
      Mage::logException($exception);
      Mage::log(__FUNCTION__ . $exception, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      parent::_redirect('checkout/cart');
    }
  }


  /**
   * Function that processes the backUrl message of the server.
   */
  public function responseAction(){
    Mage::Log(__FUNCTION__ . ': Process the backUrl response of the Twispay server.', Zend_Log::NOTICE, $this->logFileName);

    $storeId = Mage::app()->getStore()->getStoreId();
    /* Read the configuration values. */
    $liveMode = Mage::getStoreConfig('payment/tpay/liveMode', $storeId);
    Mage::Log(__FUNCTION__ . ': storeId=' . $storeId . ' liveMode=' . $liveMode, Zend_Log::DEBUG, $this->logFileName);

    /* Check if the plugin is set to the live mode. */
    $apiKey = (1 == $liveMode) ? (Mage::getStoreConfig('payment/tpay/liveApiKey', $storeId)) : (Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId));
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
      Mage::log(__FUNCTION__ . ": NULL response recived.", Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->_redirect('checkout/onepage/failure', array ('_secure' => true));
    }

    /* Decrypt the response. */
    $decrypted = Mage::helper('tpay')->twispay_tw_decrypt_message(/*tw_encryptedResponse*/$response, /*secretKey*/$apiKey);

    if(FALSE == $decrypted){
      Mage::log(__FUNCTION__ . ": Failed to decript the response.", Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->_redirect('checkout/onepage/failure', array ('_secure' => true));
    }

    /* Validate the decripted response. */
    $orderValidation = Mage::helper('tpay')->twispay_tw_checkValidation($decrypted);

    if(TRUE !== $orderValidation){
      Mage::log(__FUNCTION__ . ": Failed to validate the response.", Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->_redirect('checkout/onepage/failure', array ('_secure' => true));
    }

    /* Extract the order. */
    $orderId = explode('_', $decrypted['externalOrderId'])[0];

    /* Extract the transaction status. */
    $status = (empty($decrypted['status'])) ? ($decrypted['transactionStatus']) : ($decrypted['status']);

    /* Update the order status. */
    $statusUpdate = Mage::helper('tpay')->updateStatus_backUrl($orderId, $status, $decrypted->transactionId);

    /* Redirect user to propper checkout page. */
    if(TRUE == $statusUpdate){
      $this->_redirect('checkout/onepage/success', ['_secure' => true]);
    } else {
      $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
    }
  }


  /**
   * Function that processes the IPN (Instant Payment Notification) message of the server.
   */
  public function serverAction(){
    Mage::Log(__FUNCTION__ . ': Process the IPN response of the Twispay server.', Zend_Log::NOTICE, $this->logFileName);

    $storeId = Mage::app()->getStore()->getStoreId();
    /* Read the configuration values. */
    $liveMode = Mage::getStoreConfig('payment/tpay/liveMode', $storeId);
    Mage::Log(__FUNCTION__ . ': storeId=' . $storeId . ' liveMode=' . $liveMode, Zend_Log::DEBUG, $this->logFileName);

    /* Check if the plugin is set to the live mode. */
    $apiKey = (1 == $liveMode) ? (Mage::getStoreConfig('payment/tpay/liveApiKey', $storeId)) : (Mage::getStoreConfig('payment/tpay/stagingApiKey', $storeId));
    Mage::Log(__FUNCTION__ . ': apiKey=' . $apiKey, Zend_Log::DEBUG, $this->logFileName);

    /* Check if we received a response. */
    if( (FALSE == isset($_POST['opensslResult'])) && (FALSE == isset($_POST['result'])) ) {
      Mage::log(__FUNCTION__ . ": NULL response recived.", Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->_redirect('checkout/onepage/failure', array ('_secure' => true));
    }

    /* Get the server response. */
    $response = (isset($_POST['opensslResult'])) ? ($_POST['opensslResult']) : ($_POST['result']);

    /* Decrypt the response. */
    $decrypted = Mage::helper('tpay')->twispay_tw_decrypt_message(/*tw_encryptedResponse*/$response, /*secretKey*/$apiKey);

    if(FALSE == $decrypted){
      Mage::log(__FUNCTION__ . ": Failed to decript the response.", Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->_redirect('checkout/onepage/failure', array ('_secure' => true));
    }

    /* Validate the decripted response. */
    $orderValidation = Mage::helper('tpay')->twispay_tw_checkValidation($decrypted);

    if(TRUE !== $orderValidation){
      Mage::log(__FUNCTION__ . ": Failed to validate the response.", Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      $this->_redirect('checkout/onepage/failure', array ('_secure' => true));
    }

    /* Extract the order. */
    $orderId = explode('_', $decrypted['externalOrderId'])[0];

    /* Extract the transaction status. */
    $status = (empty($decrypted['status'])) ? ($decrypted['transactionStatus']) : ($decrypted['status']);

    Mage::helper('tpay')->updateStatus_IPN($orderId, $status, $decrypted->transactionId);
  }
}
