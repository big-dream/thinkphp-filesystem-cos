<?php

namespace bigDream\thinkphp\filesystem;

use Exception;
use GuzzleHttp\Command\Result;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;
use Qcloud\Cos\Client;

class CosAdapter implements FilesystemAdapter
{
    /**
     * @var Client|null
     */
    protected $client;

    /**
     * @var PathPrefixer
     */
    protected $prefixer;

    /**
     * @var array
     */
    protected $config;

    /**
     * CosAdapter constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = array_merge(
            [
                'bucket' => null,
                'app_id' => null,
                'region' => 'ap-guangzhou',
            ],
            $config
        );

        $this->prefixer = new PathPrefixer($config['prefix'] ?? '', DIRECTORY_SEPARATOR);
    }

    public function fileExists(string $path): bool
    {
        return $this->getClient()->doesObjectExist($this->getBucket(), $this->prefixer->prefixPath($path));
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $options = ['ACL' => $this->normalizeVisibility($config->get('visibility'))];
        $this->getClient()->upload($this->getBucket(), $this->prefixer->prefixPath($path), $contents, $options);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($this->prefixer->prefixPath($path), stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        $prefixedPath = $this->prefixer->prefixPath($path);

        try {
            /** @var Result $response */
            $response = $this->getClient()->getObject(['Bucket' => $this->getBucket(), 'Key' => $prefixedPath]);
        } catch (Exception $e) {
            throw UnableToReadFile::fromLocation($prefixedPath, $e->getMessage());
        }

        return $response['Body'];
    }

    public function readStream(string $path)
    {
        $prefixedPath = $this->prefixer->prefixPath($path);

        try {
            /** @var Result $response */
            $response = $this->getClient()->getObject(['Bucket' => $this->getBucket(), 'Key' => $prefixedPath,]);
        } catch (Exception $e) {
            throw UnableToReadFile::fromLocation($prefixedPath, $e->getMessage());
        }

        return $response['Body'];
    }

    public function delete(string $path): void
    {
        $prefixedPath = $this->prefixer->prefixPath($path);

        try {
            $this->getClient()->deleteObject(['Bucket' => $this->getBucket(), 'Key' => $prefixedPath]);
        } catch (Exception $e) {
            throw UnableToDeleteFile::atLocation($prefixedPath, $e->getMessage());
        }
    }

    public function deleteDirectory(string $path): void
    {
        $list = [];
        foreach ($this->listContents($path . '/', true) as $item) {
            $list[] = $item->path();
        }

        foreach (array_reverse($list) as $path) $this->delete($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $dirname = rtrim($this->prefixer->prefixPath($path), '/') . '/';

        $this->getClient()->putObject(['Bucket' => $this->getBucket(), 'Key' => $dirname, 'Body' => '']);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->getClient()->putObjectAcl([
            'Bucket' => $this->getBucket(),
            'Key' => $this->prefixer->prefixPath($path),
            'ACL' => $this->normalizeVisibility($visibility),
        ]);
    }

    public function visibility(string $path): FileAttributes
    {
        $response = $this->getClient()->getObjectAcl([
            'Bucket' => $this->getBucket(),
            'Key' => $this->prefixer->prefixPath($path),
        ]);

        foreach ($response['Grants'] as $grants) {
            foreach ($grants as $grant) {
                if ((array_keys($grant) !== range(0, count($grant) - 1))) continue;

                foreach ($grant as $acl) {
                    if ('READ' === $acl['Permission'] && strpos($acl['Grantee']['URI'] ?? '', '/groups/global/AllUsers')) {
                        return new FileAttributes($response['Key'], null, Visibility::PUBLIC);
                    }
                }
            }
        }

        return new FileAttributes($response['Key'], null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if ($meta->mimeType() === null) {
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
        $result = $this->getClient()->listObjects([
            'Bucket'       => $this->getBucket(),
            'Delimiter'    => $deep ? '' : '/',
            'EncodingType' => 'url',
            'Prefix'       => rtrim($this->prefixer->prefixPath($path), '/') . '/',
        ]);

        foreach ($result['Contents'] ?? [] as $content) {
            $extra = [
                'ETag'         => $content['ETag'],
                'StorageClass' => $content['StorageClass'],
                'Owner'        => $content['Owner'],
            ];
            $lastModified = strtotime($content['LastModified']);
            if (false === strpos($content['Key'], '/', -1)) {
                yield new FileAttributes($content['Key'], (int)$content['Size'], null, $lastModified, null, $extra);
            } else {
                yield new DirectoryAttributes($content['Key'], null, $lastModified, $extra);
            }
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

        $copySource = [
            'Region' => $this->config['region'],
            'Bucket' => $this->getBucket(),
            'Key'    => $prefixedSource,
        ];

        $options = [
            'x-cos-copy-source' => $prefixedSource,
        ];

        try {
            /** @var Result $response */
            $this->getClient()->copy($this->getBucket(), $prefixedDestination, $copySource, $options);
        } catch (Exception $e) {
            throw UnableToCopyFile::fromLocationTo($prefixedSource, $prefixedDestination, $e);
        }
    }

    public function getMetadata($path): FileAttributes
    {
        $prefixedPath = $this->prefixer->prefixPath($path);

        try {
            /**
             * @var Result $response
             */
            $response = $this->getClient()->headObject([
                'Bucket' => $this->getBucket(),
                'Key' => $prefixedPath,
            ]);
        } catch (Exception $e) {
            throw new UnableToRetrieveMetadata($e->getMessage(), 0, $e);
        }

        return new FileAttributes(
            $response['Key'],
            $response['ContentLength'],
            null,
            strtotime($response['LastModified']),
            $response['ContentType'],
            $response->toArray()
        );
    }

    public function getClient()
    {
        return $this->client ?? $this->client = new Client(array(
            'region' => $this->config['region'],
            'schema' => $this->config['schema'],
            'credentials' => array(
                'secretId'  => $this->config['secret_id'],
                'secretKey' => $this->config['secret_key'],
            )
        ));
    }

    protected function getBucket(): string
    {
        return $this->config['bucket'] . '-' . $this->config['app_id'];
    }

    protected function normalizeVisibility(string $visibility): string
    {
        if ('' === $visibility) return 'default';
        return $visibility === Visibility::PUBLIC ? 'public-read' : $visibility;
    }
}