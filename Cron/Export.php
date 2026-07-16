<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Cron;

use FxCommerce\IysGateway\Model\Config;
use FxCommerce\IysGateway\Model\Gateway\Exporter;
use FxCommerce\IysGateway\Model\Gateway\InboundSynchronizer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Export
{
    public function __construct(
        private readonly Config $config,
        private readonly Exporter $exporter,
        private readonly InboundSynchronizer $inboundSynchronizer,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if (!$this->config->isCronEnabled($storeId) || !$this->config->isEnabled($storeId)) {
                continue;
            }

            try {
                $this->inboundSynchronizer->execute($storeId, 100);
            } catch (\Throwable $exception) {
                $this->logger->error('IYS Gateway inbound cron synchronization failed.', [
                    'store_id' => $storeId,
                    'exception' => $exception,
                ]);
            }

            try {
                $this->exporter->execute($storeId);
            } catch (\Throwable $exception) {
                $this->logger->error('IYS Gateway outbound cron synchronization failed.', [
                    'store_id' => $storeId,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
