<?php
class Twispay_Tpay_Block_Form_Tpay extends Mage_Payment_Block_Form 
{
    protected function _construct() 
    {
        parent::_construct ();
        $this->setTemplate ( 'tpay/form/tpay.phtml' );
    }
}