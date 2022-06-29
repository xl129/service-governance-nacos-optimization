<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener;

use Hyperf\Command\Event\BeforeHandle;
use YuanxinHealthy\ServiceGovernanceNacosOptimization\Mode;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hyperf\Process\Event\BeforeProcessHandle;
use Hyperf\Server\Event\MainCoroutineServerStart;

class CreateMessageFetcherLoopListener extends OnPipeMessageListener
{
    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
            BeforeProcessHandle::class,
            BeforeHandle::class,
            MainCoroutineServerStart::class,
        ];
    }

    public function process(object $event)
    {
        $mode = strtolower($this->config->get('services.mode', Mode::PROCESS));
        if ($mode === Mode::COROUTINE) {
            $instance = $this->createDriverInstance();
            $instance && $instance->createMessageInstanceLoop();
        }
    }
}