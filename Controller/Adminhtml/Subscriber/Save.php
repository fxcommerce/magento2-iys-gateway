<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Controller\Adminhtml\Subscriber;

use FxCommerce\IysGateway\Model\ConsentStorage;
use Magento\Backend\App\Action;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'FxCommerce_IysGateway::subscriber_manage';

    public function __construct(
        Action\Context $context,
        private readonly ConsentStorage $consentStorage
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $subscriberId = (int)$this->getRequest()->getParam('subscriber_id');
        try {
            $this->consentStorage->saveBySubscriberId(
                $subscriberId,
                (bool)$this->getRequest()->getParam('email_consent', false),
                (bool)$this->getRequest()->getParam('sms_consent', false),
                (bool)$this->getRequest()->getParam('call_consent', false),
                false,
                (string)$this->getRequest()->getParam('phone_number', '')
            );
            $this->messageManager->addSuccessMessage(__('Communication consents were saved.'));
        } catch (\Throwable $exception) {
            $this->messageManager->addExceptionMessage($exception, __('Unable to save communication consents.'));
            return $this->_redirect('iysgateway/subscriber/edit', ['subscriber_id' => $subscriberId]);
        }

        return $this->_redirect('newsletter/subscriber/index');
    }
}
