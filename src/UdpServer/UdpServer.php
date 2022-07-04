<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\UdpServer;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Server;
use Throwable;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver\DriverInterface;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Client\ClientInterface;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver\DriverFactory;

class UdpServer
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
     * @var null|ConfigInterface
     */
    protected mixed $config;

    protected Server $server;

    protected ClientInterface $client;

    /**
     * @var DriverFactory
     */
    protected DriverFactory $driverFactory;

    /**
     * @var ExceptionHandlerDispatcher
     */
    protected ExceptionHandlerDispatcher $exceptionHandlerDispatcher;

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function __construct(ContainerInterface $container, ExceptionHandlerDispatcher $exceptionHandlerDispatcher)
    {
        $this->container = $container;
        $this->exceptionHandlerDispatcher = $exceptionHandlerDispatcher;
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->config = $container->get(ConfigInterface::class);
        $this->client = $container->get(ClientInterface::class);
        $this->driverFactory = $container->get(DriverFactory::class);
    }

    /**
     * @param Server $server
     * @param string $data
     * @param array $clientInfo
     * @return void
     */
    public function onPacket(Server $server, string $data, array $clientInfo): void
    {
        try {
            $this->server = $server;
            $data = json_decode($data, true);
            $lastRefTime = time();
            $response = new Response();
            $response->setLastRefTime($lastRefTime);

            if (empty($data) || !is_array($data) || !isset($data['type'])) {
                $response->setType('unknow-ack');
                $this->sendTo($clientInfo, $response);
                return;
            }

            $this->logger->debug('收到nacos消息', [
                'data' => $data,
                'clientInfo' => $clientInfo
            ]);

            $lastRefTime = strval(isset($data['lastRefTime']) ? intval($data['lastRefTime'] / 1000) : time());
            $response->setLastRefTime($lastRefTime);

            switch ($data['type']) {
                case 'dom':
                    // no break
                case 'service':
                    $content = json_decode($data['data'], true);
                    $this->upInstance(is_array($content) ? $content : []);
                    $response->setType('push-ack');
                    $this->sendTo($clientInfo, $response);
                    return;
                case 'dump':
                    $response->setType('dump-ack');
                    $this->sendTo($clientInfo, $response);
                    return;
                default:
                    $response->setType('unknow-ack');
                    $this->sendTo($clientInfo, $response);
                    return;
            }
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf(
                    "收到nacos服务消息，处理出错,err:%s",
                    $e->getMessage()
                ),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'code' => $e->getCode()
                ]
            );
        }
    }

    private function sendTo(array $clientInfo, Response $response): void
    {
        $this->server->sendto(
            $clientInfo['address'],
            $clientInfo['port'],
            json_encode($response)
        );
    }

    /**
     * 调用接口全量更新，upd不一定是及时和可靠的
     *
     * @param array $message
     * @return void
     */
    private function upInstance(array $message): void
    {
        if (empty($message['name'])) {
            return;
        }

        [, $serviceName] = explode("@@", $message['name']);
        if (empty($serviceName)) {
            return;
        }

        $groupName = $this->config->get('services.drivers.nacos.group_name');
        $list = (array)($this->config->get('services.consumers', []));

        // 与本机比对，拿到相关服务信息
        $loadBalance = $namespaceId = "";
        foreach ($list as $item) {
            if ($serviceName == $item['name']) {
                $namespaceId = $item['namespace_id'];
                $loadBalance = $item['load_balancer'];
                break;
            }
        }

        if (empty($loadBalance) || empty($namespaceId)) {
            return;
        }

        // 获取变更后的节点
        if (method_exists($this->client, "getNodesOneByListener")) {
            $nodes = $this->client->getNodesOneByListener(
                $serviceName,
                $groupName,
                $namespaceId,
                $loadBalance
            );

            // 同步其他进程
            $driver = $this->createDriverInstance();
            if (method_exists($driver, "syncNodes")) {
                $this->logger->debug('开始同步服务变更', [
                    'serviceName' => $serviceName,
                    'loadBalance' => $loadBalance,
                    'nodes'       => $nodes
                ]);

                $driver->syncNodes([
                    [
                        'serviceName' => $serviceName,
                        'loadBalance' => $loadBalance,
                        'nodes'       => $nodes
                    ],
                    true
                ]);
            }
        }
    }

    protected function createDriverInstance(): ?DriverInterface
    {
        $driver = $this->config->get('services.driver', '');
        if (!$driver) {
            return null;
        }

        return $this->driverFactory->create($driver, [
            'setServer' => $this->server,
        ]);
    }
}
