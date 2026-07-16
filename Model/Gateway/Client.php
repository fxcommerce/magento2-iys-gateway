<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model\Gateway;

use FxCommerce\IysGateway\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;

class Client
{
    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function sendEvents(array $events, ?int $storeId = null): array
    {
        if (!$events) {
            return ['status' => 'ok', 'processed' => 0];
        }
        if ($storeId === null || $storeId <= 0) {
            throw new \RuntimeException('A Magento store ID is required for IYS synchronization.');
        }

        $store = $this->storeManager->getStore($storeId);
        $actualStoreCode = strtolower((string)$store->getCode());
        $boundStoreCode = strtolower($this->config->getStoreCode($storeId));
        if (!hash_equals($boundStoreCode, $actualStoreCode)) {
            throw new \RuntimeException(sprintf(
                'The Gateway access key belongs to store code "%s", but current store is "%s".',
                $boundStoreCode,
                $actualStoreCode
            ));
        }

        foreach ($events as $event) {
            if (!is_array($event) || strtolower((string)($event['storeCode'] ?? '')) !== $actualStoreCode) {
                throw new \RuntimeException('A batch may contain records from only one Magento store view.');
            }
        }

        $baseUrl = $this->config->getBaseUrl($storeId);
        $integrationId = $this->config->getIntegrationId($storeId);
        $apiKey = $this->config->getApiKey($storeId);
        $apiSecret = $this->config->getApiSecret($storeId);

        $payload = ['events' => array_values($events)];
        $body = $this->json->serialize($payload);
        $timestamp = (string)time();
        $contentHash = hash('sha256', $body);
        $signature = hash_hmac('sha256', $timestamp . '.' . $contentHash, $apiSecret);

        $this->curl->setTimeout($this->config->getRequestTimeout($storeId));
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('X-IYS-Integration-Id', $integrationId);
        $this->curl->addHeader('X-IYS-Api-Key', $apiKey);
        $this->curl->addHeader('X-IYS-Timestamp', $timestamp);
        $this->curl->addHeader('X-IYS-Content-SHA256', $contentHash);
        $this->curl->addHeader('X-IYS-Signature', $signature);
        $this->curl->post($baseUrl . $this->config->getEndpointPath($storeId), $body);

        $status = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'IYS Gateway request failed with HTTP %d: %s',
                $status,
                mb_substr($responseBody, 0, 2000)
            ));
        }

        if ($responseBody === '') {
            return ['status' => 'ok'];
        }

        $decoded = $this->json->unserialize($responseBody);
        return is_array($decoded) ? $decoded : ['status' => 'ok'];
    }
}
