<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\Config\Source;

use Magento\Customer\Model\ResourceModel\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class CustomerAttribute implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Select a customer attribute --')],
        ];

        $attributes = $this->attributeCollectionFactory->create();

        foreach ($attributes as $attribute) {
            $code = trim((string)$attribute->getAttributeCode());
            if ($code === '' || !$this->isUsable($attribute)) {
                continue;
            }

            $label = trim((string)$attribute->getStoreLabel());
            if ($label === '') {
                $label = trim((string)$attribute->getFrontendLabel());
            }
            if ($label === '') {
                $label = $code;
            }

            $options[] = [
                'value' => $code,
                'label' => sprintf('%s (%s)', $label, $code),
            ];
        }

        usort(
            $options,
            static fn (array $left, array $right): int =>
                $left['value'] === '' ? -1 : ($right['value'] === '' ? 1 : strcasecmp($left['label'], $right['label']))
        );

        return $options;
    }

    private function isUsable(\Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute): bool
    {
        return in_array(
            (string)$attribute->getFrontendInput(),
            ['text', 'textarea', 'tel'],
            true
        );
    }
}
