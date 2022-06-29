<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Client;

interface ClientInterface
{
    // 获取服务的节点
    public function getNodes(): array;
}