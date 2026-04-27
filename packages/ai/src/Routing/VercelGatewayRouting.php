<?php

declare(strict_types=1);

namespace Pi\AI\Routing;

readonly class VercelGatewayRouting
{
    /**
     * @param  array<int, string>  $only
     * @param  array<int, string>  $order
     */
    public function __construct(
        public array $only = [],
        public array $order = [],
    ) {}
}
