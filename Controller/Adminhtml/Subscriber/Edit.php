<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Controller\Adminhtml\Subscriber;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\SubscriberFactory;

class Edit extends Action
{
    public const ADMIN_RESOURCE = 'FxCommerce_IysGateway::subscriber_manage';

    public function __construct(
        Action\Context $context,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly SubscriberResource $subscriberResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $subscriberId = (int)$this->getRequest()->getParam('subscriber_id');
        $subscriber = $this->subscriberFactory->create();
        $this->subscriberResource->load($subscriber, $subscriberId);
        if (!$subscriber->getId()) {
            $this->messageManager->addErrorMessage(__('Subscriber does not exist.'));
            return $this->_redirect('newsletter/subscriber/index');
        }

        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Magento_Newsletter::newsletter_subscriber');
        $resultPage->getConfig()->getTitle()->prepend(__('Manage Communication Consents'));
        $resultPage->getConfig()->getTitle()->prepend((string)$subscriber->getSubscriberEmail());
        return $resultPage;
    }
}
