<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Symfony\DependencyInjection;

use AsyncAws\S3\S3Client;
use Kcs\Filesystem\AsyncS3\AsyncS3Filesystem;
use Kcs\Filesystem\Filesystem;
use Kcs\Filesystem\Local\LocalFilesystem;
use RuntimeException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use function assert;
use function class_exists;
use function is_array;
use function is_string;

class FilesystemExtension extends Extension
{
    /**
     * @param array<string, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        foreach ($config['storages'] as $name => $storageConfig) {
            $options = $container->resolveEnvPlaceholders($storageConfig['options']);
            assert(is_array($options));

            $adapter = $this->createDefinition($storageConfig['type'], $options);
            if ($adapter !== null) {
                $container->setDefinition('kcs_filesystem.filesystem.' . $name, $adapter)->setPublic(false);
            } else {
                $container->setAlias('kcs_filesystem.filesystem.' . $name, $storageConfig['adapter'])->setPublic(false);
            }

            $container->registerAliasForArgument('kcs_filesystem.filesystem.' . $name, Filesystem::class, $name)->setPublic(false);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createDefinition(string $type, array $options): ?Definition
    {
        switch ($type) {
            case 's3':
                if (! class_exists(S3Client::class)) {
                    throw new RuntimeException('Async S3 client is needed to use s3 filesystem. Try run composer require async-aws/s3');
                }

                if (isset($options['client'])) {
                    if (! is_string($options['client'])) {
                        throw new InvalidConfigurationException('S3 client must be a string service name in filesystem configuration.');
                    }

                    $client = new Reference($options['client']);
                } elseif (isset($options['access_key']) || isset($options['secret_key']) || isset($options['region'])) {
                    $configuration = [];
                    if (isset($options['access_key'])) {
                        $configuration['accessKeyId'] = $options['access_key'];
                    }

                    if (isset($options['secret_key'])) {
                        $configuration['accessKeySecret'] = $options['secret_key'];
                    }

                    if (isset($options['region'])) {
                        $configuration['region'] = $options['region'];
                    }

                    $client = new Definition(S3Client::class, [$configuration]);
                }

                return new Definition(AsyncS3Filesystem::class, [$options['bucket'], $options['prefix'] ?? '/', $client ?? null]);

            case 'local':
                $arguments = [$options['path'], []];
                if (isset($options['file_permissions'])) {
                    $arguments[1]['file_permissions'] = $options['file_permissions'];
                }

                if (isset($options['dir_permissions'])) {
                    $arguments[1]['dir_permissions'] = $options['dir_permissions'];
                }

                return new Definition(LocalFilesystem::class, $arguments);

            default:
                return null;
        }
    }
}
