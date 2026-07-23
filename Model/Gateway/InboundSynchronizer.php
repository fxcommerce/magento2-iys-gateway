<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\Gateway;

use FxCommerce\IysGateway\Model\Config;
use FxCommerce\IysGateway\Model\ConsentStorage;
use Psr\Log\LoggerInterface;

class InboundSynchronizer
{
    public function __construct(
        private readonly Client $client,
        private readonly ConsentStorage $consentStorage,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Pull and apply one bounded action batch.
     *
     * @return array{pulled:int,applied:int,failed:int}
     */
    public function execute(int $storeId, int $limit = 100): array
    {
        $response = $this->client->pullActions($storeId, $limit);
        $actions = $response['actions'] ?? [];
        if (!is_array($actions) || !$actions) {
            return ['pulled' => 0, 'applied' => 0, 'failed' => 0];
        }

        $results = [];
        $applied = 0;
        $failed = 0;
        foreach ($actions as $action) {
            $actionId = is_array($action) ? trim((string)($action['actionId'] ?? '')) : '';
            try {
                $normalized = $this->normalizeAction($action);
                if (!$this->isChannelEnabled($normalized['channel'], $storeId)) {
                    $results[] = ['actionId' => $normalized['actionId'], 'success' => true];
                    ++$applied;
                    $this->logger->info('Skipped disabled IYS consent channel for store view.', [
                        'store_id' => $storeId,
                        'action_id' => $normalized['actionId'],
                        'channel' => $normalized['channel'],
                    ]);
                    continue;
                }
                $this->consentStorage->applyInboundAction(
                    $normalized['email'],
                    $normalized['phone'],
                    $storeId,
                    $normalized['channel'],
                    $normalized['status'] === 'APPROVED',
                    $normalized['consentAt']
                );
                $results[] = ['actionId' => $normalized['actionId'], 'success' => true];
                ++$applied;
            } catch (\Throwable $exception) {
                ++$failed;
                if ($actionId !== '') {
                    $results[] = [
                        'actionId' => $actionId,
                        'success' => false,
                        'error' => mb_substr($exception->getMessage(), 0, 2000),
                    ];
                }
                $this->logger->error('Unable to apply inbound IYS consent action.', [
                    'store_id' => $storeId,
                    'action_id' => $actionId,
                    'exception' => $exception,
                ]);
            }
        }

        if ($results) {
            $this->client->acknowledgeActions($storeId, $results);
        }

        return [
            'pulled' => count($actions),
            'applied' => $applied,
            'failed' => $failed,
        ];
    }

    /**
     * Drain all currently ready inbound actions.
     *
     * @param callable(array<string,int>):void|null $progress
     * @return array{pulled:int,applied:int,failed:int,batches:int}
     */
    public function executeAll(int $storeId, ?callable $progress = null): array
    {
        $summary = ['pulled' => 0, 'applied' => 0, 'failed' => 0, 'batches' => 0];
        while (true) {
            $batch = $this->execute($storeId, 100);
            if ($batch['pulled'] === 0) {
                break;
            }
            ++$summary['batches'];
            $summary['pulled'] += $batch['pulled'];
            $summary['applied'] += $batch['applied'];
            $summary['failed'] += $batch['failed'];
            if ($progress !== null) {
                $progress([
                    'batch' => $summary['batches'],
                    'pulled' => $batch['pulled'],
                    'applied' => $batch['applied'],
                    'failed' => $batch['failed'],
                ]);
            }
            if ($batch['pulled'] < 100) {
                break;
            }
        }
        return $summary;
    }

    private function isChannelEnabled(string $channel, int $storeId): bool
    {
        return match ($channel) {
            'SMS' => $this->config->isSmsEnabled($storeId),
            'CALL' => $this->config->isCallEnabled($storeId),
            default => true,
        };
    }

    /** @return array{actionId:string,email:string,phone:string,channel:string,status:string,consentAt:string} */
    private function normalizeAction(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('IYS action is not a JSON object.');
        }
        $actionId = trim((string)($value['actionId'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $actionId)) {
            throw new \RuntimeException('IYS action ID is invalid.');
        }
        $email = strtolower(trim((string)($value['email'] ?? '')));
        $channel = strtoupper(trim((string)($value['channel'] ?? '')));
        if (!in_array($channel, ['EMAIL', 'SMS', 'CALL'], true)) {
            throw new \RuntimeException('IYS action channel is invalid.');
        }
        $phone = trim((string)($value['phone'] ?? ''));
        if ($channel === 'EMAIL' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('IYS action email is invalid.');
        }
        if ($channel !== 'EMAIL' && $email === '' && $phone === '') {
            throw new \RuntimeException('IYS SMS/call action has neither an email nor a phone number.');
        }
        $status = strtoupper(trim((string)($value['status'] ?? '')));
        if (!in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new \RuntimeException('IYS action status is invalid.');
        }
        $consentAt = trim((string)($value['consentAt'] ?? ''));
        try {
            new \DateTimeImmutable($consentAt);
        } catch (\Throwable) {
            throw new \RuntimeException('IYS action timestamp is invalid.');
        }
        return compact('actionId', 'email', 'phone', 'channel', 'status', 'consentAt');
    }
}
