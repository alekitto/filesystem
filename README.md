# KCS Filesystem

Filesystem abstraction library with local, Async S3, and Google Cloud Storage adapters, plus a PHP stream wrapper and Symfony bundle integration.

## Installation

```bash
composer require kcs/filesystem
```

## Basic usage

### Local filesystem

```php
use Kcs\Filesystem\Local\LocalFilesystem;

$fs = new LocalFilesystem('/var/app/storage');
$fs->write('hello.txt', 'Hello!');
$contents = $fs->read('hello.txt')->read(1024);
```

### Async S3

```php
use AsyncAws\S3\S3Client;
use Kcs\Filesystem\AsyncS3\AsyncS3Filesystem;

$client = new S3Client([
    'region' => 'eu-west-1',
    'accessKeyId' => '...',
    'accessKeySecret' => '...',
]);

$fs = new AsyncS3Filesystem('my-bucket', '/', $client);
$fs->write('path/file.txt', 'content');
```

### Google Cloud Storage

```php
use Google\Cloud\Storage\StorageClient;
use Kcs\Filesystem\GCS\GCSFilesystem;

$client = new StorageClient([
    'projectId' => 'my-project',
    'keyFilePath' => '/path/to/credentials.json',
]);

$fs = new GCSFilesystem('my-bucket', '/', $client);
$fs->write('path/file.txt', 'content');
```

## Symfony bundle configuration

```yaml
# config/packages/filesystem.yaml
filesystem:
  storages:
    local_storage:
      type: local
      stream_wrapper_protocol: localfs
      options:
        path: '%kernel.project_dir%/var/storage'

    s3_storage:
      type: s3
      options:
        bucket: '%env(S3_BUCKET)%'
        region: '%env(S3_REGION)%'
        access_key: '%env(S3_ACCESS_KEY)%'
        secret_key: '%env(S3_SECRET_KEY)%'
        prefix: 'app'

    gcs_storage:
      type: gcs
      options:
        bucket: '%env(GCS_BUCKET)%'
        project_id: '%env(GCS_PROJECT_ID)%'
        key_file_path: '%env(resolve:GCS_KEY_FILE)%'
        prefix: 'app'
```

### GCS options

- `bucket` (required)
- `prefix` (optional, default `/`)
- `project_id` (optional)
- `key_file_path` (optional)
- `api_endpoint` (optional)
- `client` (optional, service id)

## Stream wrapper

Enable a `stream_wrapper_protocol` to register a PHP stream wrapper for a storage. For example `localfs://path/to/file.txt`.
