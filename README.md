<h1 align="center"> filesystem-oss </h1>

<p align="center"> .</p>


## Installing

```shell
$ composer require lonelywalkersource/filesystem-oss
```

```php
use League\Flysystem\Filesystem;
use OSS\OssClient;
use LonelyWalkerSource\FilesystemOss\OssAdapter;

$accessId = 'xxxxxx';
$accessKey = 'xxxxxx';
$cdnDomain = 'xxxx.bkt.clouddn.com';
$bucket = 'bucket-name';
$ssl = <true|false>;
$isCname = <true|false>;
$debug = <true|false>;
$endPoint = 'endpoint'; // 默认作为外部节点
$epInternal = 'endpoint_internal'; // 内部节点

$client = new OssClient($accessId, $accessKey, $epInternal, $isCname);

$adapter = new OssAdapter($client, $bucket, $endPoint, $ssl, $isCname, $debug, $cdnDomain);

$flysystem = new League\Flysystem\Filesystem($adapter);
```

## API

```php
bool $flysystem->write('file.md', 'contents');
bool $flysystem->write('file.md', 'http://httpbin.org/robots.txt', ['mime' => 'application/redirect302']);
bool $flysystem->writeStream('file.md', fopen('path/to/your/local/file.jpg', 'r'));
bool $flysystem->copy('foo.md', 'foo2.md');
bool $flysystem->delete('file.md');
bool $flysystem->fileExists('file.md');
bool $flysystem->directoryExists('path/to/dir');
string|false $flysystem->read('file.md');
array $flysystem->listContents();
int $flysystem->fileSize('file.md');
string $flysystem->mimeType('file.md');
```

Adapter extended methods:

```php
string $adapter->getUrl('file.md');
string $adapter->getTemporaryUrl($path, int|string|\DateTimeInterface $expiration);
```