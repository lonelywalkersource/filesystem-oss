<?php

namespace LonelyWalkerSource\FilesystemOss;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use OSS\Core\OssException;
use OSS\OssClient;

class OssAdapterr implements FilesystemAdapter
{
    protected PathPrefixer $prefix;

    //配置
    protected $options = [
        'Multipart' => 128,
    ];

    public function __construct(
        protected OssClient $client,
        protected string $bucket,
        protected string $endPoint,
        protected bool $ssl,
        protected bool $isCname = false,
        protected bool $debug = false,
        protected string $cdnDomain = '',
                            $prefix = '',
        array $options = []
    ) {
        $this->prefix = new PathPrefixer($prefix, DIRECTORY_SEPARATOR);
        $this->options = array_merge($this->options, $options);
    }

    /**
     * 检查文件是否存在.
     *
     * @param  string $path 文件路径
     * @return bool   文件是否存在
     */
    public function fileExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $this->prefix->prefixPath($path));
    }

    /**
     * 检查目录是否存在.
     *
     * @param  string $path 待检查的目录路径
     * @return bool   目录存在返回true，否则返回false
     */
    public function directoryExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $this->prefix->prefixDirectoryPath($path));
    }

    /**
     * 普通文件写入.
     */
    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->client->putObject($this->bucket, $this->prefix->prefixPath($path), $contents, $config->get('options', []));
        } catch (\Exception $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage());
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->client->uploadStream($this->bucket, $this->prefix->prefixPath($path), $contents, $config->get('options', []));
        } catch (OssException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getErrorCode(), $exception);
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->client->getObject($this->bucket, $this->prefix->prefixPath($path));
        } catch (\Exception $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage());
        }
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');

        try {
            $content = $this->client->getObject($this->bucket, $this->prefix->prefixPath($path), [OssClient::OSS_FILE_DOWNLOAD => $stream]);

            fwrite($stream, $content);
        } catch (OssException $exception) {
            fclose($stream);

            throw UnableToReadFile::fromLocation($path, $exception->getMessage());
        }
        rewind($stream);

        return $stream;
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (\Exception $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copyObject($this->bucket, $this->prefix->prefixPath($source), $this->bucket, $this->prefix->prefixPath($destination));
        } catch (OssException $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->deleteObject($this->bucket, $this->prefix->prefixPath($path));
        } catch (OssException $ossException) {
            throw UnableToDeleteFile::atLocation($path);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $contents = $this->listContents($path, false);
            $files = [];

            foreach ($contents as $i => $content) {
                if ($content instanceof DirectoryAttributes) {
                    $this->deleteDirectory($content->path());

                    continue;
                }
                $files[] = $this->prefix->prefixPath($content->path());

                if ($i && $i % 100 == 0) {
                    $this->client->deleteObjects($this->bucket, $files);
                    $files = [];
                }
            }

            if (! empty($files)) {
                $this->client->deleteObjects($this->bucket, $files);
            }
            $this->client->deleteObject($this->bucket, $this->prefix->prefixDirectoryPath($path));
        } catch (OssException $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getErrorCode(), $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->createObjectDir($this->bucket, $this->prefix->prefixPath($path));
        } catch (OssException $exception) {
            throw UnableToCreateDirectory::dueToFailure($path, $exception);
        }
    }

    public function getUrl(string $path): string
    {
        return $this->normalizeHost().ltrim($this->prefix->prefixPath($path), '/');
    }

    public function getTemporaryUrl(string $path, int|string|\DateTimeInterface $expiration, array $options = [], string $method = OssClient::OSS_HTTP_GET): bool|string
    {
        if ($expiration instanceof \DateTimeInterface) {
            $expiration = $expiration->getTimestamp();
        }

        if (is_string($expiration)) {
            $expiration = strtotime($expiration);
        }

        try {
            return $this->client->signUrl($this->bucket, $this->prefix->prefixPath($path), $expiration, $method, $options);
        } catch (OssException $exception) {
            return false;
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $acl = $visibility === Visibility::PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        try {
            $this->client->putObjectAcl($this->bucket, $this->prefix->prefixPath($path), $acl);
        } catch (OssException $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage());
        }
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $acl = $this->client->getObjectAcl($this->bucket, $this->prefix->prefixPath($path), []);
        } catch (OssException $exception) {
            throw UnableToRetrieveMetadata::visibility($path, $exception->getMessage());
        }

        return new FileAttributes($path, null, $acl === OssClient::OSS_ACL_TYPE_PRIVATE ? Visibility::PRIVATE : Visibility::PUBLIC);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $directory = $this->prefix->prefixDirectoryPath($path);
        $nextMarker = '';

        while (true) {
            try {
                $listObjectInfo = $this->client->listObjects($this->bucket, [OssClient::OSS_PREFIX => $directory, OssClient::OSS_MARKER => $nextMarker]);
                $nextMarker = $listObjectInfo->getNextMarker();
            } catch (OssException $exception) {
                throw new \Exception($exception->getErrorMessage(), 0, $exception);
            }

            $prefixList = $listObjectInfo->getPrefixList();

            foreach ($prefixList as $prefixInfo) {
                $subPath = $this->prefix->stripDirectoryPrefix($prefixInfo->getPrefix());

                if ($subPath == $path) {
                    continue;
                }

                yield new DirectoryAttributes($subPath);

                if ($deep === true) {
                    $contents = $this->listContents($subPath, $deep);

                    foreach ($contents as $content) {
                        yield $content;
                    }
                }
            }

            $listObject = $listObjectInfo->getObjectList();

            if (! empty($listObject)) {
                foreach ($listObject as $objectInfo) {
                    $objectPath = $this->prefix->stripPrefix($objectInfo->getKey());
                    $objectLastModified = strtotime($objectInfo->getLastModified());

                    if (substr($objectPath, -1, 1) == '/') {
                        continue;
                    }

                    yield new FileAttributes($objectPath, $objectInfo->getSize(), null, $objectLastModified);
                }
            }

            if ($listObjectInfo->getIsTruncated() !== 'true') {
                break;
            }
        }
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

    protected function normalizeHost(): string
    {
        $domain = $this->isCname ? ($this->cdnDomain === '' ? $this->endPoint : $this->cdnDomain) : $this->bucket.'.'.$this->endPoint;

        if ($this->ssl) {
            $domain = "https://{$domain}";
        } else {
            $domain = "http://{$domain}";
        }

        return rtrim($domain, '/').'/';
    }

    protected function getMetadata($path): FileAttributes
    {
        try {
            $result = $this->client->getObjectMeta($this->bucket, $this->prefix->prefixPath($path));
        } catch (OssException $exception) {
            throw UnableToRetrieveMetadata::create($path, 'metadata', $exception->getErrorCode(), $exception);
        }

        $size = isset($result['content-length']) ? intval($result['content-length']) : 0;
        $timestamp = isset($result['last-modified']) ? strtotime($result['last-modified']) : 0;
        $mimetype = $result['content-type'] ?? '';

        return new FileAttributes($path, $size, null, $timestamp, $mimetype);
    }
}