<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class PreserveUnknownPhoneConsents implements DataPatchInterface
{
    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('newsletter_subscriber');
        $this->moduleDataSetup->startSetup();
        $connection->update(
            $table,
            ['sms_consent' => null],
            ['sms_consent = ?' => 0, 'sms_consent_at IS NULL']
        );
        $connection->update(
            $table,
            ['call_consent' => null],
            ['call_consent = ?' => 0, 'call_consent_at IS NULL']
        );
        $this->moduleDataSetup->endSetup();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
