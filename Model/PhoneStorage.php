<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Newsletter\Model\Subscriber;

class PhoneStorage
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function read(Subscriber $subscriber): string
    {
        $storeId = (int)$subscriber->getStoreId();
        $code = $this->config->getPhoneAttributeCode($storeId);
        if ($this->config->getPhoneSource($storeId) === 'customer' && $subscriber->getCustomerId()) {
            try {
                $attribute = $this->customerRepository
                    ->getById((int)$subscriber->getCustomerId())
                    ->getCustomAttribute($code);
                return $this->normalize($attribute ? (string)$attribute->getValue() : '');
            } catch (\Throwable) {
                return '';
            }
        }
        return $this->normalize((string)$subscriber->getData($code ?: 'phone_number'));
    }

    public function write(Subscriber $subscriber, string $value): string
    {
        $phone = $this->normalize($value);
        if ($value !== '' && $phone === '') {
            throw new LocalizedException(__('The GSM number format is invalid.'));
        }
        $storeId = (int)$subscriber->getStoreId();
        $code = $this->config->getPhoneAttributeCode($storeId);
        if ($this->config->getPhoneSource($storeId) === 'customer') {
            if (!$subscriber->getCustomerId()) {
                throw new LocalizedException(__('A customer attribute cannot be used for a guest newsletter subscriber.'));
            }
            $customer = $this->customerRepository->getById((int)$subscriber->getCustomerId());
            $customer->setCustomAttribute($code, $phone !== '' ? $phone : null);
            $this->customerRepository->save($customer);
            return $phone;
        }
        $subscriber->setData($code ?: 'phone_number', $phone !== '' ? $phone : null);
        return $phone;
    }

    public function consentRecipient(Subscriber $subscriber): string
    {
        return $this->normalize((string)$subscriber->getData('phone_number'));
    }

    public function isCurrentRecipient(Subscriber $subscriber): bool
    {
        $current = $this->read($subscriber);
        return $current !== '' && hash_equals($current, $this->consentRecipient($subscriber));
    }

    private function normalize(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') return '';
        $digits = preg_replace('/\D+/', '', $trimmed) ?: '';
        if (str_starts_with($trimmed, '00')) $normalized = '+' . substr($digits, 2);
        elseif (str_starts_with($trimmed, '+')) $normalized = '+' . $digits;
        elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) $normalized = '+90' . substr($digits, 1);
        elseif (strlen($digits) === 10) $normalized = '+90' . $digits;
        elseif (strlen($digits) === 12 && str_starts_with($digits, '90')) $normalized = '+' . $digits;
        else $normalized = $digits;
        $count = strlen(preg_replace('/\D+/', '', $normalized) ?: '');
        return $count >= 10 && $count <= 15 ? $normalized : '';
    }
}
