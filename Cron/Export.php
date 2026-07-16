<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Cron;

use FxCommerce\IysGateway\Model\Config;
use FxCommerce\IysGateway\Model\Gateway\Exporter;
use Magento\Store\Model\StoreManagerInterface;

class Export
{
    public function __construct(
        private readonly Config $config,
        private readonly Exporter $exporter,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if ($this->config->isCronEnabled($storeId)) {
                $this->exporter->execute($storeId);
            }
        }
    }
}
