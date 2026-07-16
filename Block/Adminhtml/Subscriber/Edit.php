<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Block\Adminhtml\Subscriber;

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
        array $data = []
    ) {
        parent::__construct($context, $data);
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
