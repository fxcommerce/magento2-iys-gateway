<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Block;

use FxCommerce\IysGateway\Model\ConsentStorage;
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
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isSmsConsent(): bool
    {
        return (bool)$this->getSubscriber()?->getData('sms_consent');
    }

    public function isCallConsent(): bool
    {
        return (bool)$this->getSubscriber()?->getData('call_consent');
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
