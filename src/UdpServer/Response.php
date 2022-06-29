<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\UdpServer;

class Response
{
    public string $type;

    public string $lastRefTime;

    public string $data = "";

    /**
     * @param string $type
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * @param string $lastRefTime
     */
    public function setLastRefTime(string $lastRefTime): void
    {
        $this->lastRefTime = $lastRefTime;
    }

    /**
     * @param string $data
     */
    public function setData(string $data): void
    {
        $this->data = $data;
    }
}
