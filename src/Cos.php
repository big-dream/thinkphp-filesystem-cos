<?php
declare (strict_types = 1);

namespace bigDream\thinkphp\filesystem;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PathPrefixer;
use League\Flysystem\WhitespacePathNormalizer;
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
        // 必填，app_id、secret_id、secret_key
        // 可在个人秘钥管理页查看：https://console.cloud.tencent.com/capi
        'app_id' => 10020201024,
        'secret_id' => 'AKIDsiQzQla780mQxLLU2GJCxxxxxxxxxxx',
        'secret_key' => 'b0GMH2c2NXWKxPhy77xhHgwxxxxxxxxxxx',
        // 地域
        'region' => 'ap-guangzhou',
        // 储存桶
        'bucket' => 'example',

        // 可选，如果 bucket 为私有访问请打开此项
        'signed_url' => false,

        // 可选，使用 CDN 域名时指定生成的 URL host
        'cdn' => 'https://youcdn.domain.com/',
        'prefix' => '',
    ];

    /**
     * @var PathPrefixer
     */
    protected $prefixer;

    /**
     * @var PathNormalizer
     */
    protected $normalizer;

    protected function createAdapter(): FilesystemAdapter
    {
        return new CosAdapter($this->config);
    }

    protected function prefixer()
    {
        if (null === $this->prefixer) {
            $this->prefixer = new PathPrefixer($this->config['prefix']);
        }

        return $this->prefixer;
    }

    protected function normalizer()
    {
        if (null === $this->normalizer) {
            $this->normalizer = new WhitespacePathNormalizer();
        }

        return $this->normalizer;
    }

    /**
     * 获取文件访问地址
     * @param string $path 文件路径
     * @return string
     */
    public function url(string $path): string
    {
        $path = $this->prefixer()->prefixPath($path);
        $path = $this->normalizer()->normalizePath($path);

        if (isset($this->config['url'])) {
            return $this->concatPathToUrl($this->config['url'], $path);
        }

        return parent::url($path);
    }
}