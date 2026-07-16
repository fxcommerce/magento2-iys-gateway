<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use Magento\Framework\Model\AbstractModel;

class Queue extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected function _construct(): void
    {
        $this->_init(\FxCommerce\IysGateway\Model\ResourceModel\Queue::class);
    }
}
