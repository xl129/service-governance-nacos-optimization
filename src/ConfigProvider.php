<?php

declare(strict_types=1);

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization;

use YuanxinHealthy\ServiceGovernanceNacosOptimization\Client\Client;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Client\NacosClient;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Client\NacosClientFactory;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Client\ClientInterface;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Client\ServiceClient;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\JsonRpc\JsonRpcTransporter;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener\CreateMessageFetcherLoopListener;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener\FetchInstanceOnBootListener;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener\OnPipeMessageListener;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener\ServiceLogoutListener;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Process\InstanceFetcherProcess;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ClientInterface::class                    => Client::class,
                NacosClient::class                        => NacosClientFactory::class,
                \Hyperf\RpcClient\ServiceClient::class    => ServiceClient::class,
                \Hyperf\JsonRpc\JsonRpcTransporter::class => JsonRpcTransporter::class

            ],
            'processes'    => [
                InstanceFetcherProcess::class
            ],
            'listeners'    => [
                FetchInstanceOnBootListener::class,
                CreateMessageFetcherLoopListener::class,
                OnPipeMessageListener::class,
                ServiceLogoutListener::class
            ],
            'annotations'  => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
        ];
    }
}
