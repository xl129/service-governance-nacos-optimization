<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Process\ProcessCollector;
use Hyperf\Utils\Coordinator\Constants;
use Hyperf\Utils\Coordinator\CoordinatorManager;
use Hyperf\Utils\Coroutine;
use Hyperf\LoadBalancer\LoadBalancerManager;
use Hyperf\Server\ServerFactory;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Process;
use Swoole\Server;
use Throwable;
use YuanXinHealthy\ServiceGovernanceNacosOptimization\Client\ClientInterface;
use YuanXinHealthy\ServiceGovernanceNacosOptimization\PipeMessageInterface;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\PipeMessage;

abstract class AbstractDriver implements DriverInterface
{
    /**
     * @var null|Server
     */
    protected ?Server $server;

    /**
     * @var null|ConfigInterface
     */
    protected mixed $config;

    /**
     * @var StdoutLoggerInterface
     */
    protected mixed $logger;

    /**
     * @var ClientInterface
     */
    protected ClientInterface $client;

    /**
     * @var ContainerInterface
     */
    protected ContainerInterface $container;

    /**
     * @var null|string
     */
    protected ?string $pipeMessage = PipeMessage::class;

    /**
     * @var LoadBalancerManager
     */
    protected mixed $loadBalancerManager;

    /**
     * @var string
     */
    protected string $driverName = '';

    /**
     * 清除掉序列化之后的缓存
     *
     * @var bool
     */
    protected bool $isClearCacheNodes = false;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->get(ConfigInterface::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->loadBalancerManager = $container->get(LoadBalancerManager::class);
    }

    /**
     * 调用对应的客户端获取服务实列，并更新
     * @return void
     */
    public function fetchInstance(): void
    {
        if (method_exists($this->client, 'getNodes')) {
            $nodes = $this->getNodes();
            $nodes && $this->updateNodes($nodes);
            $this->log('启动服务，拉取服务', $nodes);
        }
    }

    /**
     * 进程内循环
     * @return void
     */
    public function createMessageInstanceLoop(): void
    {
        Coroutine::create(function () {
            $interval = $this->getInterval();
            retry(INF, function () use ($interval) {
                $prevNodesSerialize = '';
                while (true) {
                    try {
                        // 清楚缓存
                        if ($this->isClearCacheNodes) {
                            $prevNodesSerialize = '';
                        }

                        $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);
                        $workerExited = $coordinator->yield($interval);
                        if ($workerExited) {
                            break;
                        }

                        $nodes = $this->getNodes();
                        $nodesSerialize = serialize($nodes);
                        if ($nodesSerialize !== $prevNodesSerialize) {
                            $this->log('定时器，拉取', $nodes);
                            $this->syncNodes($nodes);
                        }

                        $prevNodesSerialize = $nodesSerialize;
                    } catch (Throwable $e) {
                        $this->logger->error($e->getMessage(), [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'code' => $e->getCode(),
                        ]);
                    } finally {
                        $this->isClearCacheNodes = false;
                    }
                }
            }, $interval * 1000);
        });
    }

    /**
     * 获取当前服务对象
     * @return Server|null
     */
    public function getServer(): ?Server
    {
        return $this->server;
    }

    /**
     * 设置服务对象
     * @param $server
     * @return $this
     */
    public function setServer($server): AbstractDriver
    {
        $this->server = $server;
        return $this;
    }

    /**
     * 定时器拉去的时候同步相关进程
     * @param array $nodes
     * @param bool $isClearCacheNodes
     * @return void
     */
    public function syncNodes(array $nodes, bool $isClearCacheNodes = false): void
    {
        if ($isClearCacheNodes) {
            $this->isClearCacheNodes = true;
        }

        $this->updateNodes($nodes);
        if (class_exists(ProcessCollector::class) && !ProcessCollector::isEmpty()) {
            $this->shareNodesToProcesses($nodes);
        }
    }

    /**
     * 调用对应的客户端获取服务实列
     * @return array
     */
    protected function getNodes(): array
    {
        return $this->client->getNodes();
    }

    /**
     * 设置到调度器里面
     * @param array $nodes
     * @return void
     */
    protected function updateNodes(array $nodes): void
    {
        foreach ($nodes as $item) {
            // 即使 nodes 为空也要设置
            if (!isset($item['serviceName']) || !isset($item['loadBalance']) || !isset($item['nodes'])) {
                continue;
            }

            $this->loadBalancerManager->getInstance(
                $item['serviceName'],
                $item['loadBalance']
            )->setNodes((array)$item['nodes']);
        }
    }

    /**
     * 定时心跳时间
     * @return int
     */
    protected function getInterval(): int
    {
        return (int)$this->config->get('services.drivers.' . $this->driverName . '.heartbeat', 5);
    }

    /**
     * 共享到进程
     * @param array $nodes
     * @return void
     */
    protected function shareNodesToProcesses(array $nodes): void
    {
        $pipeMessage = $this->pipeMessage;
        $message = new $pipeMessage($nodes);
        if (!$message instanceof PipeMessageInterface) {
            throw new InvalidArgumentException('shareNodesToProcesses Invalid pipe message object.');
        }
        $this->shareMessageToWorkers($message);
        $this->shareMessageToUserProcesses($message);
    }

    /**
     * 通知所有work
     * @param PipeMessageInterface $message
     * @return void
     */
    protected function shareMessageToWorkers(PipeMessageInterface $message): void
    {
        if ($this->server instanceof Server) {
            // 当前work
            $currentWorkId = $this->server->getWorkerId();

            $workerCount = $this->server->setting['worker_num'] + ($this->server->setting['task_worker_num'] ?? 0) - 1;
            for ($workerId = 0; $workerId <= $workerCount; ++$workerId) {
                // 排除自己给自己发消息
                if (is_numeric($currentWorkId) && $currentWorkId == $workerId) {
                    continue;
                }

                $this->server->sendMessage($message, $workerId);
            }
        }
    }

    /**
     * 通知所有进程
     * @param PipeMessageInterface $message
     * @return void
     */
    protected function shareMessageToUserProcesses(PipeMessageInterface $message): void
    {
        $processes = ProcessCollector::all();
        if ($processes) {
            $string = serialize($message);
            $currentWorkId = -1;
            if ($this->server instanceof Server) {
                // 当前work
                $currentWorkId = $this->server->getWorkerPid();
            }

            /** @var Process $process */
            foreach ($processes as $process) {
                // 不给自己进程发消息
                if ($currentWorkId === $process->pid) {
                    continue;
                }

                $result = $process->exportSocket()->send($string, 10);
                if ($result === false) {
                    $this->logger->error('Instance synchronization failed.', [
                        'info' => json_encode($process->exportSocket() ?? [])
                    ]);
                }
            }
        }
    }

    /**
     * 处理更新消息
     * @param PipeMessageInterface $pipeMessage
     * @return void
     */
    public function onPipeMessage(PipeMessageInterface $pipeMessage): void
    {
        $this->log('收到其他进程的更新消息', $pipeMessage->getData());
        $this->updateNodes($pipeMessage->getData());
    }

    protected function log(string $message, array $nodes): void
    {
        if (in_array($this->config->get('app_env'), ['dev', 'test'])) {
            try {
                /** @var Server $svr */
                $svr = $this->container->get(ServerFactory::class)->getServer()->getServer();
                $this->logger->debug($message, [
                    'workPid' => $svr->getWorkerPid(),
                    'workId'  => is_numeric($svr->getWorkerId()) ? $svr->getWorkerId() : '进程',
                    'nodes'   => $nodes
                ]);
            } catch (Throwable $e) {
                unset($e);
            }
        }
    }
}