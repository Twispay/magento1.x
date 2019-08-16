<?php

class Twispay_Tpay_Model_Observer {
  private $logFileName = 'tpay.log';

  public function checkoutSubmitAllFunction(Varien_Event_Observer $observer){
    Mage::log(__FUNCTION__, Zend_Log::DEBUG, $this->logFileName);

    if(sizeof($observer->getEvent()->getData()['recurring_profiles'])){
      $recurringProfileId = $observer->getEvent()->getData()['recurring_profiles'][0]['profile_id'];

      if($recurringProfileId){
        /* Construct the redirect URL. */
        $redirectUrl = Mage::getUrl('tpay/payment/profile', ['_query' => ['profileId' => $recurringProfileId]]);
        /* Set the redirect URL. */
        Mage::getSingleton('checkout/session')->setRedirectUrl($redirectUrl);
      }
    }
  }

}
