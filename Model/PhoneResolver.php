<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

use Magento\Newsletter\Model\Subscriber;

class PhoneResolver
{
    public function __construct(
        private readonly PhoneStorage $phoneStorage
    ) {
    }

    public function resolve(Subscriber $subscriber): string
    {
        $consentRecipient = $this->phoneStorage->consentRecipient($subscriber);
        if ($consentRecipient !== '') {
            return $consentRecipient;
        }
        return $this->phoneStorage->read($subscriber);
    }
}
