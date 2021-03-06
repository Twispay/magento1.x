<?php
/**
 * Class that implements methods for:
 *   - Notification:
 *       - encoding JSON to be sent to Twispay server;
 *       - calculating checksum to be sent to Twispay server;
 *   - Response:
 *       - decripting Twispay server responses;
 *       - validating Twispay server responses;
 *   - Status Update:
 *       -
 */
class Twispay_Tpay_Helper_Data extends Mage_Core_Helper_Abstract {

  /* The name of the logging file. */
  private $logFileName = 'tpay.log';
  /* Array containing the possible result statuses. */
  private $resultStatuses = [
    'UNCERTAIN' => 'uncertain', /* No response from provider */
    'IN_PROGRESS' => 'in-progress', /* Authorized */
    'COMPLETE_OK' => 'complete-ok', /* Captured */
    'COMPLETE_FAIL' => 'complete-failed', /* Not authorized */
    'CANCEL_OK' => 'cancel-ok', /* Capture reversal */
    'REFUND_OK' => 'refund-ok', /* Settlement reversal */
    'VOID_OK' => 'void-ok', /* Authorization reversal */
    'CHARGE_BACK' => 'charge-back', /* Charge-back received */
    'THREE_D_PENDING' => '3d-pending', /* Waiting for 3d authentication */
    'EXPIRING' => 'expiring', /* The recurring order has expired */
  ];

  /* The URLs for production and staging. */
  private $live_host_name = 'https://secure.twispay.com';
  private $stage_host_name = 'https://secure-stage.twispay.com';

  /* The URLs for production and staging API. */
  private $live_api_host_name = 'https://api.twispay.com';
  private $stage_api_host_name = 'https://api-stage.twispay.com';

  /************************** Inner functions START **************************/
  /**
   * Function that changes the state of an order
   *  and adds history comment.
   *
   * @param order: The purchase order to update.
   * @param state: The state to be set to the order.
   * @param status: The status to be set to the order.
   * @param comment: The comment to add to that status change.
   */
  private function setOrderState($order, $state, $status, $comment){
    /* Set the state of the order. */
    $order->setData('state', $state);
    $order->setStatus($status);

    /* Add history comment. */
    $history = $order->addStatusHistoryComment($comment);
    /* For backwards compatibility. */
    $history->setIsCustomerNotified(/*isCustomerNotified*/FALSE);

    /* Save changes. */
    $order->save();
  }


  /**
   * Update the status of a purchase order according to the received server status.
   *
   * @param purchase: The purchase order for which to update the status.
   * @param transactionId: The unique server transaction ID of the purchase.
   * @param serverStatus: The status received from server.
   *
   * @return bool(FALSE)     - If server status in: [COMPLETE_FAIL, THREE_D_PENDING]
   *         bool(TRUE)      - If server status in: [IN_PROGRESS, COMPLETE_OK]
   */
  private function updateStatus_purchase_backUrl($purchase, $transactionId, $serverStatus){
    switch ($serverStatus) {
      case $this->resultStatuses['COMPLETE_FAIL']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s failed.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status failed for order ID: ') . $purchase->getIncrementId(), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s failed.', $purchase->getIncrementId(), $transactionId));
        return FALSE;
      break;

