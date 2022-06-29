<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver;

use Psr\Container\ContainerInterface;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Client\ClientInterface;

class NacosDriver extends AbstractDriver
{
    protected ClientInterface $client;

    protected string $driverName = 'nacos';

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->client = $container->get(ClientInterface::class);
    }
}