<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization;

class PipeMessage implements PipeMessageInterface
{
    /**
     * @var array
     */
    protected array $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }
}