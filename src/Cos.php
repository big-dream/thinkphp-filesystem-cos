<?php
declare (strict_types = 1);

namespace bigDream\thinkphp\filesystem;

use League\Flysystem\Filesystem;
use League\Flysystem\AdapterInterface;
use Overtrue\Flysystem\Cos\CosAdapter;
use think\filesystem\Driver;

class Cos extends Driver
{
    /**
     * 配置参数
     * @var array
     */
    protected $config = [
        // 可见性
        'visibility'      => '',
        // 磁盘路径对应的外部URL路径
        'url'             => '',
        // 地域
        'region'          => 'ap-guangzhou',
        // 认证凭证
        'credentials'     => [
            'appId'     => '', // 域名中数字部分
            'secretId'  => '',
            'secretKey' => '',
        ],
        // 储存桶
        'bucket'          => '',
        // 超时时间
        'timeout'         => '',
        // 连接超时时间
        'connect_timeout' => 60,
        // CDN 域名
        'cdn'             => '',
        // 协议
        'scheme'          => '',
        // 从CDN读取 
        'read_from_cdn'   => false,
    ];

    protected function createAdapter(): AdapterInterface
    {
        $adapter = new CosAdapter($config);

        return new Filesystem($adapter);
    }
}