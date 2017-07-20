<?php

if (!function_exists('boolval')) {
    function boolval($val) {
            return (bool) $val;
    }
}

class Twispay_Tpay_PaymentController extends Mage_Core_Controller_Front_Action
{
    // Redirect to twispay 
    private $LOG_FILE_NAME = 'tpay.log';

    public function redirectAction()
    {
        try {
            Mage::Log('Step 5 Process: Loading the redirect.html page', Zend_Log::DEBUG, $this->LOG_FILE_NAME);
            $this->loadLayout();
            // Get latest order data
            $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            

            // Set status to payment pending
            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true)->save();
            $amount = $order-> getBaseGrandTotal();
            $email = $order->getCustomerEmail();
            $name = $order->getCustomerName(); 
            
            $billingAddress     = $order->getBillingAddress();
            $street = $billingAddress->getStreet();
            $firstName = $billingAddress->getFirstname();
            $lastName = $billingAddress->getLastname();
            $country = $billingAddress->getCountry();
            $city = $billingAddress->getCity();
            $zipCode = $billingAddress->getPostcode();
            $state = ($billingAddress->getCountryId() == 'US' && $billingAddress->getRegionCode() != null) ? $billingAddress->getRegionCode() : '';
            $currency = $order->getOrderCurrencyCode();
            
            
            $items = array();
            $units = array();
            $unitPrice = array();
            $subTotal = array();
            foreach ($order->getAllItems() as $key => $item) {
            	$items[$key] = $item->getName();
            	$subTotal[$key] = strval(number_format((float)$item->getRowTotalInclTax(), 2, '.', ''));
            	$unitPrice[$key] = strval(number_format((float)$item->getPriceInclTax(), 2, '.', ''));
            	$units[$key] = (int)$item->getQtyOrdered();
            }
            
            // Add the shipping price
            
            if ($order->getShippingAmount() > 0) {
            	$index             = count($items);
            	$items[$index]     = "Transport";
            	$unitPrice[$index] = strval(number_format((float) $order->getShippingAmount(), 2, '.', ''));;
            	$units[$index]     = "1";
            	$subTotal[$index]  = strval(number_format((float) $order->getShippingAmount(), 2, '.', ''));
            }
            
            
            $phone = substr(str_replace(' ', '', $billingAddress->getTelephone()), 0, 20);
            $rmTranid = time();

            $index = strpos($amount, '.');
            if ($index !== False){
                $amount = substr($amount, 0, $index+3);  
            }

            $storeId = Mage::app()->getStore()->getStoreId();
            $storeCode = Mage::app()->getStore()->getCode();
            Mage::log("Store ID and Code: $storeId | $storeCode", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
            $url = Mage::getStoreConfig('payment/tpay/payment_url', $storeId);
            $api_key = Mage::getStoreConfig('payment/tpay/api_key', $storeId);
            $site_id = Mage::getStoreConfig('payment/tpay/site_id', $storeId);
            Mage::log("Data from Backend: $url | $api_key | $site_id", Zend_Log::DEBUG, $this->LOG_FILE_NAME);

            
            $siteUrl = Mage::getBaseUrl();
            $data = Array();
            $data['address'] = $street[0];
            $data['amount'] = $amount;
            $data['backUrl'] = $siteUrl."/tpay/payment/response/";
            $data['cardTransactionMode'] = "authAndCapture";
            $data['city'] = $city;
            $data['country'] = $country;
            $data['currency'] = $currency;
            $data['description'] = "Processing order: ".$orderId;
            $data['email'] = $email;
            $data['firstName'] = $firstName;
            $data['identifier'] = "_".$rmTranid;
            $data['item'] = $items;
            $data['lastName'] = $lastName;
            $data['orderId'] = $orderId;
            $data['orderType'] = "purchase";
            $data['phone'] = $phone;
            $data['siteId'] = $site_id;
            $data['state'] = $state;
            $data['subTotal'] = $subTotal;
            $data['unitPrice'] = $unitPrice;
            $data['units'] = $units;
            $data['zipCode'] = $zipCode;
            
            
            $checksum = $this->computeChecksum($data,$api_key);
            

            $data['checksum'] = $checksum;

            Mage::log("Transaction-order ID: " . ($rmTranid . "-". $orderId), Zend_Log::DEBUG, $this->LOG_FILE_NAME);

            $ver = explode('.', phpversion());
            $major = (int) $ver[0];
            $minor = (int) $ver[1];
            if($major >= 5 and $minor >= 4){
                ksort($data, SORT_STRING | SORT_FLAG_CASE);
            }
            else{
                uksort($data, 'strcasecmp');
            }

            $message = implode('|', $data);

            $link = $url;
            $payment = $order->getPayment();
            $payment->setTransactionId($rmTranid); // Make it unique.
            $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH,
                                                    null,
                                                    false,
                                                    'OK');
            $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
                                                   array('Context'=>'Token payment',
                                                         'Amount'=>$amount,
                                                         'Status'=>0,
                                                         'Url'=>$link));
            $transaction->setIsTransactionClosed(false); // Close the transaction on return?
            $transaction->save();
            $order->save();

