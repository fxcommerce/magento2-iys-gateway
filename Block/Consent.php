<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Block;

use FxCommerce\IysGateway\Model\ConsentStorage;
use FxCommerce\IysGateway\Model\Config;
use FxCommerce\IysGateway\Model\PhoneStorage;
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\StoreManagerInterface;

class Consent extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly Session $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConsentStorage $consentStorage,
        private readonly PhoneStorage $phoneStorage,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isSmsConsent(): bool
    {
        $subscriber = $this->getSubscriber();
        return $subscriber !== null
            && $this->phoneStorage->isCurrentRecipient($subscriber)
            && (bool)$subscriber->getData('sms_consent');
    }

    public function isCallConsent(): bool
    {
        $subscriber = $this->getSubscriber();
        return $subscriber !== null
            && $this->phoneStorage->isCurrentRecipient($subscriber)
            && (bool)$subscriber->getData('call_consent');
    }

    public function getPhoneNumber(): string
    {
        $subscriber = $this->getSubscriber();
        return $subscriber ? $this->phoneStorage->read($subscriber) : '';
    }

    public function isSmsEnabled(): bool
    {
        return $this->config->isSmsEnabled($this->getStoreId());
    }

    public function isCallEnabled(): bool
    {
        return $this->config->isCallEnabled($this->getStoreId());
    }

    public function shouldShowPhoneInput(): bool
    {
        return $this->config->getPhoneSource($this->getStoreId()) !== 'customer';
    }

    public function getPhoneLabel(): string
    {
        return $this->config->getPhoneLabel($this->getStoreId());
    }

    public function getPhoneNote(): string
    {
        return $this->config->getPhoneNote($this->getStoreId());
    }

    public function getSmsLabel(): string
    {
        return $this->config->getSmsLabel($this->getStoreId());
    }

    public function getCallLabel(): string
    {
        return $this->config->getCallLabel($this->getStoreId());
    }

    private function getStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }

    private function getSubscriber(): ?\Magento\Newsletter\Model\Subscriber
    {
        $customerId = (int)$this->customerSession->getCustomerId();
        if (!$customerId) {
            return null;
        }
        return $this->consentStorage->getByCustomerId(
            $customerId,
            (int)$this->storeManager->getStore()->getId()
        );
    }
}
