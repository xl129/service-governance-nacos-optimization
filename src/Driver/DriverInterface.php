<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver;

use YuanxinHealthy\ServiceGovernanceNacosOptimization\PipeMessageInterface;

interface DriverInterface
{
    public function fetchInstance();

    public function createMessageInstanceLoop(): void;

    public function onPipeMessage(PipeMessageInterface $pipeMessage): void;
}