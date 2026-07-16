<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\ResourceModel\Queue;

use FxCommerce\IysGateway\Model\Queue as QueueModel;
use FxCommerce\IysGateway\Model\ResourceModel\Queue as QueueResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(QueueModel::class, QueueResource::class);
    }
}
