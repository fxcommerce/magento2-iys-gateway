<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Model;

class SynchronizationContext
{
    private int $inboundDepth = 0;

    public function runInbound(callable $callback): mixed
    {
        ++$this->inboundDepth;
        try {
            return $callback();
        } finally {
            --$this->inboundDepth;
        }
    }

    public function isInbound(): bool
    {
        return $this->inboundDepth > 0;
    }
}
