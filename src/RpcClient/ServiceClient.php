<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\RpcClient;

use Hyperf\LoadBalancer\Exception\RuntimeException;
use Hyperf\LoadBalancer\LoadBalancerInterface;
use Hyperf\Rpc\Exception\RecvException;

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

    public function __call(string $method, array $params)
    {
        try {
            return parent::__call($method, $params);
        } catch (RuntimeException $e) {
            $newMessage = sprintf(
                "serviceName:%s,loadBalancer:%s,method:%s,err:%s",
                $this->serviceName,
                $this->loadBalancer,
                $method,
                $e->getMessage()
            );

            throw new RuntimeException($newMessage);
        } catch (RecvException $e) {
            $newMessage = sprintf(
                "serviceName:%s,loadBalancer:%s,method:%s,err:%s",
                $this->serviceName,
                $this->loadBalancer,
                $method,
                $e->getMessage()
            );
            throw new RecvException($newMessage);
        }
    }
}