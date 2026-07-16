<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Block\Adminhtml\Subscriber\Grid\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;

class Boolean extends AbstractRenderer
{
    public function render(DataObject $row): string
    {
        return (bool)$row->getData($this->getColumn()->getIndex())
            ? (string)__('Enabled')
            : (string)__('Disabled');
    }
}
