<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_EcorePay
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors.
 */

class Liftmode_EcorePay_Model_Method_EcorePay extends Mage_Payment_Model_Method_Cc
{
    const PAYMENT_METHOD_ECOREPAY_CODE = 'ecorepay';

    protected $_code = self::PAYMENT_METHOD_ECOREPAY_CODE;

    protected $_isGateway                   = true;
    protected $_canOrder                    = true;
    protected $_canAuthorize                = true;
    protected $_canCapture                  = true;
    protected $_isInitializeNeeded          = false;
    protected $_canVoid                     = true;
    protected $_canRefund                   = true;


    /**
     * Authorize payment abstract method
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for authorization.'));
        }

        $payment->setAmount($amount);

        $data = $this->_doSale($payment);

        $payment->setTransactionId($data->TransactionID)
                ->setAdditionalInformation(serialize($data->asXML()))
                ->setIsTransactionClosed(false);

        return $this;
    }


    /**
     * Check void availability
     *
     * @param   Varien_Object $invoicePayment
     * @return  bool
     */
    public function canVoid(Varien_Object $payment)
    {
        return $this->_canVoid;
    }


    /**
     * Capture payment abstract method
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for authorization.'));
        }

        $payment->setAmount($amount);

        $data = $this->_doSale($payment);

        $payment->setTransactionId($data->TransactionID)
                ->setAdditionalInformation($data->asXML())
                ->setIsTransactionClosed(true);

        return $this;
    }


    /**
     * Void payment abstract method
     *
     * @param Varien_Object $payment
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function cancel(Varien_Object $payment)
    {
        return $this->void($payment);
    }


    /**
     * Void payment abstract method
     *
     * @param Varien_Object $payment
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function void(Varien_Object $payment)
    {
        $paymentTransactionId = $this->_getParentTransactionId($payment);

        if (!$paymentTransactionId) {
            Mage::throwException(Mage::helper('paygate')->__('Invalid transaction ID.'));
        }

        $data = array (
            "AccountID" => $this->getAccountId($payment->getCcType()),
            "AccountAuth" => $this->getAuthCode($payment->getCcType()),
            "Transaction" => array(
                "TransactionID"      => $paymentTransactionId,
            )
        );


        $xmlData = $this->arrayToXML($data, new SimpleXMLElement('<Request type="Void" />'), 'child_name_to_replace_numeric_integers');
        list ($resCode, $resData) =  $this->_doPost($xmlData);


        $this->_doValidate($resCode, $resData, $xmlData, 110);

        $payment->setTransactionId($paymentTransactionId)
                ->setIsTransactionClosed(1);

        return $this;
    }

    /**
     * Refund specified amount for payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if (!$this->canRefund()) {
            Mage::throwException(Mage::helper('payment')->__('Refund action is not available.'));
        }

        $data = array (
            "AccountID" => $this->getAccountId($payment->getCcType()),
            "AccountAuth" => $this->getAuthCode($payment->getCcType()),
            "Transaction" => array(
                "Amount"             => $amount,
                "TransactionID"      => $payment->getRefundTransactionId(),
            )
        );


        $xmlData = $this->arrayToXML($data, new SimpleXMLElement('<Request type="Refund" />'), 'child_name_to_replace_numeric_integers');

        $this->_doValidate(...$this->_doPost($xmlData), ...[$xmlData, 110]);

        return $this;
    }

    public function log($data)
    {
        Mage::log($data, null, 'EcorePay.log');
    }


    private function _sanitizeData($data) {
        if (is_string($data)) {
            return  preg_replace(
                        '/<AccountAuth>([^<]*)<\/AccountAuth>/i',
                        '<AccountAuth>***</AccountAuth>',
                        preg_replace(
                            '/<CardNumber>[^<]*([^<]{4})<\/CardNumber>/i',
                            '<CardNumber>***$1</CardNumber>',
                            preg_replace(
                                '/<CardCVV>([^"]*)<\/CardCVV>/i',
                                '<CardCVV>***</CardCVV>',
                                $data
                            )
                        )
                    );
        }

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    return $this->_sanitizeData($v);
                } else {
                    if (in_array($k, array('ccnumber', 'CardNumber')) {
                        $data[$k] = "***" . substr($data[$k], -4);
                    }

                    if (in_array($k, array('cvv', 'CardCVV', 'AccountAuth')) {
                        $data[$k] = "***";
                    }
                }
            }
        }

        return $data;
    }


    /**
     * Parent transaction id getter
     *
     * @param Varien_Object $payment
     * @return string
     */
    private function _getParentTransactionId(Varien_Object $payment)
    {
        return $payment->getParentTransactionId() ? $payment->getParentTransactionId() : $payment->getLastTransId();
    }


