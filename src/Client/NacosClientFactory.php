<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Client;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Nacos\Application;
use Hyperf\Nacos\Config;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class NacosClientFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->get(ConfigInterface::class)->get('services.drivers.nacos', []);
        if (empty($config)) {
            return $container->get(Application::class);
        }

        if (!empty($config['uri'])) {
            $baseUri = $config['uri'];
        } else {
            $baseUri = sprintf('http://%s:%d', $config['host'] ?? '127.0.0.1', $config['port'] ?? 8848);
        }

        return new Application(
            new Config([
                'base_uri'      => $baseUri,
                'username'      => $config['username'] ?? null,
                'password'      => $config['password'] ?? null,
                'guzzle_config' => $config['guzzle']['config'] ?? null,
            ])
        );
    }
}