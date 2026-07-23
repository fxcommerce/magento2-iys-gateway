<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class QueuePublisher
{
    public function __construct(
        private readonly QueueFactory $queueFactory,
        private readonly Json $json,
        private readonly SynchronizationContext $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly PhoneResolver $phoneResolver,
        private readonly SubscriberResource $subscriberResource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function publish(Subscriber $subscriber): bool
    {
        if ($this->context->isInbound() || !$subscriber->getId()) {
            return false;
        }

        try {
            $this->ensureStableTimestamps($subscriber);
            $payload = $this->buildPayload($subscriber);
            $eventHash = hash('sha256', $this->json->serialize([
                $payload['storeId'],
                $payload['sourceRecordId'],
                $payload['email'],
                $payload['phone'],
                $payload['emailConsent'],
                $payload['emailConsentAt'],
                $payload['smsConsent'],
                $payload['smsConsentAt'],
                $payload['callConsent'],
                $payload['callConsentAt'],
            ]));
            $eventId = sprintf(
                'magento-v2:%d:%d:%s',
                (int)$subscriber->getStoreId(),
                (int)$subscriber->getId(),
                $eventHash
            );
            $payload['eventId'] = $eventId;

            $queue = $this->queueFactory->create();
            $queue->setData([
                'subscriber_id' => (int)$subscriber->getId(),
                'store_id' => (int)$subscriber->getStoreId(),
                'event_id' => $eventId,
                'payload' => $this->json->serialize($payload),
                'status' => Queue::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $queue->save();
            return true;
        } catch (AlreadyExistsException) {
            return false;
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to queue IYS consent event.', [
                'subscriber_id' => $subscriber->getId(),
                'store_id' => $subscriber->getStoreId(),
                'exception' => $exception,
            ]);
            return false;
        }
    }

    private function buildPayload(Subscriber $subscriber): array
    {
        $store = $this->storeManager->getStore((int)$subscriber->getStoreId());
        $website = $store->getWebsite();
        $email = strtolower(trim((string)$subscriber->getSubscriberEmail()));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(sprintf(
                'Subscriber %d has an invalid email address and was not queued.',
                (int)$subscriber->getId()
            ));
        }
        $emailConsent = (int)$subscriber->getStatus() === Subscriber::STATUS_SUBSCRIBED;
        $storeId = (int)$store->getId();
        $smsValue = $this->config->isSmsEnabled($storeId)
            ? $subscriber->getData('sms_consent')
            : null;
        $callValue = $this->config->isCallEnabled($storeId)
            ? $subscriber->getData('call_consent')
            : null;
        $smsConsent = $smsValue === null ? null : (bool)$smsValue;
        $callConsent = $callValue === null ? null : (bool)$callValue;
        $phone = ($smsConsent !== null || $callConsent !== null)
            ? $this->phoneResolver->resolve($subscriber)
            : '';
        if (($smsConsent || $callConsent) && $phone === '') {
            throw new \RuntimeException('A phone number is required for approved SMS or call consent.');
        }

        $fallback = (string)($subscriber->getChangeStatusAt() ?: gmdate('Y-m-d H:i:s'));
        $emailConsentAt = $this->timestamp($subscriber->getData('email_consent_at'), $fallback);
        $smsConsentAt = $smsConsent === null
            ? null
            : $this->timestamp($subscriber->getData('sms_consent_at'), $fallback);
        $callConsentAt = $callConsent === null
            ? null
            : $this->timestamp($subscriber->getData('call_consent_at'), $fallback);
        $knownDates = array_filter([$emailConsentAt, $smsConsentAt, $callConsentAt]);
        $consentAt = max($knownDates);

        return [
            'source' => 'magento',
            'sourceRecordId' => (string)$subscriber->getId(),
            'storeId' => (int)$store->getId(),
            'storeCode' => (string)$store->getCode(),
            'storeName' => (string)$store->getName(),
            'websiteId' => (int)$website->getId(),
            'websiteCode' => (string)$website->getCode(),
            'websiteName' => (string)$website->getName(),
            'customerId' => $subscriber->getCustomerId() ? (int)$subscriber->getCustomerId() : null,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'emailConsent' => $emailConsent,
            'emailConsentAt' => $emailConsentAt,
            'smsConsent' => $smsConsent,
            'smsConsentAt' => $smsConsentAt,
            'callConsent' => $callConsent,
            'callConsentAt' => $callConsentAt,
            'consentAt' => $consentAt,
        ];
    }

    private function ensureStableTimestamps(Subscriber $subscriber): void
    {
        $fallback = trim((string)$subscriber->getChangeStatusAt());
        if ($fallback === '') {
            $fallback = gmdate('Y-m-d H:i:s');
        }

        $updates = [];
        if (!$subscriber->getData('email_consent_at')) {
            $updates['email_consent_at'] = $fallback;
        }
        if ($subscriber->getData('sms_consent') !== null && !$subscriber->getData('sms_consent_at')) {
            $updates['sms_consent_at'] = $fallback;
        }
        if ($subscriber->getData('call_consent') !== null && !$subscriber->getData('call_consent_at')) {
            $updates['call_consent_at'] = $fallback;
        }
        if (!$updates) {
            return;
        }

        $connection = $this->subscriberResource->getConnection();
        $connection->update(
            $this->subscriberResource->getMainTable(),
            $updates,
            ['subscriber_id = ?' => (int)$subscriber->getId()]
        );
        foreach ($updates as $field => $value) {
            $subscriber->setData($field, $value);
            $subscriber->setOrigData($field, $value);
        }
    }

    private function timestamp(mixed $value, string $fallback): string
    {
        $candidate = trim((string)($value ?: $fallback));
        try {
            return (new \DateTimeImmutable($candidate, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
    }

}
