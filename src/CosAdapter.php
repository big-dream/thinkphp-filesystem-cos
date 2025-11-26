<?php

namespace bigDream\thinkphp\filesystem;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Overtrue\CosClient\BucketClient;
use Overtrue\CosClient\Exceptions\ClientException;
use Overtrue\CosClient\ObjectClient;
use Throwable;

class CosAdapter implements FilesystemAdapter
{
    /**
     * @var ObjectClient
     */
    protected ObjectClient $objectClient;

    /**
     * @var BucketClient
     */
    protected BucketClient $bucketClient;

    /**
     * @var PathPrefixer
     */
    protected PathPrefixer $prefixer;

    /**
     * @var array
     */
    protected array $config;

    /**
     * CosAdapter constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = array_merge(
            [
                // 储存桶名称
                'bucket'     => '',
                // 应用ID
                'app_id'     => '',
                // 密钥ID
                'secret_id'  => '',
                // 密钥KEY
                'secret_key' => '',
                // 请求协议
                'schema'     => 'http',
                // 地域
                'region'     => 'ap-guangzhou',
                // 路径前缀
                'prefix'     => '',
            ],
            $config
        );

        $this->prefixer = new PathPrefixer($this->config['prefix'], '/');
    }

    /**
     * 检测文件是否存在
     * @param string $path
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        try {
            $this->getMetadata($this->prefixer->prefixPath($path));
        } catch (Throwable $e) {
            if ($e instanceof ClientException && 404 === $e->getCode()) {
                return false;
            }
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }

        return true;
    }

    /**
     * 检测目录是否存在
     * @param string $path
     * @return bool
     */
    public function directoryExists(string $path): bool
    {
        return $this->fileExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $headers = ['x-cos-acl' => $this->normalizeVisibility($config->get('visibility'))];
            $this->getObjectClient()->putObject($this->prefixer->prefixPath($path), $contents, $headers);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        try {
            $response = $this->getObjectClient()->getObject($this->prefixer->prefixPath($path));
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        return $response->getBody();
    }

    public function readStream(string $path)
    {
        try {
            $response = $this->getObjectClient()->getObject($this->prefixer->prefixPath($path));
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        return $response->getBody()->detach();
    }

    public function delete(string $path): void
    {
        try {
            $this->getObjectClient()->deleteObject($this->prefixer->prefixPath($path));
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $objects = [];
            foreach ($this->listContents($path, true) as $item) {
                $objects[]['Key'] = $item->path();
            }

            $this->getObjectClient()->deleteObjects([
                'Delete' => [
                    'Quiet' => 'false',
                    'Object' => $objects,
                ],
            ]);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $prefixPath = $this->prefixer->prefixPath($path);
        if (str_ends_with($prefixPath, '/')) $prefixPath .= '/';

        $this->getObjectClient()->putObject($prefixPath, '');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $headers = [
            'x-cos-acl' => $this->normalizeVisibility($visibility),
        ];
        $this->getObjectClient()->putObjectAcl($this->prefixer->prefixPath($path), [], $headers);
    }

    public function visibility(string $path): FileAttributes
    {
        $response = $this->getObjectClient()->getObjectAcl($this->prefixer->prefixPath($path));

        $grants = $response['AccessControlPolicy']['AccessControlList']['Grant'] ?? [];
        // 继承权限会返回关联数组
        $grants = array_is_list($grants) ? $grants : [$grants];
        foreach ($grants as $grant) {
            if ('READ' !== $grant['Permission']) continue;
            if (str_ends_with($acl['Grantee']['URI'] ?? '', '/groups/global/AllUsers')) continue;
        }

        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if (null === $meta->mimeType()) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return $meta;
    }

    public function lastModified(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if ($meta->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return $meta;
    }

    public function fileSize(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if ($meta->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return $meta;
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefixPath = $this->prefixer->prefixPath($path);
        if ('' !== $prefixPath) $prefixPath .= '/';

        $response = $this->getBucketClient()->getObjects([
            'prefix'    => $prefixPath,
            'delimiter' => $deep ? '' : '/',
        ]);

        $list = $response['ListBucketResult']['CommonPrefixes'] ?? [];
        // 只有一个目录时返回关联数组
        foreach (array_is_list($list) ? $list : [$list] as $item)
        {
            yield new DirectoryAttributes($item['Prefix']);
        }

        $list = $response['ListBucketResult']['Contents'] ?? [];
        // 只有一个文件时返回关联数组
        foreach (array_is_list($list) ? $list : [$list] as $item)
        {
            $extra = [
                'ETag'         => $item['ETag'],
                'StorageClass' => $item['StorageClass'],
                'Owner'        => $item['Owner'],
            ];
            yield new FileAttributes($item['Key'], (int)$item['Size'], null, strtotime($item['LastModified']), null, $extra);
        }
    }

    public function move(string $source, string $destination, $config = []): void
    {
        $this->copy($source, $destination, $config);

        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $prefixedSource = $this->prefixer->prefixPath($source);

        $prefixedDestination = $this->prefixer->prefixPath($destination);

        try {
            $headers = [
                'x-cos-copy-source' => sprintf(
                    '%s-%s.cos.%s.myqcloud.com/%s',
                    $this->config['bucket'],
                    $this->config['app_id'],
                    $this->config['region'],
                    $prefixedSource
                ),
            ];
            $this->getObjectClient()->copyObject($prefixedDestination, $headers);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $prefixedDestination, $e);
        }
    }

    /**
     * 获取文件元信息
     * @param string $path
     * @return FileAttributes
     */
    public function getMetadata($path): FileAttributes
    {
        $prefixedPath = $this->prefixer->prefixPath($path);

        $meta = $this->getObjectClient()->headObject($prefixedPath)->getHeaders();

        return new FileAttributes(
            $path,
            isset($meta['Content-Length'][0]) ? (int)$meta['Content-Length'][0] : null,
            null,
            isset($meta['Last-Modified'][0]) ? strtotime($meta['Last-Modified'][0]) : null,
            $meta['Content-Type'][0] ?? null,
            $meta
        );
    }

    /**
     * 设置文件元信息
     * @param string $path
     * @param array $metadata
     * @return void
     * @throws \Overtrue\CosClient\Exceptions\InvalidArgumentException
     */
    public function setMetadata($path, $metadata): void
    {
        $originMeta = $this->getMetadata($path);

        $headers = [
            'Content-Type' => $originMeta->mimeType(),
            'x-cos-metadata-directive' => 'Replaced',
            'x-cos-copy-source' => sprintf(
                '%s-%s.cos.%s.myqcloud.com/%s',
                $this->config['bucket'],
                $this->config['app_id'],
                $this->config['region'],
                $path
            )
        ];
        foreach ($originMeta->extraMetadata() as $key => $value) {
            if (str_starts_with($key, 'x-cos-meta-')) {
                $headers[$key] = $value[0];
            }
        }

        $headers = array_merge($headers, $metadata);

        try {
            $this->getObjectClient()->copyObject($path, $headers);
        } catch (ClientException $e) {
            $message = $e->getResponse()['Message'];

            throw null === $message
                ? UnableToCopyFile::fromLocationTo($path, $path, $e)
                : UnableToCopyFile::because($message, $path, $path);
        }
    }

    public function getBucketClient(): BucketClient
    {
        return $this->bucketClient ?? $this->bucketClient = new BucketClient([
            'use_https'  => 'https' === $this->config['schema'],
            'region'     => $this->config['region'],
            'app_id'     => $this->config['app_id'],
            'secret_id'  => $this->config['secret_id'],
            'secret_key' => $this->config['secret_key'],
            'bucket'     => $this->config['bucket'],
        ]);
    }

    public function getObjectClient(): ObjectClient
    {
        return $this->objectClient ?? $this->objectClient = new ObjectClient([
            'use_https'  => 'https' === $this->config['schema'],
            'region'     => $this->config['region'],
            'app_id'     => $this->config['app_id'],
            'secret_id'  => $this->config['secret_id'],
            'secret_key' => $this->config['secret_key'],
            'bucket'     => $this->config['bucket'],
        ]);
    }

    protected function normalizeVisibility(string $visibility): string
    {
        if ('' === $visibility) return 'default';
        return $visibility === Visibility::PUBLIC ? 'public-read' : $visibility;
    }
}