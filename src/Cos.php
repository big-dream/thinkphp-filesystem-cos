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
        // 磁盘路径对应的外部URL路径
        'url'        => 'https://example-10020201024.cos.ap-guangzhou.myqcloud.com',
        // 请求协议
        'schema'     => 'http',
        // 可见性
        'visibility' => '',
        // 地域
        'region'     => 'ap-guangzhou',
        // 应用ID
        'app_id'     => 10020201024,
        // 密钥ID
        'secret_id'  => 'AKIDsiQzQla780mQxLLU2GJCxxxxxxxxxxx',
        // 密钥KEY
        'secret_key' => 'b0GMH2c2NXWKxPhy77xhHgwxxxxxxxxxxx',
        // 储存桶名称
        'bucket'     => 'example',
        // 路径前缀
        'prefix'     => '',
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