<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Block\Adminhtml\Subscriber;

use FxCommerce\IysGateway\Model\PhoneStorage;
use Magento\Backend\Block\Template;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;

class Edit extends Template
{
    private ?Subscriber $subscriber = null;

    public function __construct(
        Template\Context $context,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly SubscriberResource $subscriberResource,
        private readonly PhoneStorage $phoneStorage,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getPhoneNumber(): string
    {
        return $this->phoneStorage->read($this->getSubscriber());
    }

    public function isSmsConsent(): bool
    {
        return $this->phoneStorage->isCurrentRecipient($this->getSubscriber())
            && (bool)$this->getSubscriber()->getData('sms_consent');
    }

    public function isCallConsent(): bool
    {
        return $this->phoneStorage->isCurrentRecipient($this->getSubscriber())
            && (bool)$this->getSubscriber()->getData('call_consent');
    }

    public function getSubscriber(): Subscriber
    {
        if ($this->subscriber === null) {
            $this->subscriber = $this->subscriberFactory->create();
            $this->subscriberResource->load(
                $this->subscriber,
                (int)$this->getRequest()->getParam('subscriber_id')
            );
        }
        return $this->subscriber;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('iysgateway/subscriber/save');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('newsletter/subscriber/index');
    }
}
