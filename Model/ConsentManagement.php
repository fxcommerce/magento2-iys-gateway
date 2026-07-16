<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use FxCommerce\IysGateway\Api\ConsentManagementInterface;
use FxCommerce\IysGateway\Api\Data\ConsentInterface;
use FxCommerce\IysGateway\Api\Data\ConsentInterfaceFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;

class ConsentManagement implements ConsentManagementInterface
{
    public function __construct(
        private readonly SubscriberFactory $subscriberFactory,
        private readonly SubscriberResource $subscriberResource,
        private readonly ConsentInterfaceFactory $consentFactory,
        private readonly SynchronizationContext $context
    ) {
    }

    public function get($subscriberId)
    {
        $subscriber = $this->subscriberFactory->create();
        $this->subscriberResource->load($subscriber, (int)$subscriberId);
        if (!$subscriber->getId()) {
            throw new NoSuchEntityException(__('Subscriber with ID %1 does not exist.', $subscriberId));
        }
        return $this->map($subscriber);
    }

    public function getList($fromId = 0, $limit = 100, $storeId = null)
    {
        $limit = max(1, min(1000, (int)$limit));
        $collection = $this->subscriberFactory->create()->getCollection();
        $collection->addFieldToFilter('subscriber_id', ['gt' => (int)$fromId]);
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', (int)$storeId);
        }
        $collection->setOrder('subscriber_id', 'ASC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $result = [];
        foreach ($collection as $subscriber) {
            $result[] = $this->map($subscriber);
        }
        return $result;
    }

    public function save(ConsentInterface $consent)
    {
        return $this->context->runInbound(function () use ($consent): ConsentInterface {
            $subscriber = $this->resolveSubscriber($consent);
            $subscriber->setData('subscriber_email', strtolower(trim($consent->getEmail())));
            $subscriber->setData('store_id', $consent->getStoreId());
            if ($consent->getCustomerId()) {
                $subscriber->setData('customer_id', (int)$consent->getCustomerId());
            }
            $subscriber->setData(
                'subscriber_status',
                $consent->getEmailConsent() ? Subscriber::STATUS_SUBSCRIBED : Subscriber::STATUS_UNSUBSCRIBED
            );
            $subscriber->setData('sms_consent', $consent->getSmsConsent() ? 1 : 0);
            $subscriber->setData('call_consent', $consent->getCallConsent() ? 1 : 0);
            $subscriber->setData('change_status_at', gmdate('Y-m-d H:i:s'));
            $this->subscriberResource->save($subscriber);
            return $this->map($subscriber);
        });
    }

    public function saveBatch(array $consents)
    {
        $result = [];
        foreach ($consents as $consent) {
            if (!$consent instanceof ConsentInterface) {
                throw new InputException(__('Every batch item must be a consent object.'));
            }
            $result[] = $this->save($consent);
        }
        return $result;
    }

    private function resolveSubscriber(ConsentInterface $consent): Subscriber
    {
        $subscriber = $this->subscriberFactory->create();
        if ($consent->getSubscriberId()) {
            $this->subscriberResource->load($subscriber, (int)$consent->getSubscriberId());
            if ($subscriber->getId()) {
                return $subscriber;
            }
        }

        $email = strtolower(trim($consent->getEmail()));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InputException(__('A valid subscriber email address is required.'));
        }

        $collection = $subscriber->getCollection();
        $collection->addFieldToFilter('subscriber_email', $email);
        $collection->addFieldToFilter('store_id', $consent->getStoreId());
        $collection->setPageSize(1);
        $existing = $collection->getFirstItem();
        return $existing->getId() ? $existing : $subscriber;
    }

    private function map(Subscriber $subscriber): ConsentInterface
    {
        $consent = $this->consentFactory->create();
        $consent->setSubscriberId((int)$subscriber->getId());
        $consent->setEmail((string)$subscriber->getSubscriberEmail());
        $consent->setStoreId((int)$subscriber->getStoreId());
        $consent->setCustomerId($subscriber->getCustomerId() ? (int)$subscriber->getCustomerId() : null);
        $consent->setEmailConsent((int)$subscriber->getStatus() === Subscriber::STATUS_SUBSCRIBED);
        $consent->setSmsConsent((bool)$subscriber->getData('sms_consent'));
        $consent->setCallConsent((bool)$subscriber->getData('call_consent'));
        $consent->setUpdatedAt((string)($subscriber->getChangeStatusAt() ?: ''));
        return $consent;
    }
}
