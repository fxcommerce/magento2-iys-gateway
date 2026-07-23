<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PREFIX = 'fxcommerce_iys/';
    private const TOKEN_VERSION = 'iysg1';
    private const INGESTION_ENDPOINT_PATH = '/api/v2/ingestion/consents/batch';
    private const ACTION_PULL_ENDPOINT_PATH = '/api/v2/integration/consent-actions/pull';
    private const ACTION_ACK_ENDPOINT_PATH = '/api/v2/integration/consent-actions/ack';
    private const DEFAULT_REQUEST_TIMEOUT = 15;

    private array $connectionCache = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'general/enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) && $this->getAccessToken($storeId) !== '';
    }

    public function isCronEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync/cron_enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getAccessToken(?int $storeId = null): string
    {
        $storedValue = trim((string)$this->value('general/access_token', $storeId));
        if ($storedValue === '') {
            return '';
        }

        $plainToken = $this->normalizeToken($storedValue);
        if (str_starts_with($plainToken, self::TOKEN_VERSION . '.')) {
            return $plainToken;
        }

        try {
            $decryptedValue = trim((string)$this->encryptor->decrypt($storedValue));
        } catch (\Throwable) {
            $decryptedValue = '';
        }

        if ($decryptedValue !== '') {
            $decryptedToken = $this->normalizeToken($decryptedValue);
            if (str_starts_with($decryptedToken, self::TOKEN_VERSION . '.')) {
                return $decryptedToken;
            }
        }

        return $plainToken;
    }

    public function getBaseUrl(?int $storeId = null): string
    {
        return rtrim((string)$this->connection($storeId)['gatewayUrl'], '/');
    }

    public function getIntegrationId(?int $storeId = null): string
    {
        return (string)$this->connection($storeId)['integrationId'];
    }

    public function getStoreCode(?int $storeId = null): string
    {
        return strtolower((string)$this->connection($storeId)['storeCode']);
    }

    public function getApiKey(?int $storeId = null): string
    {
        return (string)$this->connection($storeId)['apiKey'];
    }

    public function getApiSecret(?int $storeId = null): string
    {
        return (string)$this->connection($storeId)['apiSecret'];
    }

    public function getEndpointPath(?int $storeId = null): string
    {
        $this->connection($storeId);
        return self::INGESTION_ENDPOINT_PATH;
    }

    public function getActionPullEndpointPath(?int $storeId = null): string
    {
        $this->connection($storeId);
        return self::ACTION_PULL_ENDPOINT_PATH;
    }

    public function getActionAckEndpointPath(?int $storeId = null): string
    {
        $this->connection($storeId);
        return self::ACTION_ACK_ENDPOINT_PATH;
    }

    public function getRequestTimeout(?int $storeId = null): int
    {
        $timeout = (int)($this->connection($storeId)['requestTimeout'] ?? self::DEFAULT_REQUEST_TIMEOUT);
        return max(1, min(60, $timeout));
    }

    public function getBatchSize(?int $storeId = null): int
    {
        return max(1, min(1000, (int)$this->value('sync/batch_size', $storeId) ?: 100));
    }

    public function getMaxAttempts(?int $storeId = null): int
    {
        return max(1, min(100, (int)$this->value('sync/max_attempts', $storeId) ?: 10));
    }

    public function getPhoneSource(?int $storeId = null): string
    {
        return (string)($this->value('phone/source', $storeId) ?: 'subscriber');
    }

    public function getPhoneAttributeCode(?int $storeId = null): string
    {
        if ($this->getPhoneSource($storeId) === 'customer') {
            return trim((string)($this->value('phone/customer_attribute', $storeId)
                ?: $this->value('phone/attribute_code', $storeId)));
        }

        return trim((string)($this->value('phone/subscriber_field', $storeId)
            ?: $this->value('phone/attribute_code', $storeId)
            ?: 'phone_number'));
    }

    public function getPhoneLabel(?int $storeId = null): string
    {
        return $this->displayValue('phone_label', (string)__('GSM Number'), $storeId);
    }

    public function getPhoneNote(?int $storeId = null): string
    {
        return $this->displayValue(
            'phone_note',
            (string)__('SMS and call permissions apply to this GSM number.'),
            $storeId
        );
    }

    public function getSmsLabel(?int $storeId = null): string
    {
        return $this->displayValue(
            'sms_label',
            (string)__('I consent to receive commercial SMS messages.'),
            $storeId
        );
    }

    public function getCallLabel(?int $storeId = null): string
    {
        return $this->displayValue(
            'call_label',
            (string)__('I consent to receive commercial phone calls.'),
            $storeId
        );
    }

    private function displayValue(string $field, string $default, ?int $storeId = null): string
    {
        $value = trim((string)$this->value('phone/display/' . $field, $storeId));
        return (string)__($value !== '' ? $value : $default);
    }

    private function normalizeToken(string $value): string
    {
        $normalized = preg_replace('/\s+/', '', trim($value));
        return is_string($normalized) ? $normalized : trim($value);
    }

    private function connection(?int $storeId = null): array
    {
        $cacheKey = $storeId ?? 0;
        if (isset($this->connectionCache[$cacheKey])) {
            return $this->connectionCache[$cacheKey];
        }

        $token = $this->getAccessToken($storeId);
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_VERSION) {
            throw new \RuntimeException('The IYS Gateway access key format is invalid.');
        }

        $payloadSegment = $parts[1];
        $payloadJson = $this->base64UrlDecode($payloadSegment);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('The IYS Gateway access key payload is invalid.');
        }

        $required = ['gatewayUrl', 'integrationId', 'storeCode', 'apiKey', 'apiSecret'];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new \RuntimeException(sprintf('The IYS Gateway access key is missing %s.', $field));
            }
        }

        if (!filter_var($payload['gatewayUrl'], FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('The IYS Gateway access key contains an invalid Gateway URL.');
        }
        if (!preg_match('/^[a-z0-9_-]+$/', strtolower($payload['storeCode']))) {
            throw new \RuntimeException('The IYS Gateway access key contains an invalid store code.');
        }

        $expectedSignature = hash_hmac('sha256', $payloadSegment, $payload['apiSecret']);
        if (!hash_equals($expectedSignature, strtolower($parts[2]))) {
            throw new \RuntimeException('The IYS Gateway access key signature is invalid.');
        }

        return $this->connectionCache[$cacheKey] = $payload;
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('The IYS Gateway access key encoding is invalid.');
        }

        return $decoded;
    }

    private function value(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue(
            self::XML_PREFIX . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
