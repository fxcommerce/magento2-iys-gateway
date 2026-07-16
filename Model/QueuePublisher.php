<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
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
        private readonly LoggerInterface $logger
    ) {
    }

    public function publish(Subscriber $subscriber): bool
    {
        if ($this->context->isInbound() || !$subscriber->getId()) {
            return false;
        }

        $payload = $this->buildPayload($subscriber);
        $stateHash = sha1($this->json->serialize([
            $payload['storeCode'],
            $payload['emailConsent'],
            $payload['smsConsent'],
            $payload['callConsent'],
        ]));
        $eventId = sprintf(
            'magento:%d:%d:%s',
            (int)$subscriber->getStoreId(),
            (int)$subscriber->getId(),
            $stateHash
        );
        $payload['eventId'] = $eventId;

        try {
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
            // The same store-specific consent state has already been queued.
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
        $emailConsent = (int)$subscriber->getStatus() === Subscriber::STATUS_SUBSCRIBED;
        $changedAt = (string)($subscriber->getChangeStatusAt() ?: gmdate('c'));

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
            'email' => strtolower((string)$subscriber->getSubscriberEmail()),
            'emailConsent' => $emailConsent,
            'smsConsent' => (bool)$subscriber->getData('sms_consent'),
            'callConsent' => (bool)$subscriber->getData('call_consent'),
            'consentAt' => $changedAt,
        ];
    }
}
