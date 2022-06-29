<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Process;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Server;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver\DriverFactory;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Mode;

class InstanceFetcherProcess extends AbstractProcess
{

    public $name = 'instance-center-fetcher';

    /**
     * @var Server
     */
    protected Server $server;

    /**
     * @var ConfigInterface
     */
    protected mixed $config;

    /**
     * @var StdoutLoggerInterface
     */
    protected mixed $logger;

    /**
     * @var DriverFactory
     */
    protected DriverFactory $driverFactory;


    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->config = $container->get(ConfigInterface::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->driverFactory = $container->get(DriverFactory::class);
    }

    public function bind($server): void
    {
        $this->server = $server;
        parent::bind($server);
    }

    public function isEnable($server): bool
    {
        return $server instanceof Server
            && $this->config->get('services.enable.discovery', false)
            && strtolower($this->config->get('services.mode', Mode::PROCESS)) === Mode::PROCESS;
    }

    public function handle(): void
    {
        $driver = $this->config->get('services.driver', '');
        if (!$driver) {
            return;
        }

        $instance = $this->driverFactory->create($driver, [
            'setServer' => $this->server,
        ]);

        // 起一个进程拉去定时拉去服务
        $instance->createMessageInstanceLoop();

        while (ProcessManager::isRunning()) {
            sleep(1);
        }
    }
}