      case $this->resultStatuses['THREE_D_PENDING']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage::helper('tpay')->__(' Order #%s pended as payment for transaction #%s is pending.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status three-d-pending for order ID: ') . $purchase->getIncrementId(), Zend_Log::WARN , $this->logFileName, /*forceLog*/TRUE);
        Mage::getSingleton('core/session')->addWarning(Mage::helper('tpay')->__(' Payment pended for transaction #%s, order #%s.', $transactionId, $purchase->getIncrementId()));
        return FALSE;
      break;

      case $this->resultStatuses['IN_PROGRESS']:
      case $this->resultStatuses['COMPLETE_OK']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage::helper('tpay')->__(' Order #%s processing as payment for transaction #%s is successful.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status complete-ok for order ID: ') . $purchase->getIncrementId(), Zend_Log::NOTICE , $this->logFileName);
        Mage::getSingleton('core/session')->addSuccess(Mage::helper('tpay')->__(' Payment successful for transaction #%s, order #%s.', $transactionId, $purchase->getIncrementId()));
        return TRUE;
      break;

      default:
        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Wrong status: ') . $serverStatus, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;
    }
  }


  /**
   * Update the status of a purchase order according to the received server status.
   *
   * @param purchase: The purchase order for which to update the status.
   * @param transactionId: The unique transaction ID of the order.
   * @param serverStatus: The status received from server.
   *
   * @return bool(FALSE)     - If server status in: [COMPLETE_FAIL, CANCEL_OK, VOID_OK, CHARGE_BACK, THREE_D_PENDING]
   *         bool(TRUE)      - If server status in: [REFUND_OK, IN_PROGRESS, COMPLETE_OK]
   */
  public function updateStatus_purchase_IPN($purchase, $transactionId, $serverStatus){
    switch ($serverStatus) {
      case $this->resultStatuses['COMPLETE_FAIL']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s failed.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status failed for order ID: ') . $purchase->getIncrementId(), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;

      case $this->resultStatuses['REFUND_OK']:
        /* Set order status. */
        if($purchase->getTotalPaid() > $purchase->getTotalRefunded()){
          $this->setOrderState( $purchase
                              , Mage_Sales_Model_Order::STATE_PROCESSING
                              , Mage_Sales_Model_Order::STATE_PROCESSING
                              , Mage::helper('tpay')->__(' Order #%s processing as payment for transaction #%s is partially refunded.', $purchase->getIncrementId(), $transactionId));
        } else {
          $this->setOrderState( $purchase
                              , Mage_Sales_Model_Order::STATE_CLOSED
                              , Mage_Sales_Model_Order::STATE_CLOSED
                              , Mage::helper('tpay')->__(' Order #%s closed as payment for transaction #%s has been refunded.', $purchase->getIncrementId(), $transactionId));
        }

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status refund-ok for order ID: ') . $purchase->getIncrementId(), Zend_Log::NOTICE , $this->logFileName);
        return TRUE;
      break;

      case $this->resultStatuses['CANCEL_OK']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s has been canceled.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status cancel-ok for order ID: ') . $purchase->getIncrementId(), Zend_Log::NOTICE , $this->logFileName);
        return FALSE;
      break;

      case $this->resultStatuses['VOID_OK']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s has been voided ok.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status void-ok for order ID: ') . $purchase->getIncrementId(), Zend_Log::NOTICE , $this->logFileName);
        return FALSE;
      break;

      case $this->resultStatuses['CHARGE_BACK']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s has been charged back.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status charge-back for order ID: ') . $purchase->getIncrementId(), Zend_Log::NOTICE , $this->logFileName);
        return FALSE;
      break;

      case $this->resultStatuses['THREE_D_PENDING']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage::helper('tpay')->__(' Order #%s pended as payment for transaction #%s is pending.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status three-d-pending for order ID: ') . $purchase->getIncrementId(), Zend_Log::WARN , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;

      case $this->resultStatuses['IN_PROGRESS']:
      case $this->resultStatuses['COMPLETE_OK']:
        /* Set order status. */
        $this->setOrderState( $purchase
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage::helper('tpay')->__(' Order #%s processing as payment for transaction #%s is successful.', $purchase->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status complete-ok for order ID: ') . $purchase->getIncrementId(), Zend_Log::NOTICE , $this->logFileName);
        return TRUE;
      break;

      default:
        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Wrong status: ') . $serverStatus, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;
    }
  }


  /**
   * Update the status of a recurring profile order according to the received server status.
   *
   * @param profile: The recurring profile order for which to update the status.
   * @param recurringProfileChildOrder: Child order of the recurring profile.
   * @param orderId: The unique Twispay order ID of the recurring profile.
   * @param transactionId: The unique transaction ID of the order.
   * @param serverStatus: The status received from server.
   *
   * @return bool(FALSE)     - If server status in: [COMPLETE_FAIL, THREE_D_PENDING]
   *         bool(TRUE)      - If server status in: [IN_PROGRESS, COMPLETE_OK]
   */
  private function updateStatus_recurringProfile_backUrl($profile, $recurringProfileChildOrder, $orderId, $transactionId, $serverStatus){
    switch ($serverStatus) {
      case $this->resultStatuses['COMPLETE_FAIL']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s failed.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status failed for order ID: ') . $profile->getId(), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        Mage::getSingleton('core/session')->addError(Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s failed.', $profile->getId(), $transactionId));
        return FALSE;
      break;

      case $this->resultStatuses['THREE_D_PENDING']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_PENDING, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage::helper('tpay')->__(' Order #%s pended as payment for transaction #%s is pending.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status three-d-pending for order ID: ') . $profile->getId(), Zend_Log::WARN , $this->logFileName, /*forceLog*/TRUE);
        Mage::getSingleton('core/session')->addWarning(Mage::helper('tpay')->__(' Payment pended for transaction #%s, order #%s.', $transactionId, $profile->getId()));
        return FALSE;
      break;

      case $this->resultStatuses['IN_PROGRESS']:
      case $this->resultStatuses['COMPLETE_OK']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage::helper('tpay')->__(' Order #%s processing as payment for transaction #%s is successful.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status complete-ok for order ID: ') . $profile->getId(), Zend_Log::NOTICE , $this->logFileName);
        Mage::getSingleton('core/session')->addSuccess(Mage::helper('tpay')->__(' Payment successful for transaction #%s, order #%s.', $transactionId, $profile->getId()));
        return TRUE;
      break;

      default:
        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Wrong status: ') . $serverStatus, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;
    }
  }


  /**
   * Update the status of a recurring profile order according to the received server status.
   *
   * @param profile: The recurring profile order for which to update the status.
   * @param recurringProfileChildOrder: Child order of the recurring profile.
   * @param orderId: The unique Twispay order ID of the recurring profile.
   * @param transactionId: The unique server transaction ID of the recurring profile.
   * @param serverStatus: The status received from server.
   *
   * @return bool(FALSE)     - If server status in: [COMPLETE_FAIL, CANCEL_OK, VOID_OK, CHARGE_BACK, THREE_D_PENDING]
   *         bool(TRUE)      - If server status in: [REFUND_OK, IN_PROGRESS, COMPLETE_OK]
   */
  public function updateStatus_recurringProfile_IPN($profile, $recurringProfileChildOrder, $orderId, $transactionId, $serverStatus){
    switch ($serverStatus) {
      case $this->resultStatuses['COMPLETE_FAIL']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s failed.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status failed for order ID: ') . $profile->getId(), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;

      case $this->resultStatuses['REFUND_OK']:
        /* Set recurring profile status. */
        // $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        // if($recurringProfileChildOrder->getTotalPaid() > $recurringProfileChildOrder->getTotalRefunded()){
        //   $this->setOrderState( $recurringProfileChildOrder
        //                       , Mage_Sales_Model_Order::STATE_PROCESSING
        //                       , Mage_Sales_Model_Order::STATE_PROCESSING
        //                       , Mage::helper('tpay')->__(' Order #%s processing as payment for transaction #%s is partially refunded.', $recurringProfileChildOrder->getIncrementId(), $transactionId));
        // } else {
        //   $this->setOrderState( $recurringProfileChildOrder
        //                       , Mage_Sales_Model_Order::STATE_CLOSED
        //                       , Mage_Sales_Model_Order::STATE_CLOSED
        //                       , Mage::helper('tpay')->__(' Order #%s closed as payment for transaction #%s has been refunded.', $recurringProfileChildOrder->getIncrementId(), $transactionId));
        // }

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status refund-ok for order ID: ') . $profile->getId(), Zend_Log::NOTICE , $this->logFileName);
        return TRUE;
      break;

      case $this->resultStatuses['CANCEL_OK']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_CANCELED, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s has been canceled.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status cancel-ok for order ID: ') . $profile->getId(), Zend_Log::NOTICE , $this->logFileName);
        return FALSE;
      break;

      case $this->resultStatuses['VOID_OK']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_CANCELED, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s has been voided ok.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status void-ok for order ID: ') . $profile->getId(), Zend_Log::NOTICE , $this->logFileName);
        return FALSE;
      break;

      case $this->resultStatuses['CHARGE_BACK']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled as payment for transaction #%s has been charged back.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status charge-back for order ID: ') . $profile->getId(), Zend_Log::NOTICE , $this->logFileName);
        return FALSE;
      break;

      case $this->resultStatuses['THREE_D_PENDING']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_PENDING, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage::helper('tpay')->__(' Order #%s pended as payment for transaction #%s is pending.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status three-d-pending for order ID: ') . $profile->getId(), Zend_Log::WARN , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;

      case $this->resultStatuses['IN_PROGRESS']:
      case $this->resultStatuses['COMPLETE_OK']:
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE, true);
        $profile->setReferenceId($orderId);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage::helper('tpay')->__(' Order #%s processing as payment for transaction #%s is successful.', $recurringProfileChildOrder->getIncrementId(), $transactionId));

        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Status complete-ok for order ID: ') . $profile->getId(), Zend_Log::NOTICE , $this->logFileName);
        return TRUE;
      break;

      default:
        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Wrong status: ') . $serverStatus, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;
    }
  }
  /************************** Inner functions END **************************/



  /************************** Config functions START **************************/
  /**
   * Function that extracts the value of the "liveMode" from
   *  the config.
   */
  public function getLiveMode(){
    return Mage::getStoreConfig('payment/tpay/liveMode', Mage::app()->getStore()->getStoreId());
  }


  /**
   * Function that extracts the value of the "apiKey" from
   *  the config depending of the "liveMode" value.
   */
  public function getContactEmail(){
    return Mage::getStoreConfig('payment/tpay/contactEmail', Mage::app()->getStore()->getStoreId());
  }


  /**
   * Function that extracts the value of the "siteId" from
   *  the config depending of the "liveMode" value.
   */
  public function getSiteId(){
    if(1 == $this->getLiveMode()){
      return Mage::getStoreConfig('payment/tpay/liveSiteId', Mage::app()->getStore()->getStoreId());
    } else {
      return Mage::getStoreConfig('payment/tpay/stagingSiteId', Mage::app()->getStore()->getStoreId());
    }
  }


  /**
   * Function that extracts the value of the "apiKey" from
   *  the config depending of the "liveMode" value.
   */
  public function getApiKey(){
    if(1 == $this->getLiveMode()){
      return Mage::getStoreConfig('payment/tpay/liveApiKey', Mage::app()->getStore()->getStoreId());
    } else {
      return Mage::getStoreConfig('payment/tpay/stagingApiKey', Mage::app()->getStore()->getStoreId());
    }
  }


  /**
   * Function that extracts the value of the "url"
   *  depending of the "liveMode" value.
   */
  public function getUrl(){
    if(1 == $this->getLiveMode()){
      return $this->live_host_name;
    } else {
      return $this->stage_host_name;
    }
  }


  /**
   * Function that extracts the value of the "api url"
   *  depending of the "liveMode" value.
   */
  public function getApiUrl(){
    if(1 == $this->getLiveMode()){
      return $this->live_api_host_name;
    } else {
      return $this->stage_api_host_name;
    }
  }
  /************************** Config functions END **************************/



  /************************** Api START **************************/
  /**
   * Function that extracts the status of an order from the
   *  Twisplay platform.
   *
   * @param orderId: The unique Twispay order ID of a recurring profile.
   *
   * @return string(server-status) In case of success.
   *         bool(FALSE)           If case if failure.
   */
  public function getServerOrderStatus($orderId){
    /* Get the config values. */
    $apiKey = Mage::helper('tpay')->getApiKey();
    Mage::Log(__FUNCTION__ . ': apiKey=' . print_r($apiKey, true), Zend_Log::DEBUG, $this->logFileName);
    $url = Mage::helper('tpay')->getApiUrl();
    Mage::Log(__FUNCTION__ . ': url=' . print_r($url, true), Zend_Log::DEBUG, $this->logFileName);

    if ('' == $apiKey) {
      Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' Order details extraction failed: Incomplete or missing configuration.'), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      return FALSE;
    }

    /* Create the URL. */
    $url = $url . '/order/' . $orderId;

    /* Make the server request. */
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: application/json', 'Authorization: ' . $apiKey]);
    /* Send the request. */
    $response = curl_exec($ch);
    curl_close($ch);
    /* Decode the response. */
    $response = json_decode($response);

    /* Check if the decryption was successful, the response code is 200 and message is 'Success'. */
    if ((NULL !== $response) && (200 == $response->code) && ('Success' == $response->message)) {
      return $response->data->orderStatus;
    } else {
      Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' Order details extraction failed: Server returned error: %s', $response->message), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      return FALSE;
    }
  }


  /**
   * Function that cancels a recurring profile
   *  in the Twispay platform.
   *
   * @param profile: The recurring profile order for which to update the status.
   * @param recurringProfileChildOrder: Child order of the recurring profile.
   * @param message: The cancel reason.
   */
  public function cancelRecurringProfile($profile, $recurringProfileChildOrder, $message){
    /* Get the config values. */
    $apiKey = Mage::helper('tpay')->getApiKey();
    Mage::Log(__FUNCTION__ . ': apiKey=' . print_r($apiKey, true), Zend_Log::DEBUG, $this->logFileName);
    $url = Mage::helper('tpay')->getApiUrl();
    Mage::Log(__FUNCTION__ . ': url=' . print_r($url, true), Zend_Log::DEBUG, $this->logFileName);

    if ('' == $apiKey) {
      Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' Subscription cancel failed: Incomplete or missing configuration.'), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      return;
    }

    /* Create the URL. */
    $url = $url . '/order/' . $profile->getReferenceId();

    /* Create the DELETE data arguments. */
    $postData = 'message=' . $message;

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
      /* Set recurring profile status. */
      $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_CANCELED, true);
      $profile->save();

      /* Set recurring profile child order status. */
      $this->setOrderState( $recurringProfileChildOrder
                          , Mage_Sales_Model_Order::STATE_CANCELED
                          , Mage_Sales_Model_Order::STATE_CANCELED
                          , Mage::helper('tpay')->__(' Order #%s canceled as recurring profile #%s has been canceled.', $recurringProfileChildOrder->getIncrementId(), $profile->getId()));
    } else {
      Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' Subscription cancel failed: Server returned error: %s', $response->message), Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      return;
    }
  }
  /************************** Api END **************************/



  /************************** Notification START **************************/
  /**
   * Get the `jsonRequest` parameter (order parameters as JSON and base64 encoded).
   *
   * @param orderData: Array containing the order parameters.
   *
   * @return string
   */
  public function getBase64JsonRequest(array $orderData) {
    return base64_encode(json_encode($orderData));
  }


  /**
   * Get the `checksum` parameter (the checksum computed over the `jsonRequest` and base64 encoded).
   *
   * @param orderData: Array containing the order parameters.
   * @param secretKey: The secret key (from Twispay).
   *
   * @return string
   */
  public function getBase64Checksum(array $orderData, $secretKey) {
    $hmacSha512 = hash_hmac(/*algo*/'sha512', json_encode($orderData), $secretKey, /*raw_output*/true);
    return base64_encode($hmacSha512);
  }
  /************************** Notification END **************************/



  /************************** Response START **************************/
  /**
   * Decrypt the response from Twispay server.
   *
   * @param tw_encryptedMessage: - The encripted server message.
   * @param tw_secretKey:        - The secret key (from Twispay).
   *
   * @return Array([key => value,]) - If everything is ok array containing the decrypted data.
   *         bool(FALSE)            - If decription fails.
   */
  public function twispay_tw_decrypt_message($tw_encryptedMessage, $tw_secretKey) {
    $encrypted = (string)$tw_encryptedMessage;

    if(!strlen($encrypted) || (FALSE === strpos($encrypted, ','))) {
      return FALSE;
    }

    /* Get the IV and the encrypted data */
    $encryptedParts = explode(/*delimiter*/',', $encrypted, /*limit*/2);
    $iv = base64_decode($encryptedParts[0]);
    if(FALSE === $iv) {
      return FALSE;
    }

    $encryptedData = base64_decode($encryptedParts[1]);
    if(FALSE === $encryptedData) {
      return FALSE;
    }

    /* Decrypt the encrypted data */
    $decryptedResponse = openssl_decrypt($encryptedData, /*method*/'aes-256-cbc', $tw_secretKey, /*options*/OPENSSL_RAW_DATA, $iv);
    if(FALSE === $decryptedResponse) {
      return FALSE;
    }

    /* JSON decode the decrypted data. */
    $decodedResponse = json_decode($decryptedResponse, /*assoc*/TRUE, /*depth*/4);

    /* Check if the decryption was successful. */
    if (NULL === $decodedResponse) {
      return FALSE;
    }

    /** Check if externalOrderId uses '_' separator */
    if (FALSE !== strpos($decodedResponse['externalOrderId'], '_')) {
      $explodedVal = explode('_', $decodedResponse['externalOrderId'])[0];

      /** Check if externalOrderId contains only digits and is not empty. */
      if (!empty($explodedVal) && ctype_digit($explodedVal)) {
        $decodedResponse['externalOrderId'] = $explodedVal;
      }
    }

    return $decodedResponse;
  }


  /**
   * Function that validates a decripted response.
   *
   * @param tw_response The server decripted and JSON decoded response
   *
   * @return bool(FALSE)     - If any error occurs
   *         bool(TRUE)      - If the validation is successful
   */
  public function twispay_tw_checkValidation($tw_response) {
    $tw_errors = array();

    if(!$tw_response) {
      return FALSE;
    }

    if(empty($tw_response['transactionStatus'])) {
      $tw_errors[] = Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Empty status');
    }

    if(empty($tw_response['identifier'])) {
      $tw_errors[] = Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Empty identifier');
    }

    if(empty($tw_response['externalOrderId'])) {
      $tw_errors[] = Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Empty externalOrderId');
    }

    if(empty($tw_response['transactionId'])) {
      $tw_errors[] = Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Empty transactionId');
    }

    if(sizeof($tw_errors)) {
      foreach($tw_errors as $err) {
        Mage::Log(__FUNCTION__ . $err, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      }

      return FALSE;
    } else {
      $data = [ 'externalOrderId' => explode('_', $tw_response['externalOrderId'])[0]
              , 'status'          => $tw_response['transactionStatus']
              , 'identifier'      => $tw_response['identifier']
              , 'orderId'         => (int)$tw_response['orderId']
              , 'transactionId'   => (int)$tw_response['transactionId']
              , 'customerId'      => (int)$tw_response['customerId']
              , 'cardId'          => (!empty($tw_response['cardId'])) ? (( int )$tw_response['cardId']) : (0)];

      Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Data: ') . json_encode($data), Zend_Log::NOTICE , $this->logFileName);

      if(!in_array($data['status'], $this->resultStatuses)){
        Mage::Log(Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Wrong status: ') . $data['status'], Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);

        return FALSE;
      }

      Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE]: Validating completed for order ID: ') . $data['externalOrderId'], Zend_Log::NOTICE , $this->logFileName);

      return TRUE;
    }
  }


  /**
   * Function that adds a new transaction to the order.
   *
   * @param order: The order to which to add the transaction.
   * @param serverResponse: Array containing the server decripted response.
   */
  public function addOrderTransaction($order, $serverResponse){
    /* Save the payment transaction. */
    $payment = $order->getPayment();
    $payment->setTransactionId($serverResponse['transactionId']);
    $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE, null, false, 'OK');
    $transaction->setAdditionalInformation( Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS
                                          , [ 'identifier'    => $serverResponse['identifier']
                                            , 'status'        => $serverResponse['status']
                                            , 'orderId'       => $serverResponse['orderId']
                                            , 'transactionId' => $serverResponse['transactionId']
                                            , 'customerId'    => $serverResponse['customerId']
                                            , 'cardId'        => $serverResponse['cardId']
                                            , 'storeId'       => Mage::app()->getStore()->getStoreId()]);
    $payment->setIsTransactionClosed(TRUE);
    $transaction->save();
    $order->save();
  }


  /**
   * Function that adds a new invoice for the init fee and
   *  trial period to the order.
   *
   * @param order: The order to which to add the transaction invoice.
   * @param transactionId: The ID of the transaction.
   */
  public function addTrialInvoice($order, $transactionId){
    /* Construct the invoice items array an the total cost. */
    $trialItems = [];
    $totalCost = $order->getGrandTotal();
    foreach ($order->getAllItems() as $item) {
      if(('initial_fee' === $item->getSku()) || ('trial_fee' === $item->getSku())){
        $trialItems[$item->getId()] = 1;
        $totalCost += $item->getBasePrice();
      }
    }

    /* Create 'Pending' state invoice. */
    $invoiceId = Mage::getModel('sales/order_invoice_api')->create($order->getIncrementId(), $trialItems);
    $invoice = Mage::getModel('sales/order_invoice')->loadByIncrementId($invoiceId);

    /* Add the transaction to the invoice and pay the invoice. */
    $invoice->setTransactionId($transactionId);

    /* Pay invoice. */
    $invoice->capture()->save();

    /* Set the order amount. */
    $order->setSubtotal($totalCost)
          ->setGrandTotal($totalCost)
          ->setBaseSubtotal($totalCost)
          ->setBaseGrandTotal($totalCost)
          ->setTotalInvoiced($totalCost)
          ->setTotalPaid($totalCost)
          ->setBaseTotalInvoiced($totalCost)
          ->setSubtotalInvoiced($totalCost)
          ->setBaseTotalPaid($totalCost);
    $order->save();
  }


  /**
   * Function that adds a new invoice for a normal period to the order.
   *
   * @param order: The order to which to add the transaction invoice.
   * @param transactionId: The ID of the transaction.
   */
  public function addNormalInvoice($order, $transactionId){
    /* Construct the invoice items array an the total cost. */
    $normalItems = [];
    $totalCost = $order->getGrandTotal();
    foreach ($order->getAllItems() as $item) {
      if(('initial_fee' !== $item->getSku()) && ('trial_fee' !== $item->getSku())){
        $normalItems[$item->getId()] = 1;
        $totalCost += $item->getBasePrice();
      }
    }

    /* Create 'Pending' state invoice. */
    $invoiceId = Mage::getModel('sales/order_invoice_api')->create($order->getIncrementId(), $normalItems);
    $invoice = Mage::getModel('sales/order_invoice')->loadByIncrementId($invoiceId);

    /* Add the transaction to the invoice and pay the invoice. */
    $invoice->setTransactionId($transactionId);

    /* Pay invoice. */
    $invoice->capture()->save();

    /* Set the order amount. */
    $order->setSubtotal($totalCost)
          ->setGrandTotal($totalCost)
          ->setBaseSubtotal($totalCost)
          ->setBaseGrandTotal($totalCost)
          ->setTotalInvoiced($totalCost)
          ->setTotalPaid($totalCost)
          ->setBaseTotalInvoiced($totalCost)
          ->setSubtotalInvoiced($totalCost)
          ->setBaseTotalPaid($totalCost);
    $order->save();
  }


  /**
   * Function that adds a transaction to an invoice.
   *
   * @param order: The order that has the transaction and the invoice.
   * @param transactionId: The ID of the transaction.
   */
  public function addPurchaseInvoice($order, $transactionId){
    /* Add the transaction to the invoice. */
    $invoice = $order->getInvoiceCollection()->addAttributeToSort('created_at', 'DSC')->setPage(1, 1)->getFirstItem();
    $invoice->setTransactionId($transactionId);
    $invoice->save();
  }


  /**
   * Function that adds a new order to a recurring profile.
   *
   * @param profile: The recurrent profile.
   */
  public function createRecurringProfileChildOrder($profile){
    /* Create and save a new order. */
    $order = $profile->createOrder();
    $order->save();

    /* Get the order item data and the product. */
    $orderItemInfo = $profile->getOrderItemInfo();
    $product = Mage::getModel('catalog/product')->loadByAttribute('entity_id', $orderItemInfo['product_id']);

    /* Create initial item. */
    $initOrderItem = Mage::getModel('sales/order_item')
                         ->setStoreId(Mage::app()->getStore()->getStoreId())
                         ->setQuoteItemId(NULL)
                         ->setQuoteParentItemId(NULL)
                         ->setProductId($orderItemInfo['product_id'])
                         ->setProductType($orderItemInfo['product_type'])
                         ->setQtyBackordered(NULL)
                         ->setTotalQtyOrdered(1)
                         ->setQtyOrdered(1)
                         ->setName($product->getName())
                         ->setSku('initial_fee')
                         ->setPrice($profile->getInitAmount())
                         ->setBasePrice($profile->getInitAmount())
                         ->setOriginalPrice($profile->getInitAmount())
                         ->setRowTotal($profile->getInitAmount())
                         ->setBaseRowTotal($profile->getInitAmount())
                         ->setOrder($order);
    $initOrderItem->save();

    /* Create trial item. */
    $trialOrderItem = Mage::getModel('sales/order_item')
                          ->setStoreId(Mage::app()->getStore()->getStoreId())
                          ->setQuoteItemId(NULL)
                          ->setQuoteParentItemId(NULL)
                          ->setProductId($orderItemInfo['product_id'])
                          ->setProductType($orderItemInfo['product_type'])
                          ->setQtyBackordered(NULL)
                          ->setTotalQtyOrdered(1)
                          ->setQtyOrdered(1)
                          ->setName($product->getName())
                          ->setSku('trial_fee')
                          ->setPrice($profile->getTrialBillingAmount())
                          ->setBasePrice($profile->getTrialBillingAmount())
                          ->setOriginalPrice($profile->getTrialBillingAmount())
                          ->setRowTotal($profile->getTrialBillingAmount())
                          ->setBaseRowTotal($profile->getTrialBillingAmount())
                          ->setOrder($order);
    $trialOrderItem->save();

    /* Create period item. */
    $periodOrderItem = Mage::getModel('sales/order_item')
                           ->setStoreId(Mage::app()->getStore()->getStoreId())
                           ->setQuoteItemId(NULL)
                           ->setQuoteParentItemId(NULL)
                           ->setProductId($orderItemInfo['product_id'])
                           ->setProductType($orderItemInfo['product_type'])
                           ->setQtyBackordered(NULL)
                           ->setTotalQtyOrdered(1)
                           ->setQtyOrdered(1)
                           ->setName($product->getName())
                           ->setSku($product->getSku())
                           ->setPrice($profile->getBillingAmount())
                           ->setBasePrice($profile->getBillingAmount())
                           ->setOriginalPrice($profile->getBillingAmount())
                           ->setRowTotal($profile->getBillingAmount())
                           ->setBaseRowTotal($profile->getBillingAmount())
                           ->setOrder($order);
    $periodOrderItem->save();

    /* Calculate the order amount. */
    $amount = $profile->getShippingAmount() + $profile->getTaxAmount();
    /* Set the order amount. */
    $order->setSubtotal($amount)
          ->setGrandTotal($amount)
          ->setBaseSubtotal($amount)
          ->setBaseGrandTotal($amount)
          ->setTotalInvoiced($amount)
          ->setTotalPaid($amount)
          ->setBaseTotalPaid($amount)
          ->setSubtotalInvoiced($amount)
          ->setTotalOnlineRefunded(0)
          ->setTotalRefunded(0)
          ->setTotalDue(0)
          ->setBaseTotalInvoicedCost(0)
          ->setBaseTotalOnlineRefunded(0)
          ->setBaseTotalRefunded(0)
          ->setBaseTotalDue(0);
    $order->save();

    /* Add the new order to the profile. */
    $profile->addOrderRelation($order->getId());
  }


  /**
   * Function that extracts the child order of a recurring profile.
   *
   * @param profile: The recurrent profile from ehich to extract the order.
   *
   * @return Mage_Sales_Model_Order  - The child order of the recurring profile.
   */
  public function getRecurringProfileChildOrder($profile){
    /* Extract the related order. */
    return Mage::getModel('sales/order')->loadByAttribute('entity_id', $profile->getChildOrderIds()[0]);
  }
  /************************** Response END **************************/



  /************************** Status Update START **************************/
  /**
   * Update the status of an order according to the received server status.
   * An order can be either a normal purchase or a recurring profile.
   *
   * @param profile: The recurring profile.
   * @param order: The order in case of a purchase and the recurring profile
   *                child order in case of a subscription.
   * @param orderId: The unique Twispay order ID of the recurring profile.
   * @param transactionId: The ID of the transaction.
   * @param serverStatus: The status received from server.
   * @param identifier: The unique identifier of the twispay request.
   *
   * @return bool(FALSE) - If server status in: [COMPLETE_FAIL, THREE_D_PENDING]
   *         bool(TRUE)  - If server status in: [IN_PROGRESS, COMPLETE_OK]
   */
  public function updateStatus_backUrl($profile, $order, $orderId, $transactionId, $serverStatus, $identifier){
    if('p' == $identifier){
      return $this->updateStatus_purchase_backUrl($order, $transactionId, $serverStatus);
    } elseif ('r' == $identifier) {
      return $this->updateStatus_recurringProfile_backUrl($profile, $order, $orderId, $transactionId, $serverStatus);
    } else {
      $message = Mage::helper('tpay')->__(' Invalid order identifier.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError($message);
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }
  }


  /**
   * Update the status of an order according to the received server status.
   * An order can be either a normal purchase or a recurring profile.
   *
   * @param profile: The recurring profile.
   * @param order: The order in case of a purchase and the recurring profile
   *                child order in case of a subscription.
   * @param orderId: The unique Twispay order ID of the recurring profile.
   * @param transactionId: The ID of the transaction.
   * @param serverStatus: The status received from server.
   * @param identifier: The unique identifier of the twispay request.
   *
   * @return bool(FALSE) - If server status in: [COMPLETE_FAIL, CANCEL_OK, VOID_OK, CHARGE_BACK, THREE_D_PENDING]
   *         bool(TRUE)  - If server status in: [REFUND_OK, IN_PROGRESS, COMPLETE_OK]
   */
  public function updateStatus_IPN($profile, $order, $orderId, $transactionId, $serverStatus, $identifier){
    if('p' == $identifier){
      return $this->updateStatus_purchase_IPN($order, $transactionId, $serverStatus);
    } elseif ('r' == $identifier) {
      return $this->updateStatus_recurringProfile_IPN($profile, $order, $orderId, $transactionId, $serverStatus);
    } else {
      $message = Mage::helper('tpay')->__(' Invalid order identifier.');
      Mage::log(__FUNCTION__ . $message, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      Mage::getSingleton('core/session')->addError($message);
      $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
    }
  }


  /**
   * Update the status of a recurring profile order according to the extracted server status.
   *
   * @param profile: The recurring profile order for which to update the status.
   * @param recurringProfileChildOrder: Child order of the recurring profile.
   * @param serverStatus: The status received from server.
   */
  public function updateProfileStatus($profile, $recurringProfileChildOrder, $serverStatus){
    switch ($serverStatus) {
      case $this->resultStatuses['COMPLETE_FAIL']: /* The subscription has payment failure. */
      case $this->resultStatuses['THREE_D_PENDING']: /* The subscription has a 3D pending payment. */
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_PENDING, true);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
                            , Mage::helper('tpay')->__(' Order #%s pending based on server status.', $recurringProfileChildOrder->getIncrementId()));
      break;

      case $this->resultStatuses['COMPLETE_OK']: /* The subscription has been completed. */
      case $this->resultStatuses['CANCEL_OK']: /* The subscription has been canceled. */
      case $this->resultStatuses['REFUND_OK']: /* The subscription has been refunded. */
      case $this->resultStatuses['VOID_OK']: /* The subscription has been voided. */
      case $this->resultStatuses['CHARGE_BACK']: /* The subscription has been forced back. */
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_CANCELED, true);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage_Sales_Model_Order::STATE_CANCELED
                            , Mage::helper('tpay')->__(' Order #%s canceled on periodic sync based on server status.', $recurringProfileChildOrder->getIncrementId()));
      break;

      case $this->resultStatuses['EXPIRING']: /* The subscription will expire soon. */
      case $this->resultStatuses['IN_PROGRESS']: /* The subscription is in progress. */
        /* Set recurring profile status. */
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE, true);
        $profile->save();

        /* Set recurring profile child order status. */
        $this->setOrderState( $recurringProfileChildOrder
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage_Sales_Model_Order::STATE_PROCESSING
                            , Mage::helper('tpay')->__(' Order #%s activated based on server status.', $recurringProfileChildOrder->getIncrementId()));
      break;

      default:
        Mage::Log(__FUNCTION__ . Mage::helper('tpay')->__(' [RESPONSE-ERROR]: Wrong status: ') . $serverStatus, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      break;
    }
  }
  /************************** Status Update END **************************/
}
