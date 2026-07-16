<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Plugin\Newsletter;

use FxCommerce\IysGateway\Model\ConsentStorage;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Newsletter\Controller\Manage\Save;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ManageSavePlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Session $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConsentStorage $consentStorage,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterExecute(Save $subject, mixed $result): mixed
    {
        $customerId = (int)$this->customerSession->getCustomerId();
        if (!$customerId) {
            return $result;
        }

        try {
            $this->consentStorage->saveCustomerConsents(
                $customerId,
                (int)$this->storeManager->getStore()->getId(),
                (bool)$this->request->getParam('sms_consent', false),
                (bool)$this->request->getParam('call_consent', false),
                (string)$this->request->getParam('phone_number', '')
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to save customer SMS/call consents.', [
                'customer_id' => $customerId,
                'exception' => $exception,
            ]);
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $result;
    }
}