    /**
     * Return url of payment method
     *
     * @return string
     */
    private function getUrl()
    {
        return $this->getConfigData('gatewayurl');
    }

    private function getAccountId($type) {
//        return $type == 'MC' ? $this->getConfigData('acidmc') : $this->getConfigData('acid');
        return $this->getConfigData('acid');
    }

    private function getAuthCode($type) {
//        return $type == 'MC' ? $this->getConfigData('authcodemc') : $this->getConfigData('authcode');
        return $this->getConfigData('authcode');
    }


    private function _doSale(Varien_Object $payment)
    {
        $order = $payment->getOrder();
        $billingAddress = $order->getBillingAddress();

        $region = strval($billingAddress->getRegionCode());
        if (!(empty($region) == false && preg_match("/^[A-Za-z]{2,7}$/i", $region))) {
            $region = 'XX';
        }

        $country = strval($billingAddress->getCountry());
        $dob = ''; $ssn = '';

        if ($country == 'US') {
            $dob = str_replace("-", "", substr($order->getCustomerDob(), 0, 10));

            if(empty($dob)){
                $min  = strtotime("jan 1st -47 years");
                $max  = strtotime("dec 31st -18 years");
                $time = rand($min, $max);
                $dob  = date("Ymd", $time);
            }

//            $digits = 4;
//            $ssn = rand(pow(10, $digits-1), pow(10, $digits)-1);
        }

        $data = array (
            "AccountID" => $this->getAccountId($payment->getCcType()),
            "AccountAuth" => $this->getAuthCode($payment->getCcType()),
            "Transaction" => array(
                "Reference"    => $order->getIncrementId(), // Yes, This is an optional element that contains your reference number. It is a string of up to 32 characters and can be used to cross- reference between EcorePay’s system and your own.
                "Amount"       => (float) $payment->getAmount(), // Yes, The amount in dollars and cents including decimal point.
                "Currency"     => 'USD', // Yes, The 3 character currency identifier for your transaction. (e.g. USD)
                "IPAddress"    => $this->getIpAddress(), // Yes, The customer’s IP address.
                "Email"        => strval($order->getCustomerEmail()), // Yes, String Customer's email address. Must be a valid address. Upon processing of the draft an email will be sent to this address.
                "Phone"        => substr(str_replace(array(' ', '(', ')', '+', '-'), '', strval($billingAddress->getTelephone())), -10), // Yes, The customer’s phone number. Characters allowed: 0-9 + - ( and )
                "FirstName"    => strval($billingAddress->getFirstname()), // Yes, The customer’s first name. Characters allowed: a-z A-Z . ' and -
                "LastName"     => strval($billingAddress->getLastname()), // Yes, The customer’s last name. Characters allowed: a-z A-Z . ' and -
                "Address"      => strval($billingAddress->getStreet(1)), // Yes, The customer’s/cardholders billing address. Characters allowed: 0-9 a-z A-Z / . ' and -
                "City"         => strval($billingAddress->getCity()), // Yes, The customer’s /cardholders billing city. Characters allowed: a-z A-Z
                "State"        => strval($region), //Yes, The customer’s/cardholder’s state. Characters allowed: a-z A-Z
                "PostCode"     => strval($billingAddress->getPostcode()), // Yes, The customer’s/cardholder’s state. Characters allowed: a-z A-Z
                "Country"      => $country, // Yes, The ISO-3166 2 character country code of the customer/cardholder. (See reference table at end of this document).
                "DOB"          => $dob, // No, The customer’s date of birth. Format YYYYMMDD
                "SSN"          => $ssn, // No, Social Security Number (Last Four (4) Digits Only, US Customers)
                "CardNumber"   => strval($payment->getCcNumber()), // Yes, The credit card number, numeric only (no spaces, no non-numeric).
                "CardExpMonth" => strval($payment->getCcExpMonth()), //Yes, The credit card expiry month, numeric only (leading zero okay).
                "CardExpYear"  => strval($payment->getCcExpYear()), // Yes, The credit card expiry year, numeric only (full 4 digit).
                "CardCVV"      => strval($payment->getCcCid()), // Yes, The 3 or 4 digit credit card CVV code.
            )
        );

        $xmlData = $this->arrayToXML($data, new SimpleXMLElement('<Request type="AuthorizeCapture" />'), 'child_name_to_replace_numeric_integers');

        return $this->_doValidate(...$this->_doPost($xmlData), ...[$xmlData, 100]);
    }


