<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Client;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\LoadBalancer\Node;
use Hyperf\Nacos\Exception\RequestException;
use Hyperf\ServiceGovernance\IPReaderInterface;
use Hyperf\Utils\Codec\Json;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\UdpServer\UdpServer;

class Client implements ClientInterface
{
    /**
     * @var ContainerInterface
     */
    protected ContainerInterface $container;

    /**
     * @var ConfigInterface
     */
    protected mixed $config;

    /**
     * @var NacosClient
     */
    protected mixed $client;

    /**
     * @var StdoutLoggerInterface
     */
    protected mixed $logger;

    /**
     * @var IPReaderInterface
     */
    protected mixed $ipReader;

    /** @var string */
    protected string $clientIp = '';

    protected int $udpPort = 0;

    protected string $appName = "appName";

    protected array $consumers = [];

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->get(ConfigInterface::class);
        $this->client = $container->get(NacosClient::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->ipReader = $container->get(IPReaderInterface::class);
        $this->init();
    }

    /**
     * 初始化一些udp监听的东西
     * @return void
     */
    protected function init(): void
    {
        $this->appName = $this->config->get('app_name', 'appName');
        $this->clientIp = $this->ipReader->read();

        // 获取监听端口
        $servers = $this->config->get('server.servers');
        foreach ($servers as $server) {
            if ($this->udpPort > 0) {
                break;
            }

            if (!isset($server['name']) || $server['name'] !== 'udp') {
                continue;
            }

            if (empty($server['callbacks']['packet'][0]) || $server['callbacks']['packet'][0] != UdpServer::class) {
                continue;
            }

            $this->udpPort = intval($server['port']);
        }

        // 获取要拉去的服务
        $this->getServices();
    }

    /**
     * 供监听服务调用
     * @param string $serviceName
     * @param string $groupName
     * @param string $namespaceId
     * @param string $loadBalance
     * @return array
     */
    public function getNodesOneByListener(
        string $serviceName,
        string $groupName,
        string $namespaceId,
        string $loadBalance
    ): array {
        return $this->getNodesOne([
            'serviceName' => $serviceName,
            'groupName'   => $groupName,
            'namespaceId' => $namespaceId,
            'udpPort'     => $this->udpPort,
            'clientIP'    => $this->clientIp,
            'app'         => $this->appName,
            'loadBalance' => $loadBalance
        ]);
    }

    /**
     * 获取一个服务下的实列
     * @param array $item
     * @return array
     */
    public function getNodesOne(array $item): array
    {
        try {
            $response = $this->client->instance->list($item['serviceName'], [
                'groupName' => $item['groupName'],
                'namespaceId' => $item['namespaceId'],
                'udpPort' => $item['udpPort'],
                'clientIP' => $item['clientIP'],
                'app' => $item['app'],
                'healthyOnly' => 'true'
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new RequestException((string)$response->getBody(), $response->getStatusCode());
            }

            $data = Json::decode((string)$response->getBody());
            $hosts = $data['hosts'] ?? [];
            $nodes = [];
            foreach ($hosts as $node) {
                if (isset($node['ip'], $node['port']) && ($node['healthy'] ?? false)) {
                    $nodes[] = new Node(
                        $node['ip'],
                        $node['port'],
                        intval(100 * ($node['weight'] ?? 1)),
                        $node['path_prefix'] ?? ''
                    );
                }
            }

            return $nodes;
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf(
                    "获取服务实列出错，处理出错,err:%s",
                    $e->getMessage()
                ),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'code' => $e->getCode()
                ]
            );

            return [];
        }
    }

    public function getNodes(): array
    {
        $outData = [];
        // 一次性最多8个请求
        foreach (array_chunk($this->consumers, 8) as $items) {
            $callables = [];
            foreach ($items as $item) {
                $callables[] = function () use (&$outData, $item) {
                    $nodes = $this->getNodesOne($item);
                    $outData[$item['serviceName']] = [
                        'serviceName' => $item['serviceName'],
                        'loadBalance' => $item['loadBalance'],
                        'nodes'       => $nodes
                    ];
                };
            }

            parallel($callables, count($callables));
        }

        // 排序，外层有判断数组相等
        ksort($outData);

        return array_values($outData);
    }

    private function getServices()
    {
        if (!empty($this->consumers)) {
            return;
        }

        $groupName = $this->config->get('services.drivers.nacos.group_name');
        $list = (array)($this->config->get('services.consumers', []));

        $out = [];

        foreach ($list as $item) {
            $out[] = [
                'serviceName' => $item['name'],
                'loadBalance' => $item['load_balancer'],
                'namespaceId' => $item['namespace_id'],
                'udpPort'     => $this->udpPort,
                'clientIP'    => $this->clientIp,
                'app'         => $this->appName,
                'groupName'   => $groupName,
                'nodes'       => []
            ];
        }

        $this->consumers = $out;
    }
}