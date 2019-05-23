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
  /* The error and success logging messages. */
  private $messages = [
    'log_ok_response_data' => '[RESPONSE]: Data: ',
    'log_ok_validating_complete' => '[RESPONSE]: Validating completed for order ID: ',
    'log_ok_status_complete' => '[RESPONSE]: Status complete-ok for order ID: ',
    'log_ok_status_refund' => '[RESPONSE]: Status refund-ok for order ID: ',
    'log_ok_status_canceled' => '[RESPONSE]: Status cancel-ok for order ID: ',
    'log_ok_status_failed' => '[RESPONSE]: Status failed for order ID: ',
    'log_ok_status_hold' => '[RESPONSE]: Status on-hold for order ID: ',
    'log_ok_status_uncertain' => '[RESPONSE]: Status uncertain for order ID: ',

    'log_error_wrong_status' => '[RESPONSE-ERROR]: Wrong status: ',
    'log_error_empty_status' => '[RESPONSE-ERROR]: Empty status',
    'log_error_empty_identifier' => '[RESPONSE-ERROR]: Empty identifier',
    'log_error_empty_external' => '[RESPONSE-ERROR]: Empty externalOrderId',
    'log_error_empty_transaction' => '[RESPONSE-ERROR]: Empty transactionId',
  ];
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


  /************************** Notification START **************************/
  /**
   * Get the `jsonRequest` parameter (order parameters as JSON and base64 encoded).
   *
   * @param array $orderData The order parameters.
   *
   * @return string
   */
  public function getBase64JsonRequest(array $orderData) {
    return base64_encode(json_encode($orderData));
  }


  /**
   * Get the `checksum` parameter (the checksum computed over the `jsonRequest` and base64 encoded).
   *
   * @param array $orderData The order parameters.
   * @param string $secretKey The secret key (from Twispay).
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
   * @param string $tw_encryptedMessage - The encripted server message.
   * @param string $tw_secretKey        - The secret key (from Twispay).
   *
   * @return Array([key => value,]) - If everything is ok array containing the decrypted data.
   *         bool(FALSE)            - If decription fails.
   */
  public function twispay_tw_decrypt_message($tw_encryptedMessage, $tw_secretKey) {
    $encrypted = (string)$tw_encryptedMessage;

    if(!strlen($encrypted) || (FALSE == strpos($encrypted, ','))) {
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
    return json_decode($decryptedResponse, /*assoc*/TRUE, /*depth*/4);
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

    if(empty($tw_response['status']) && empty($tw_response['transactionStatus'])) {
      $tw_errors[] = $this->messages['log_error_empty_status'];
    }

    if(empty($tw_response['identifier'])) {
      $tw_errors[] = $this->messages['log_error_empty_identifier'];
    }

    if(empty($tw_response['externalOrderId'])) {
      $tw_errors[] = $this->messages['log_error_empty_external'];
    }

    if(empty($tw_response['transactionId'])) {
      $tw_errors[] = $this->messages['log_error_empty_transaction'];
    }

    if(sizeof($tw_errors)) {
      foreach($tw_errors as $err) {
        Mage::Log($err, Zend_Log::ERR, $this->logFileName, /*forceLog*/TRUE);
      }

      return FALSE;
    } else {
      $data = [ 'id_cart'       => explode('_', $tw_response['externalOrderId'])[0]
              , 'status'        => (empty($tw_response['status'])) ? ($tw_response['transactionStatus']) : ($tw_response['status'])
              , 'identifier'    => $tw_response['identifier']
              , 'orderId'       => (int)$tw_response['orderId']
              , 'transactionId' => (int)$tw_response['transactionId']
              , 'customerId'    => (int)$tw_response['customerId']
              , 'cardId'        => (!empty($tw_response['cardId'])) ? (( int )$tw_response['cardId']) : (0)];

      Mage::Log($this->messages['log_ok_response_data'] . json_encode($data), Zend_Log::NOTICE , $this->logFileName);

      if(!in_array($data['status'], $this->resultStatuses)){
        Mage::Log($this->messages['log_error_wrong_status'] . $data['status'], Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);

        Mage::Log($this->messages['log_ok_response_data'] . json_encode($data), Zend_Log::NOTICE , $this->logFileName);

        return FALSE;
      }

      Mage::Log($this->messages['log_ok_validating_complete'] . $data['id_cart'], Zend_Log::NOTICE , $this->logFileName);

      return TRUE;
    }
  }
  /************************** Response END **************************/



  /************************** Status Update START **************************/
  /**
   * Update the status of an order according to the received server status.
   *
   * @param orderId: The id of the order for which to update the status.
   * @param serverStatus: The status received from server.
   * @param transactionId: The unique transaction ID of the order.
   *
   * @return bool(FALSE)     - If server status in: [COMPLETE_FAIL, THREE_D_PENDING]
   *         bool(TRUE)      - If server status in: [IN_PROGRESS, COMPLETE_OK]
   */
  public function updateStatus_backUrl($orderId, $serverStatus, $transactionId){
    /* Extract the order. */
    $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);

    switch ($serverStatus) {
      case $this->resultStatuses['COMPLETE_FAIL']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_CANCELED);
        $order->addStatusToHistory($order->getStatus(), 'Payment failed for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_failed'] . $orderId, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;

      case $this->resultStatuses['THREE_D_PENDING']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $order->addStatusToHistory($order->getStatus(), 'Payment pending for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_hold'] . $orderId, Zend_Log::WARNING , $this->logFileName, /*forceLog*/TRUE);
        return FALSE;
      break;

      case $this->resultStatuses['IN_PROGRESS']:
      case $this->resultStatuses['COMPLETE_OK']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
        $order->addStatusToHistory($order->getStatus(), 'Payment successful for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_complete'] . $orderId, Zend_Log::NOTICE , $this->logFileName, /*forceLog*/TRUE);
        return TRUE;
      break;

      default:
        Mage::Log($this->messages['log_error_wrong_status'] . $orderId, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      break;
    }
  }


  /**
   * Update the status of an Woocommerce subscription according to the received server status.
   *
   * @param orderId: The ID of the order to be updated.
   * @param serverStatus: The status received from server.
   * @param transactionId: The unique transaction ID of the order.
   *
   * @return void
   */
  public function updateStatus_IPN($orderId, $serverStatus, $transactionId){
    /* Extract the order. */
    $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);

    switch ($serverStatus) {
      case $this->resultStatuses['COMPLETE_FAIL']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_CANCELED);
        $order->addStatusToHistory($order->getStatus(), 'Payment failed for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_failed'] . $orderId, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      break;

      case $this->resultStatuses['REFUND_OK']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_CLOSED, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_CLOSED);
        $order->addStatusToHistory($order->getStatus(), 'Payment refunded for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_refund'] . $orderId, Zend_Log::NOTICE , $this->logFileName, /*forceLog*/TRUE);
      break;

      case $this->resultStatuses['CANCEL_OK']:
      case $this->resultStatuses['VOID_OK']:
      case $this->resultStatuses['CHARGE_BACK']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_CANCELED);
        $order->addStatusToHistory($order->getStatus(), 'Payment refunded for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_canceled'] . $orderId, Zend_Log::NOTICE , $this->logFileName, /*forceLog*/TRUE);
      break;

      case $this->resultStatuses['THREE_D_PENDING']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $order->addStatusToHistory($order->getStatus(), 'Payment pending for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_hold'] . $orderId, Zend_Log::WARNING , $this->logFileName, /*forceLog*/TRUE);
      break;

      case $this->resultStatuses['IN_PROGRESS']:
      case $this->resultStatuses['COMPLETE_OK']:
        /* Set order status. */
        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
        $order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
        $order->addStatusToHistory($order->getStatus(), 'Payment successful for order with reference ' . $transactionId);
        $order->save();

        Mage::Log($this->messages['log_ok_status_complete'] . $orderId, Zend_Log::NOTICE , $this->logFileName, /*forceLog*/TRUE);
      break;

      default:
        Mage::Log($this->messages['log_error_wrong_status'] . $orderId, Zend_Log::ERR , $this->logFileName, /*forceLog*/TRUE);
      break;
    }
  }
  /************************** Status Update END **************************/
}
