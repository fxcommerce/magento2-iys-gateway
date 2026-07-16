<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Queue extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('fxcommerce_iys_sync_queue', 'queue_id');
    }
}
