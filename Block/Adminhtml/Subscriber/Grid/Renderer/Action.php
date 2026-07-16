<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Block\Adminhtml\Subscriber\Grid\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;

class Action extends AbstractRenderer
{
    public function render(DataObject $row): string
    {
        $url = $this->getUrl('iysgateway/subscriber/edit', [
            'subscriber_id' => (int)$row->getData('subscriber_id'),
        ]);
        return sprintf(
            '<a href="%s">%s</a>',
            $this->escapeHtmlAttr($url),
            $this->escapeHtml(__('Manage Consents'))
        );
    }
}
