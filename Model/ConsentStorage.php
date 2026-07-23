<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;

class ConsentStorage
{
    public function __construct(
        private readonly SubscriberFactory $subscriberFactory,
        private readonly SubscriberResource $subscriberResource,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SynchronizationContext $context,
        private readonly QueuePublisher $queuePublisher,
        private readonly PhoneStorage $phoneStorage
    ) {
    }

    public function getByCustomerId(int $customerId, int $storeId): ?Subscriber
    {
        $collection = $this->subscriberFactory->create()->getCollection();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->setPageSize(1);
        $subscriber = $collection->getFirstItem();
        return $subscriber->getId() ? $subscriber : null;
    }

    public function getByEmail(string $email, int $storeId): ?Subscriber
    {
        $collection = $this->subscriberFactory->create()->getCollection();
        $collection->addFieldToFilter('subscriber_email', strtolower(trim($email)));
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->setPageSize(1);
        $subscriber = $collection->getFirstItem();
        return $subscriber->getId() ? $subscriber : null;
    }

    public function getByPhone(string $phone, int $storeId): ?Subscriber
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return null;
        }
        $collection = $this->subscriberFactory->create()->getCollection();
        $collection->addFieldToFilter('phone_number', $normalized);
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->setPageSize(1);
        $subscriber = $collection->getFirstItem();
        return $subscriber->getId() ? $subscriber : null;
    }

    public function saveCustomerConsents(
        int $customerId,
        int $storeId,
        ?bool $smsConsent,
        ?bool $callConsent,
        ?string $phoneNumber = null
    ): Subscriber {
        $subscriber = $this->getByCustomerId($customerId, $storeId);
        $now = gmdate('Y-m-d H:i:s');

        if (!$subscriber) {
            $customer = $this->customerRepository->getById($customerId);
            $subscriber = $this->subscriberFactory->create();
            $subscriber->setData('customer_id', $customerId);
            $subscriber->setData('subscriber_email', strtolower((string)$customer->getEmail()));
            $subscriber->setData('store_id', $storeId);
            $subscriber->setData('subscriber_status', Subscriber::STATUS_UNSUBSCRIBED);
            $subscriber->setData('email_consent_at', $now);
            if ($smsConsent !== null) {
                $subscriber->setData('sms_consent_at', $now);
            }
            if ($callConsent !== null) {
                $subscriber->setData('call_consent_at', $now);
            }
        } else {
            $this->backfillTimestamps($subscriber, $now);
        }

        $phone = $phoneNumber === null
            ? $this->phoneStorage->read($subscriber)
            : $this->normalizePhone($phoneNumber);
        if (($smsConsent === true || $callConsent === true) && $phone === '') {
            throw new LocalizedException(__('A valid phone number is required for SMS or call consent.'));
        }
        $phoneChanged = $this->normalizePhone((string)$subscriber->getData('phone_number')) !== $phone;

        if ($subscriber->getId()) {
            if ($smsConsent !== null
                && ((bool)$subscriber->getData('sms_consent') !== $smsConsent || $phoneChanged)
            ) {
                $subscriber->setData('sms_consent_at', $now);
            }
            if ($callConsent !== null
                && ((bool)$subscriber->getData('call_consent') !== $callConsent || $phoneChanged)
            ) {
                $subscriber->setData('call_consent_at', $now);
            }
        }

        if ($phoneNumber !== null) {
            $this->phoneStorage->write($subscriber, $phone);
        }
        $subscriber->setData('phone_number', $phone !== '' ? $phone : null);
        if ($smsConsent !== null) {
            $subscriber->setData('sms_consent', $smsConsent ? 1 : 0);
        }
        if ($callConsent !== null) {
            $subscriber->setData('call_consent', $callConsent ? 1 : 0);
        }
        $this->subscriberResource->save($subscriber);
        $this->queuePublisher->publish($subscriber);
        return $subscriber;
    }

    public function saveBySubscriberId(
        int $subscriberId,
        bool $emailConsent,
        bool $smsConsent,
        bool $callConsent,
        bool $inbound = false,
        ?string $phoneNumber = null
    ): Subscriber {
        $callback = function () use (
            $subscriberId,
            $emailConsent,
            $smsConsent,
            $callConsent,
            $inbound,
            $phoneNumber
        ): Subscriber {
            $subscriber = $this->subscriberFactory->create();
            $this->subscriberResource->load($subscriber, $subscriberId);
            if (!$subscriber->getId()) {
                throw new LocalizedException(__('Subscriber does not exist.'));
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->backfillTimestamps($subscriber, $now);
            $nextEmailStatus = $emailConsent
                ? Subscriber::STATUS_SUBSCRIBED
                : Subscriber::STATUS_UNSUBSCRIBED;
            $phone = $phoneNumber === null
                ? $this->phoneStorage->read($subscriber)
                : $this->normalizePhone($phoneNumber);
            if (($smsConsent || $callConsent) && $phone === '') {
                throw new LocalizedException(__('A valid phone number is required for SMS or call consent.'));
            }
            $phoneChanged = $this->normalizePhone((string)$subscriber->getData('phone_number')) !== $phone;

            if ((int)$subscriber->getStatus() !== $nextEmailStatus) {
                $subscriber->setData('email_consent_at', $now);
                $subscriber->setData('change_status_at', $now);
            }
            if ((bool)$subscriber->getData('sms_consent') !== $smsConsent || $phoneChanged) {
                $subscriber->setData('sms_consent_at', $now);
            }
            if ((bool)$subscriber->getData('call_consent') !== $callConsent || $phoneChanged) {
                $subscriber->setData('call_consent_at', $now);
            }

            if ($phoneNumber !== null) {
                $this->phoneStorage->write($subscriber, $phone);
            }
            $subscriber->setData('subscriber_status', $nextEmailStatus);
            $subscriber->setData('phone_number', $phone !== '' ? $phone : null);
            $subscriber->setData('sms_consent', $smsConsent ? 1 : 0);
            $subscriber->setData('call_consent', $callConsent ? 1 : 0);
            $this->subscriberResource->save($subscriber);
            if (!$inbound) {
                $this->queuePublisher->publish($subscriber);
            }
            return $subscriber;
        };

        return $inbound ? $this->context->runInbound($callback) : $callback();
    }

    public function applyInboundAction(
        string $email,
        string $phone,
        int $storeId,
        string $channel,
        bool $approved,
        string $consentAt
    ): Subscriber {
        return $this->context->runInbound(function () use (
            $email,
            $phone,
            $storeId,
            $channel,
            $approved,
            $consentAt
        ): Subscriber {
            $subscriber = $email !== '' ? $this->getByEmail($email, $storeId) : null;
            if (!$subscriber && $phone !== '') {
                $subscriber = $this->getByPhone($phone, $storeId);
            }
            $dbTimestamp = $this->databaseTimestamp($consentAt);
            if (!$subscriber) {
                if ($email === '') {
                    throw new LocalizedException(__(
                        'No Magento subscriber matches this phone number; the consent remains in IYS Gateway.'
                    ));
                }
                $subscriber = $this->subscriberFactory->create();
                $subscriber->setData('subscriber_email', strtolower(trim($email)));
                $subscriber->setData('store_id', $storeId);
                $subscriber->setData('subscriber_status', Subscriber::STATUS_UNSUBSCRIBED);
                $subscriber->setData('email_consent_at', $dbTimestamp);
                $subscriber->setData('sms_consent_at', $dbTimestamp);
                $subscriber->setData('call_consent_at', $dbTimestamp);
            } else {
                $this->backfillTimestamps($subscriber, $dbTimestamp);
            }

            switch (strtoupper($channel)) {
                case 'EMAIL':
                    $subscriber->setData(
                        'subscriber_status',
                        $approved ? Subscriber::STATUS_SUBSCRIBED : Subscriber::STATUS_UNSUBSCRIBED
                    );
                    $subscriber->setData('email_consent_at', $dbTimestamp);
                    $subscriber->setData('change_status_at', $dbTimestamp);
                    break;
                case 'SMS':
                    if ($phone !== '') {
                        $subscriber->setData('phone_number', $this->normalizePhone($phone));
                    }
                    if ($approved && !$subscriber->getData('phone_number')) {
                        throw new LocalizedException(__('Subscriber has no phone number for approved SMS consent.'));
                    }
                    $subscriber->setData('sms_consent', $approved ? 1 : 0);
                    $subscriber->setData('sms_consent_at', $dbTimestamp);
                    break;
                case 'CALL':
                    if ($phone !== '') {
                        $subscriber->setData('phone_number', $this->normalizePhone($phone));
                    }
                    if ($approved && !$subscriber->getData('phone_number')) {
                        throw new LocalizedException(__('Subscriber has no phone number for approved call consent.'));
                    }
                    $subscriber->setData('call_consent', $approved ? 1 : 0);
                    $subscriber->setData('call_consent_at', $dbTimestamp);
                    break;
                default:
                    throw new LocalizedException(__('Unsupported IYS consent channel.'));
            }

            $this->subscriberResource->save($subscriber);
            return $subscriber;
        });
    }

    private function backfillTimestamps(Subscriber $subscriber, string $fallback): void
    {
        $base = (string)($subscriber->getChangeStatusAt() ?: $fallback);
        foreach (['email_consent_at', 'sms_consent_at', 'call_consent_at'] as $field) {
            if (!$subscriber->getData($field)) {
                $subscriber->setData($field, $base);
            }
        }
    }

    private function databaseTimestamp(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new LocalizedException(__('IYS consent timestamp is invalid.'));
        }
    }

    private function normalizePhone(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $trimmed) ?: '';
        if (str_starts_with($trimmed, '00')) {
            $normalized = '+' . substr($digits, 2);
        } elseif (str_starts_with($trimmed, '+')) {
            $normalized = '+' . $digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $normalized = '+90' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $normalized = '+90' . $digits;
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '90')) {
            $normalized = '+' . $digits;
        } else {
            $normalized = $digits;
        }
        $normalizedDigits = preg_replace('/\D+/', '', $normalized) ?: '';
        return strlen($normalizedDigits) >= 10 && strlen($normalizedDigits) <= 15
            ? $normalized
            : '';
    }
}
