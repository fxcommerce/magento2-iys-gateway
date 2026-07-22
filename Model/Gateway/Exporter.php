<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\Gateway;

use FxCommerce\IysGateway\Model\Config;
use FxCommerce\IysGateway\Model\Queue;
use FxCommerce\IysGateway\Model\ResourceModel\Queue\Collection;
use FxCommerce\IysGateway\Model\ResourceModel\Queue\CollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Exporter
{
    /** @var array<int, string> */
    private array $lastErrors = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Config $config,
        private readonly Client $client,
        private readonly Json $json,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Export a single configured batch for each requested store.
     *
     * Cron intentionally uses this method so one cron execution stays bounded.
     */
    public function execute(?int $storeId = null): int
    {
        if ($storeId !== null) {
            unset($this->lastErrors[$storeId]);
        }

        if ($storeId === null) {
            $total = 0;
            foreach ($this->storeManager->getStores() as $store) {
                $total += $this->execute((int)$store->getId());
            }
            return $total;
        }

        if (!$this->config->isEnabled($storeId)) {
            return 0;
        }

        $collection = $this->createReadyCollection($storeId);
        $collection->setOrder('queue_id', 'ASC');
        $collection->setPageSize($this->config->getBatchSize($storeId));
        $collection->setCurPage(1);

        if (!$collection->getSize()) {
            return 0;
        }

        $events = [];
        $items = [];
        foreach ($collection as $item) {
            try {
                $payload = $this->json->unserialize((string)$item->getData('payload'));
                if (!is_array($payload)) {
                    throw new \RuntimeException('Queue payload is not a JSON object.');
                }
                if ((int)($payload['storeId'] ?? 0) !== $storeId) {
                    throw new \RuntimeException('Queue store ID does not match the export scope.');
                }
                $events[] = $payload;
                $items[] = $item;
                $item->setData('status', Queue::STATUS_PROCESSING)->save();
            } catch (\Throwable $exception) {
                $this->markFailed($item, $exception, $storeId);
            }
        }

        if (!$events) {
            return 0;
        }

        try {
            $this->client->sendEvents($events, $storeId);
            foreach ($items as $item) {
                $item->setData('status', Queue::STATUS_SUCCESS)
                    ->setData('last_error', null)
                    ->save();
            }
            return count($items);
        } catch (\Throwable $exception) {
            $this->lastErrors[$storeId] = $exception->getMessage();
            foreach ($items as $item) {
                $this->markFailed($item, $exception, $storeId);
            }
            $this->logger->error('IYS Gateway store batch export failed.', [
                'store_id' => $storeId,
                'exception' => $exception,
            ]);
            return 0;
        }
    }

    /**
     * Drain all currently ready queue records in configured batch sizes.
     *
     * This is used by the manual CLI --export operation. It reports every batch
     * and stops safely when a batch cannot make progress (for example, a remote
     * API error moved the records into retry backoff).
     *
     * @param callable(array<string, int|string|null>):void|null $progress
     * @return array{exported:int,batches:int,remaining:int,ready:int,failed:int}
     */
    public function executeAll(?int $storeId = null, ?callable $progress = null): array
    {
        $summary = [
            'exported' => 0,
            'batches' => 0,
            'remaining' => 0,
            'ready' => 0,
            'failed' => 0,
        ];

        $storeIds = $storeId !== null
            ? [$storeId]
            : array_map(
                static fn($store): int => (int)$store->getId(),
                array_values($this->storeManager->getStores())
            );

        foreach ($storeIds as $targetStoreId) {
            $batchNumber = 0;
            $store = $this->storeManager->getStore($targetStoreId);
            $storeCode = (string)$store->getCode();

            if (!$this->config->isEnabled($targetStoreId)) {
                $remaining = $this->countOutstanding($targetStoreId);
                $failed = $this->countFailed($targetStoreId);
                $summary['remaining'] += $remaining;
                $summary['failed'] += $failed;
                continue;
            }

            while (true) {
                $readyBefore = $this->countReady($targetStoreId);
                if ($readyBefore === 0) {
                    break;
                }

                ++$batchNumber;
                ++$summary['batches'];
                $exported = $this->execute($targetStoreId);
                $summary['exported'] += $exported;

                $readyAfter = $this->countReady($targetStoreId);
                $remaining = $this->countOutstanding($targetStoreId);
                $failed = $this->countFailed($targetStoreId);

                if ($progress !== null) {
                    $progress([
                        'storeId' => $targetStoreId,
                        'storeCode' => $storeCode,
                        'batch' => $batchNumber,
                        'exported' => $exported,
                        'ready' => $readyAfter,
                        'remaining' => $remaining,
                        'failed' => $failed,
                        'error' => $this->lastErrors[$targetStoreId] ?? null,
                    ]);
                }

                if ($readyAfter >= $readyBefore) {
                    break;
                }
            }

            $summary['remaining'] += $this->countOutstanding($targetStoreId);
            $summary['ready'] += $this->countReady($targetStoreId);
            $summary['failed'] += $this->countFailed($targetStoreId);
        }

        return $summary;
    }

    public function getLastError(int $storeId): ?string
    {
        return $this->lastErrors[$storeId] ?? null;
    }

    public function countReady(int $storeId): int
    {
        return (int)$this->createReadyCollection($storeId)->getSize();
    }

    public function countOutstanding(int $storeId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->addFieldToFilter('status', [
            'in' => [Queue::STATUS_PENDING, Queue::STATUS_PROCESSING, Queue::STATUS_FAILED],
        ]);
        return (int)$collection->getSize();
    }

    public function countFailed(int $storeId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->addFieldToFilter('status', Queue::STATUS_FAILED);
        return (int)$collection->getSize();
    }

    public function retryOutstandingNow(int $storeId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->addFieldToFilter('status', [
            'in' => [Queue::STATUS_PENDING, Queue::STATUS_PROCESSING, Queue::STATUS_FAILED],
        ]);
        $count = 0;
        foreach ($collection as $item) {
            $item->setData('status', Queue::STATUS_PENDING)
                ->setData('attempts', 0)
                ->setData('available_at', gmdate('Y-m-d H:i:s'))
                ->setData('last_error', null)
                ->save();
            ++$count;
        }
        return $count;
    }

    private function createReadyCollection(int $storeId): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->addFieldToFilter('status', ['in' => [Queue::STATUS_PENDING, Queue::STATUS_FAILED]]);
        $collection->addFieldToFilter('attempts', ['lt' => $this->config->getMaxAttempts($storeId)]);
        $collection->addFieldToFilter('available_at', ['lteq' => gmdate('Y-m-d H:i:s')]);
        return $collection;
    }

    private function markFailed(Queue $item, \Throwable $exception, int $storeId): void
    {
        $attempts = (int)$item->getData('attempts') + 1;
        $maxAttempts = $this->config->getMaxAttempts($storeId);
        $delay = min(3600, 30 * (2 ** min(7, $attempts - 1)));
        $item->setData('attempts', $attempts)
            ->setData('status', $attempts >= $maxAttempts ? Queue::STATUS_FAILED : Queue::STATUS_PENDING)
            ->setData('available_at', gmdate('Y-m-d H:i:s', time() + $delay))
            ->setData('last_error', mb_substr($exception->getMessage(), 0, 65535))
            ->save();
    }
}
