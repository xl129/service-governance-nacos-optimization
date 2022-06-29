<?php

namespace YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver;

use Hyperf\Contract\ConfigInterface;

class DriverFactory
{
    /**
     * @var ConfigInterface
     */
    protected ConfigInterface $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function create(string $driver, array $properties = []): DriverInterface
    {
        $config = $this->config->get('services.drivers.' . $driver, []);
        $class = $config['driver'];
        $instance = make($class, $config);
        foreach ($properties as $method => $value) {
            if (method_exists($instance, $method)) {
                $instance->{$method}($value);
            }
        }
        return $instance;
    }
}
