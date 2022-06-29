### 服务监听使用配置
* server.php
    ```php
        [
            'name' => 'udp',
            'type' => Server::SERVER_BASE,
            'host' => '0.0.0.0',
            'port' => 9506,
            'sock_type' => SWOOLE_SOCK_UDP,
            'callbacks' => [
                Event::ON_PACKET => [\YuanxinHealthy\ServiceGovernanceNacosOptimization\UdpServer\UdpServer::class, 'onPacket'],
            ],
            'settings' => [
                // 按需配置
            ],
        ],
    ```
### 服务发现和自动下线配置
* services.php
    ```php
        'enable' => [
            // 开启服务发现
            'discovery'  => true,
            // 开启服务注册
            'register'   => true,
            // 是否自动下线
            'autoLogout' => true,
            // 配置自动下线事件
            'listener'   => [
                /*
                * 不建议监听 OnShutdown 事件，因为触发该事件时
                * 已关闭所有 Reactor 线程、HeartbeatCheck 线程、UdpRecv 线程
                * 已关闭所有 Worker 进程、 Task 进程、User 进程
                * 已 close 所有 TCP/UDP/UnixSocket 监听端口
                * 已关闭主 Reactor;建议自定义事件，先下注册的服务，消费者监听变更，在停止服务
                */
                \Hyperf\Framework\Event\OnShutdown::class
            ]
        ],
        'mode'   => \YuanxinHealthy\ServiceGovernanceNacosOptimization\Mode::PROCESS, // 进程模式
        'driver' => 'nacos',
        'drivers' => [
            'nacos' => [
                'driver' => \YuanxinHealthy\ServiceGovernanceNacosOptimization\Driver\NacosDriver::class, // 服务发现驱动
                'guzzle' => [
                    'config' => [ // header 头 版本号就用默认的Nacos-Go-Client:vX.X.X，否则不能实现监听
                        'headers' => [
                            'charset'        => 'UTF-8',
                            'Client-Version' => env('NACOS_VERSION', 'Nacos-Go-Client:v1.0.1'),
                            'User-Agent'     => env('NACOS_VERSION', 'Nacos-Go-Client:v1.0.1'),
                            'Connection'     => 'Keep-Alive',
                        ],
                    ],
                ],
                'heartbeat': 5 // 单位秒，心跳周期不能设置过长，否则会失去监听
            ]
        ]
    ```