<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnPipeMessage;
use Hyperf\Process\Event\PipeMessage as UserProcessPipeMessage;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver\DriverInterface;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\PipeMessageInterface;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver\DriverFactory;

class OnPipeMessageListener implements ListenerInterface
{
    /**
     * @var DriverFactory
     */
    protected DriverFactory $driverFactory;

    /**
     * @var ConfigInterface
     */
    protected ConfigInterface $config;

    /**
     * @var StdoutLoggerInterface
     */
    protected StdoutLoggerInterface $logger;

    public function __construct(DriverFactory $driverFactory, ConfigInterface $config, StdoutLoggerInterface $logger)
    {
        $this->driverFactory = $driverFactory;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            OnPipeMessage::class,
            UserProcessPipeMessage::class,
        ];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event)
    {
        if ($instance = $this->createDriverInstance()) {
            if ($event instanceof OnPipeMessage || $event instanceof UserProcessPipeMessage) {
                $event->data instanceof PipeMessageInterface && $instance->onPipeMessage($event->data);
            }
        }
    }

    protected function createDriverInstance(): ?DriverInterface
    {
        if (! $this->config->get('services.enable.discovery', false)) {
            return null;
        }

        $driver = $this->config->get('services.driver', '');
        if (! $driver) {
            return null;
        }

        return $this->driverFactory->create($driver);
    }
}