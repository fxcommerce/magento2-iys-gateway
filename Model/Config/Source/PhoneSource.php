<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PhoneSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'subscriber', 'label' => __('Newsletter subscriber table field')],
            ['value' => 'customer', 'label' => __('Customer attribute')],
        ];
    }
}
