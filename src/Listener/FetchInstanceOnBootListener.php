<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Listener;

use Hyperf\Command\Event\BeforeHandle;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hyperf\Process\Event\BeforeProcessHandle;
use Hyperf\Server\Event\MainCoroutineServerStart;

class FetchInstanceOnBootListener extends OnPipeMessageListener
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
        $instance = $this->createDriverInstance();
        $instance && $instance->fetchInstance();
    }
}