<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Plugin\Newsletter;

use FxCommerce\IysGateway\Model\Config;
use FxCommerce\IysGateway\Model\QueuePublisher;
use Magento\Newsletter\Model\Subscriber;

class SubscriberSavePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly QueuePublisher $publisher
    ) {
    }

    public function afterSave(Subscriber $subject, Subscriber $result): Subscriber
    {
        if ($this->config->isEnabled((int)$subject->getStoreId())) {
            $this->publisher->publish($subject);
        }
        return $result;
    }
}