            $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'tpay', array('template' => 'tpay/redirect.phtml'))
                          ->assign(array_merge($data, array('url'=>$url)));
            $this->getLayout()->getBlock('content')->append($block);
            $this->renderLayout();
        } catch (Exception $e){
            Mage::logException($e);
            Mage::log($e, Zend_Log::ERR, $this->LOG_FILE_NAME);
            parent::_redirect('checkout/cart');
        }
    }

    // Redirect from Twispay
    // The response action is triggered when your gateway sends back a response after processing the customer's payment
    public function responseAction() {

        Mage::log("Running response action", Zend_Log::DEBUG, $this->LOG_FILE_NAME); 

        $storeId = Mage::app()->getStore()->getStoreId();
        $storeCode = Mage::app()->getStore()->getCode();
        Mage::log("Store ID and Code: $storeId | $storeCode", Zend_Log::DEBUG, $this->LOG_FILE_NAME);

        $response = $this->getRequest()->getPost('opensslResult');

        $api_key = Mage::getStoreConfig('payment/tpay/api_key', $storeId);
        
        
        $result = null;
        if ($response) {
        	Mage::log("We got a response", Zend_Log::DEBUG, $this->LOG_FILE_NAME); 
        	try {

        		$result = $this->decryptResponse($response, $api_key);

        		if ($result != null) {
        			$result = json_decode($result);
        			Mage::log(print_r($result, true), Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        			
        			
        			
        			
        			 
        		} else {
        			Mage::log("Decoded response is NULL", Zend_Log::DEBUG, $this->LOG_FILE_NAME); 
        					
        		}
        
        	} catch (LocalizedException $ex) {
        		Mage::log($ex->getMessage(), Zend_Log::DEBUG, $this->LOG_FILE_NAME); 
        				
        	}
        }
        else
        {

        	Mage::log("response(opensslResult) is null", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        	 
        }
        
        if ($result && isset($result->status) && ($result->status == 'complete-ok' || $result->status == 'in-progress')) {
        
        	// Set the status of this order to processing
        	$orderId = $result->externalOrderId;
        	$status = $result->status;
        	Mage::log("Status: $status| Payment ID: $orderId", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        	
        	try {

        	$order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        	$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
        	$order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
        	$order->addStatusToHistory($order->getStatus(), 'Order paid successfully with reference '.$result->transactionId);
        	$order->save();
        	} catch (LocalizedException $ex) {
        		Mage::log($ex->getMessage(), Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        	
        	}
        
        	Mage::log("Payment has been successfully authorized. Transaction id: $result->transactionId", Zend_Log::DEBUG, $this->LOG_FILE_NAME); 
        			
        
        	$this->_redirect('checkout/onepage/success', array('_secure'=>true));
        } else {
        	Mage::log("Failed to complete payment for $result->transactionId", Zend_Log::DEBUG, $this->LOG_FILE_NAME); 
        			
        	$this->_redirect('checkout/onepage/failure', array('_secure'=>true));
        }
    }
        
        // Server-2-server page for Twispay to re-send payment information
        // The server action is triggered when your gateway sends back a response after processing the customer's payment
        public function serverAction() {
        
        	Mage::log("Running Server-2-server action", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        
        	$storeId = Mage::app()->getStore()->getStoreId();
        	$storeCode = Mage::app()->getStore()->getCode();
        	Mage::log("Store ID and Code: $storeId | $storeCode", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        
        	$response = $this->getRequest()->getPost('opensslResult');
        
        	$api_key = Mage::getStoreConfig('payment/tpay/api_key', $storeId);
        
        	$result = null;
        	if ($response) {
        		try {
        
        			$result = $this->decryptResponse($response, $api_key);
        
        			if ($result != null) {
        				$result = json_decode($result);
        			} else {
        				Mage::log("Decoded response is NULL", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        				 
        			}
        
        		} catch (LocalizedException $ex) {
        			Mage::log($ex->getMessage(), Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        
        		}
        	}
        
        	if ($result && isset($result->status) && ($result->status == 'complete-ok' || $result->status == 'in-progress')) {
        
        		// Set the status of this order to processing
        		$orderId = $result->externalOrderId;
        		$status = $result->status;
        		Mage::log("Status: $status| Payment ID: $orderId", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        		 
        		try {
        
        			$order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
        			$order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
        			$order->addStatusToHistory($order->getStatus(), 'Order paid successfully with reference '.$result->transactionId);
        			$order->save();
        		} catch (LocalizedException $ex) {
        			Mage::log($ex->getMessage(), Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        			 
        		}
        
        		Mage::log("Payment has been successfully authorized. Transaction id: $result->transactionId", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        		 
        
        	} else {
        		Mage::log("Failed to complete payment for $result->transactionId", Zend_Log::DEBUG, $this->LOG_FILE_NAME);
        		 
        	}
       
        
    }
    /**
     * This method computes the checksum on the given data array
     *
     * @param string $encrypted
     * @return array the decrypted response
     */
    public function decryptResponse($encrypted, $apiKey) {
    
    	$encrypted = (string)$encrypted;
    	if (!strlen($encrypted)) {
    		return null;
    	}
    
    	if (strpos($encrypted, ',') !== false) {
    		$encryptedParts = explode(',', $encrypted, 2);
    		$iv = base64_decode($encryptedParts[0]);
    		if (false === $iv) {
    			throw new LocalizedException("Invalid encryption iv");
    		}
    		$encrypted = base64_decode($encryptedParts[1]);
    		if (false === $encrypted) {
    			throw new LocalizedException("Invalid encrypted data");
    		}
    		$decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $apiKey, OPENSSL_RAW_DATA, $iv);
    		if (false === $decrypted) {
    			throw new LocalizedException("Data could not be decrypted");
    		}
    
    		return $decrypted;
    	}
    
    	return null;
    }
    
    /**
     * This method computes the checksum on the given data array
     *
     * @param array $data
     * @return string the computed checksum
     */
    public function computeChecksum(array &$data, $apiKey) {
    
    	// Sort the keys in the object alphabetically
    	$this->recursiveKeySort($data);
    
    
    	// Build an encoded HTTP query string from the data
    	$query = http_build_query($data);
    
    	// Encrypt the query string with SHA-512 algorithm
    	$encoded = hash_hmac('sha512', $query, $apiKey, true);
    
    	$checksum = base64_encode($encoded);
    
    	return $checksum;
    }
    

    /**
     * Sort the array based on the keys
     * @param array $data
     */
    private function recursiveKeySort(array &$data) {
    	ksort($data, SORT_STRING);
    	foreach ($data as $key => $value) {
    		if (is_array($value)) {
    			$this->recursiveKeySort($data[$key]);
    		}
    	}
    }

} 
