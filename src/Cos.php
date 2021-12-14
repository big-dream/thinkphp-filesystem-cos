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
        // 必填，app_id、secret_id、secret_key 
        // 可在个人秘钥管理页查看：https://console.cloud.tencent.com/capi
        'app_id'     => 10020201024, 
        'secret_id'  => 'AKIDsiQzQla780mQxLLU2GJCxxxxxxxxxxx', 
        'secret_key' => 'b0GMH2c2NXWKxPhy77xhHgwxxxxxxxxxxx',
    
        'region'     => 'ap-guangzhou', 
        'bucket'     => 'example',
        
        // 可选，如果 bucket 为私有访问请打开此项
        'signed_url' => false,
        
        // 可选，使用 CDN 域名时指定生成的 URL host
        'cdn'        => 'https://youcdn.domain.com/',
    ];

    protected function createAdapter(): AdapterInterface
    {
        reutrn new CosAdapter($this->config);
    }
}