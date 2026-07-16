<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Api;

use FxCommerce\IysGateway\Api\Data\ConsentInterface;

interface ConsentManagementInterface
{
    /**
     * @param int $subscriberId
     * @return \FxCommerce\IysGateway\Api\Data\ConsentInterface
     */
    public function get($subscriberId);

    /**
     * @param int $fromId
     * @param int $limit
     * @param int|null $storeId
     * @return \FxCommerce\IysGateway\Api\Data\ConsentInterface[]
     */
    public function getList($fromId = 0, $limit = 100, $storeId = null);

    /**
     * @param \FxCommerce\IysGateway\Api\Data\ConsentInterface $consent
     * @return \FxCommerce\IysGateway\Api\Data\ConsentInterface
     */
    public function save(ConsentInterface $consent);

    /**
     * @param \FxCommerce\IysGateway\Api\Data\ConsentInterface[] $consents
     * @return \FxCommerce\IysGateway\Api\Data\ConsentInterface[]
     */
    public function saveBatch(array $consents);
}
