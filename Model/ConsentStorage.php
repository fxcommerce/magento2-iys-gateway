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
        private readonly QueuePublisher $queuePublisher
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

    public function saveCustomerConsents(
        int $customerId,
        int $storeId,
        bool $smsConsent,
        bool $callConsent
    ): Subscriber {
        $subscriber = $this->getByCustomerId($customerId, $storeId);
        if (!$subscriber) {
            $customer = $this->customerRepository->getById($customerId);
            $subscriber = $this->subscriberFactory->create();
            $subscriber->setData('customer_id', $customerId);
            $subscriber->setData('subscriber_email', $customer->getEmail());
            $subscriber->setData('store_id', $storeId);
            $subscriber->setData('subscriber_status', Subscriber::STATUS_UNSUBSCRIBED);
        }

        $subscriber->setData('sms_consent', $smsConsent ? 1 : 0);
        $subscriber->setData('call_consent', $callConsent ? 1 : 0);
        $subscriber->setData('change_status_at', gmdate('Y-m-d H:i:s'));
        $this->subscriberResource->save($subscriber);
        $this->queuePublisher->publish($subscriber);
        return $subscriber;
    }

    public function saveBySubscriberId(
        int $subscriberId,
        bool $emailConsent,
        bool $smsConsent,
        bool $callConsent,
        bool $inbound = false
    ): Subscriber {
        $callback = function () use ($subscriberId, $emailConsent, $smsConsent, $callConsent): Subscriber {
            $subscriber = $this->subscriberFactory->create();
            $this->subscriberResource->load($subscriber, $subscriberId);
            if (!$subscriber->getId()) {
                throw new LocalizedException(__('Subscriber does not exist.'));
            }
            $subscriber->setData(
                'subscriber_status',
                $emailConsent ? Subscriber::STATUS_SUBSCRIBED : Subscriber::STATUS_UNSUBSCRIBED
            );
            $subscriber->setData('sms_consent', $smsConsent ? 1 : 0);
            $subscriber->setData('call_consent', $callConsent ? 1 : 0);
            $subscriber->setData('change_status_at', gmdate('Y-m-d H:i:s'));
            $this->subscriberResource->save($subscriber);
            $this->queuePublisher->publish($subscriber);
            return $subscriber;
        };

        return $inbound ? $this->context->runInbound($callback) : $callback();
    }
}
