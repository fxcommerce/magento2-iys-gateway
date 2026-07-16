<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Plugin\Newsletter;

use FxCommerce\IysGateway\Model\Config;
use FxCommerce\IysGateway\Model\QueuePublisher;
use FxCommerce\IysGateway\Model\SynchronizationContext;
use Magento\Newsletter\Model\Subscriber;

class SubscriberSavePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly QueuePublisher $publisher,
        private readonly SynchronizationContext $context
    ) {
    }

    public function beforeSave(Subscriber $subject): ?array
    {
        if ($this->context->isInbound()) {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');
        $fallback = (string)($subject->getChangeStatusAt() ?: $now);
        $isNew = !$subject->getId();

        if ($isNew || $this->changed($subject, 'subscriber_status')) {
            $subject->setData('email_consent_at', $now);
        } elseif (!$subject->getData('email_consent_at')) {
            $subject->setData('email_consent_at', $fallback);
        }

        if ($isNew || $this->changed($subject, 'sms_consent')) {
            $subject->setData('sms_consent_at', $now);
        } elseif (!$subject->getData('sms_consent_at')) {
            $subject->setData('sms_consent_at', $fallback);
        }

        if ($isNew || $this->changed($subject, 'call_consent')) {
            $subject->setData('call_consent_at', $now);
        } elseif (!$subject->getData('call_consent_at')) {
            $subject->setData('call_consent_at', $fallback);
        }

        return null;
    }

    public function afterSave(Subscriber $subject, Subscriber $result): Subscriber
    {
        if ($this->config->isEnabled((int)$subject->getStoreId())) {
            $this->publisher->publish($subject);
        }
        return $result;
    }

    private function changed(Subscriber $subscriber, string $field): bool
    {
        return (string)$subscriber->getOrigData($field) !== (string)$subscriber->getData($field);
    }
}
