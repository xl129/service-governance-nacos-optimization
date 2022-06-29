<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\ServiceGovernance\IPReaderInterface;
use Hyperf\ServiceGovernance\ServiceManager;
use Hyperf\ServiceGovernanceNacos\Client;
use Hyperf\Utils\Coordinator\Constants;
use Hyperf\Utils\Coordinator\CoordinatorManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function Swoole\Coroutine\run;

class ServiceLogoutListener implements ListenerInterface
{
    /**
     * @var ContainerInterface
     */
    protected ContainerInterface $container;

    /**
     * @var StdoutLoggerInterface
     */
    protected mixed $logger;

    /**
     * @var IPReaderInterface
     */
    protected mixed $ipReader;

    /**
     * @var ServiceManager
     */
    protected mixed $serviceManager;

    /**
     * @var ConfigInterface
     */
    protected mixed $config;

    /**
     * @var bool
     */
    private bool $processed = false;

    /**
     * @var array
     */
    private array $listenerList = [];

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->ipReader = $container->get(IPReaderInterface::class);
        $this->serviceManager = $container->get(ServiceManager::class);
        $this->config = $container->get(ConfigInterface::class);
        $this->listenerList = (array)$this->config->get('services.enable.listener', []);
    }

    /**
     * 自定义下线事件，方便钩子函数
     *
     * 不建议监听 OnShutdown 事件，因为触发该事件时
     * 已关闭所有 Reactor 线程、HeartbeatCheck 线程、UdpRecv 线程
     * 已关闭所有 Worker 进程、 Task 进程、User 进程
     * 已 close 所有 TCP/UDP/UnixSocket 监听端口
     * 已关闭主 Reactor;
     *
     * 建议自定义事件，先下注册的服务，消费者监听变更，在停止服务
     *
     * @return array|string[]
     */
    public function listen(): array
    {
        return $this->listenerList;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(object $event)
    {
        $this->log('已经监听到服务下线信号');
        if ($this->processed) {
            return;
        }
        $this->processed = true;

        if (!$this->config->get('services.enable.autoLogout', true)) {
            return;
        }

        $client = $this->container->get(Client::class);
        $serverMap = $this->getServers();
        $servers = $this->serviceManager->all();

        $groupName = $this->config->get('services.drivers.nacos.group_name');
        $namespaceId = $this->config->get('services.drivers.nacos.namespace_id');
        $ephemeral = boolval($this->config->get('services.drivers.nacos.ephemeral'));
        $ip = $this->ipReader->read();

        $this->log(sprintf('服务【%s->%s】准备从nacos注销 ....', $namespaceId, $groupName));

        // 停止心跳，避免自动注册
        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

        // 服务下线，批量，加快下线速度
        $callables = [];
        foreach ($servers as $serviceName => $serviceProtocols) {
            foreach ($serviceProtocols as $paths) {
                foreach ($paths as $service) {
                    if (empty($serverMap[$service['server']]['port'])) {
                        continue;
                    }
                    $port = intval($serverMap[$service['server']]['port']);
                    $callables[] = function () use (
                        $client,
                        $serviceName,
                        $groupName,
                        $ip,
                        $port,
                        $namespaceId,
                        $ephemeral
                    ) {
                        $response = $client->instance->delete($serviceName, $groupName, $ip, $port, [
                            'namespaceId' => $namespaceId,
                            'ephemeral'   => $ephemeral ? 'true' : null,
                        ]);

                        $logData = [
                            'respone'     => $response->getBody()->getContents(),
                            'serviceName' => $serviceName,
                            'groupName'   => $groupName,
                            'ip'          => $ip,
                            'port'        => $port,
                            'namespaceId' => $namespaceId,
                            'ephemeral'   => $ephemeral,
                        ];

                        if ($response->getStatusCode() === 200) {
                            $this->log(sprintf('Instance %s deleted successfully!', $serviceName), $logData);
                        } else {
                            $this->log(sprintf('Instance %s deleted failed!', $serviceName), $logData);
                        }
                    };
                }
            }
        }

        run(function () use ($callables, $namespaceId, $groupName) {
            foreach (array_chunk($callables, 8) as $items) {
                parallel($callables, count($items));
            }
            $this->log(sprintf('服务【%s->%s】bye bye ....', $namespaceId, $groupName));
        });
    }

    protected function getServers(): array
    {
        return array_column($this->config->get('server.servers', []), null, 'name');
    }

    protected function log(string $message, array $content = [])
    {
        try {
            $this->logger->debug($message, $content);
        } catch (\Throwable $e) {
        }
    }
}