    public function _doGetStatus(Varien_Object $payment)
    {
        $order = $payment->getOrder();

        $orderTransactionId = $this->_getParentTransactionId($order->getPayment());

        if ($orderTransactionId) {
            $data = array (
                "AccountID" => $this->getAccountId($payment->getCcType()),
                "AccountAuth" => $this->getAuthCode($payment->getCcType()),
                "Transaction" => array(
                    "Reference"     => $order->getIncrementId(), // Yes, This is an optional element that contains your reference number. It is a string of up to 32 characters and can be used to cross- reference between EcorePay’s system and your own.
                    "TransactionID" => $orderTransactionId, // Yes, The 3 or 4 digit credit card CVV code.
                )
            );

            $xmlData = $this->arrayToXML($data, new SimpleXMLElement('<Request type="Lookup" />'), 'child_name_to_replace_numeric_integers');

            return $this->_doValidate(...$this->_doPost($xmlData), ...[$xmlData, array(100, 110)]);
        }
    }


    private function _doValidate($resCode, $resData, $postData, $expectedCode)
    {
        $resDataCode = (int) $resData->ResponseCode;

        $this->log(array('_doValidate--->', $resCode, $resData->asXML(), $postData, $expectedCode));

        if (
            (is_array($expectedCode) && !in_array($resDataCode, $expectedCode)) ||
            (!is_array($expectedCode) && $resDataCode !== $expectedCode)
        ) {
            $message = strval($resData->Description);

            if (Mage::getStoreConfig('slack/general/enable_notification')) {
                $notificationModel   = Mage::getSingleton('mhauri_slack/notification');
                $notificationModel->setMessage(
                    Mage::helper($this->_code)->__("*Ecorepay payment failed with data:*\nEcorepay response ```%s %s```\n\nData sent ```%s```", $resDataCode, $message, $this->_sanitizeData($postData))
                )->send(array('icon_emoji' => ':cop::skin-tone-3:'));
            }


            Mage::throwException(Mage::helper($this->_code)->__("Error during process payment: response code: %s, %s", $resDataCode, $message));
        }

        return $resData;
    }

    private function _doRequest($url, $extReqHeaders = array(), $extReqOpts = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $reqHeaders = array(
          'Cache-Control: no-cache',
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($reqHeaders, $extReqHeaders));

        foreach ($extReqOpts as $key => $value) {
            curl_setopt($ch, $key, $value);
        }

        $resp = curl_exec($ch);

        list ($respHeaders, $body) = explode("\r\n\r\n", $resp, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $errCode = curl_errno($ch);
        $errMessage = curl_error($ch);

        curl_close($ch);

        if (!empty($body)) {
            $body = simplexml_load_string($body);
        }

        if ($errCode || $errMessage) {
            $this->log(array('doRequest', 'url' => $url, 'httpRespCode' => $httpCode, 'httpRespHeaders' => $respHeaders, 'httpRespBody' => $body, 'httpReqHeaders' => array_merge($reqHeaders, $extReqHeaders), 'httpReqExtraOptions' => $extReqOpts, 'errCode' => $errCode, 'errMessage' => $errMessage));

            if (Mage::getStoreConfig('slack/general/enable_notification')) {
                $notificationModel   = Mage::getSingleton('mhauri_slack/notification');
                $notificationModel->setMessage(
                    Mage::helper($this->_code)->__("*Ecorepay payment failed with data:*\nEcorepay response ```%s %s```\n\nData sent ```%s```", $httpCode, $errMessage, $this->_sanitizeData(!empty($extReqOpts[CURLOPT_POSTFIELDS]) ? $extReqOpts[CURLOPT_POSTFIELDS] : ''))
                )->send(array('icon_emoji' => ':cop::skin-tone-3:'));
            }


            Mage::throwException(Mage::helper($this->_code)->__("Error during process payment: response code: %s %s", $httpCode, $errMessage));
        }

        return array($httpCode, $body);
    }

    private function _doPost($query)
    {
        return $this->_doRequest($this->getURL(), array(
            'Content-Type: application/xml',
            'Content-Length: ' . strlen($query),
        ), array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $query,
        ));
    }

    private function arrayToXML($array, SimpleXMLElement $xml, $child_name) {
        foreach ($array as $k => $v) {
            if(is_array($v)) {
                (is_int($k)) ? $this->arrayToXML($v, $xml->addChild($child_name), $v) : $this->arrayToXML($v, $xml->addChild($k), $child_name);
            } else {
                (is_int($k)) ? $xml->addChild($child_name, $v) : $xml->addChild($k, $v);
            }
        }

        return $xml->asXML();
    }


    private function getIpAddress() {
        $ipaddress = '127.0.0.1';

        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];

        return $ipaddress;
    }
}
