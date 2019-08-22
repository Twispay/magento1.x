<?php

class Twispay_Tpay_Model_Observer {
  private $logFileName = 'tpay.log';


  /**
   * 
   */
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


  

  /**
   * Function that updates the statuses of the local recurring profile
   *  based on the status from the Twispay platform.
   *
   * @return Twispay_Tpay_Model_Observer
   */
  public function profileSync(){
    /* Extract all profiles that have the payment method set to Twispay
       and are not canceled, expired or have an unknown state. */
    $profiles = Mage::getModel('sales/recurring_profile')
                    ->getCollection()
                    ->addFieldToFilter( ['method_code', 'state']
                                      , [ ['eq' => 'tpay']
                                        , ['nin' => [ Mage_Sales_Model_Recurring_Profile::STATE_UNKNOWN
                                                    , Mage_Sales_Model_Recurring_Profile::STATE_CANCELED
                                                    , Mage_Sales_Model_Recurring_Profile::STATE_EXPIRED
                                                    ]
                                          ]
                                        ]
                                      );

    foreach ($profiles as $profile) {
      if ($profile->getReferenceId()) {
        $serverStatus = Mage::helper('tpay')->getServerOrderStatus($profile->getReferenceId());

        if ($serverStatus) {
          $serverStatus = Mage::helper('tpay')->updateProfileStatus($profile, Mage::helper('tpay')->getRecurringProfileChildOrder($profile), $serverStatus);
        }
      }
    }

    return $this;
  }
}
