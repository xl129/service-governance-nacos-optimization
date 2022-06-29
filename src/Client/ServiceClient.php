<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Client;

use Hyperf\LoadBalancer\LoadBalancerInterface;

class ServiceClient extends \Hyperf\RpcClient\ServiceClient
{
    protected function createLoadBalancer(array $nodes, callable $refresh = null): LoadBalancerInterface
    {
        // 不需要每个work的每个服务启动定时器
        return $this->loadBalancerManager->getInstance($this->serviceName, $this->loadBalancer);
    }

    /**
     * @return array
     */
    protected function createNodes(): array
    {
        return [[], null];
    }
}