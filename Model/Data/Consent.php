<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\Data;

use FxCommerce\IysGateway\Api\Data\ConsentInterface;
use Magento\Framework\Api\AbstractSimpleObject;

class Consent extends AbstractSimpleObject implements ConsentInterface
{
    public function getSubscriberId() { return $this->_get(self::SUBSCRIBER_ID); }
    public function setSubscriberId($subscriberId) { return $this->setData(self::SUBSCRIBER_ID, $subscriberId); }
    public function getEmail() { return (string)$this->_get(self::EMAIL); }
    public function setEmail($email) { return $this->setData(self::EMAIL, (string)$email); }
    public function getStoreId() { return (int)$this->_get(self::STORE_ID); }
    public function setStoreId($storeId) { return $this->setData(self::STORE_ID, (int)$storeId); }
    public function getCustomerId() { return $this->_get(self::CUSTOMER_ID); }
    public function setCustomerId($customerId) { return $this->setData(self::CUSTOMER_ID, $customerId); }
    public function getEmailConsent() { return (bool)$this->_get(self::EMAIL_CONSENT); }
    public function setEmailConsent($emailConsent) { return $this->setData(self::EMAIL_CONSENT, (bool)$emailConsent); }
    public function getSmsConsent() { return (bool)$this->_get(self::SMS_CONSENT); }
    public function setSmsConsent($smsConsent) { return $this->setData(self::SMS_CONSENT, (bool)$smsConsent); }
    public function getCallConsent() { return (bool)$this->_get(self::CALL_CONSENT); }
    public function setCallConsent($callConsent) { return $this->setData(self::CALL_CONSENT, (bool)$callConsent); }
    public function getUpdatedAt() { return $this->_get(self::UPDATED_AT); }
    public function setUpdatedAt($updatedAt) { return $this->setData(self::UPDATED_AT, $updatedAt); }
}
