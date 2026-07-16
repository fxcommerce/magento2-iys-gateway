<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Api\Data;

interface ConsentInterface
{
    public const SUBSCRIBER_ID = 'subscriber_id';
    public const EMAIL = 'email';
    public const STORE_ID = 'store_id';
    public const CUSTOMER_ID = 'customer_id';
    public const EMAIL_CONSENT = 'email_consent';
    public const SMS_CONSENT = 'sms_consent';
    public const CALL_CONSENT = 'call_consent';
    public const UPDATED_AT = 'updated_at';

    /** @return int|null */
    public function getSubscriberId();

    /** @param int|null $subscriberId @return $this */
    public function setSubscriberId($subscriberId);

    /** @return string */
    public function getEmail();

    /** @param string $email @return $this */
    public function setEmail($email);

    /** @return int */
    public function getStoreId();

    /** @param int $storeId @return $this */
    public function setStoreId($storeId);

    /** @return int|null */
    public function getCustomerId();

    /** @param int|null $customerId @return $this */
    public function setCustomerId($customerId);

    /** @return bool */
    public function getEmailConsent();

    /** @param bool $emailConsent @return $this */
    public function setEmailConsent($emailConsent);

    /** @return bool */
    public function getSmsConsent();

    /** @param bool $smsConsent @return $this */
    public function setSmsConsent($smsConsent);

    /** @return bool */
    public function getCallConsent();

    /** @param bool $callConsent @return $this */
    public function setCallConsent($callConsent);

    /** @return string|null */
    public function getUpdatedAt();

    /** @param string|null $updatedAt @return $this */
    public function setUpdatedAt($updatedAt);
}
