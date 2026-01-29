<?php

declare(strict_types=1);

namespace Tests\Symfony;

use Google\Cloud\Storage\StorageClient;
use Kcs\Filesystem\GCS\GCSFilesystem;
use Kcs\Filesystem\Symfony\DependencyInjection\FilesystemExtension;
use Kcs\Filesystem\Symfony\StreamWrapperRegisterer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class FilesystemExtensionTest extends TestCase
{
    public function testRegistersGcsFilesystemWithInlineClientConfig(): void
    {
        $container = new ContainerBuilder();
        $extension = new FilesystemExtension();

        $extension->load([
            [
                'storages' => [
                    'gcs_storage' => [
                        'type' => 'gcs',
                        'options' => [
                            'bucket' => 'my-bucket',
                            'prefix' => 'root',
                            'project_id' => 'my-project',
                        ],
                    ],
                ],
            ],
        ], $container);

        $definition = $container->getDefinition('kcs_filesystem.filesystem.gcs_storage');
        self::assertSame(GCSFilesystem::class, $definition->getClass());
        self::assertSame('my-bucket', $definition->getArgument(0));
        self::assertSame('root', $definition->getArgument(1));
        self::assertFalse($definition->isPublic());

        $clientDefinition = $definition->getArgument(2);
        self::assertInstanceOf(Definition::class, $clientDefinition);
        self::assertSame(StorageClient::class, $clientDefinition->getClass());
        self::assertSame([['projectId' => 'my-project']], $clientDefinition->getArguments());
    }

    public function testRegistersGcsFilesystemWithClientService(): void
    {
        $container = new ContainerBuilder();
        $extension = new FilesystemExtension();

        $extension->load([
            [
                'storages' => [
                    'gcs_storage' => [
                        'type' => 'gcs',
                        'options' => [
                            'bucket' => 'my-bucket',
                            'client' => 'app.gcs_client',
                        ],
                    ],
                ],
            ],
        ], $container);

        $definition = $container->getDefinition('kcs_filesystem.filesystem.gcs_storage');
        self::assertSame(GCSFilesystem::class, $definition->getClass());
        self::assertSame('my-bucket', $definition->getArgument(0));
        self::assertSame('/', $definition->getArgument(1));
        self::assertEquals(new Reference('app.gcs_client'), $definition->getArgument(2));
        self::assertFalse($definition->isPublic());
    }

    public function testRegistersLocalFilesystemWithPath(): void
    {
        $container = new ContainerBuilder();
        $extension = new FilesystemExtension();

        $extension->load([
            [
                'storages' => [
                    'local_storage' => [
                        'type' => 'local',
                        'options' => [
                            'path' => '/tmp',
                        ],
                    ],
                ],
            ],
        ], $container);

        $definition = $container->getDefinition('kcs_filesystem.filesystem.local_storage');
        self::assertSame('/tmp', $definition->getArgument(0));
    }

    public function testRegistersAliasForArgument(): void
    {
        $container = new ContainerBuilder();
        $extension = new FilesystemExtension();

        $extension->load([
            [
                'storages' => [
                    'local_storage' => [
                        'type' => 'local',
                        'options' => [
                            'path' => '/tmp',
                        ],
                    ],
                ],
            ],
        ], $container);

        $found = false;
        foreach ($container->getAliases() as $id => $alias) {
            if (! str_starts_with($id, 'Kcs\\Filesystem\\Filesystem $')) {
                continue;
            }

            if ((string) $alias === 'kcs_filesystem.filesystem.local_storage') {
                $found = true;
                break;
            }
        }

        self::assertTrue($found);
    }

    public function testRegistersStreamWrapperRegisterer(): void
    {
        $container = new ContainerBuilder();
        $extension = new FilesystemExtension();

        $extension->load([
            [
                'storages' => [
                    'local_storage' => [
                        'type' => 'local',
                        'stream_wrapper_protocol' => 'localfs',
                        'options' => [
                            'path' => '/tmp',
                        ],
                    ],
                ],
            ],
        ], $container);

        $registerer = $container->getDefinition('kcs_filesystem.stream_wrapper_registerer');
        self::assertSame(StreamWrapperRegisterer::class, $registerer->getClass());
        self::assertTrue($registerer->isPublic());
        self::assertEquals([
            'localfs' => new Reference('kcs_filesystem.filesystem.local_storage'),
        ], $registerer->getArgument(0));
    }

    public function testStreamWrapperSkipsOnlyEmptyProtocols(): void
    {
        $container = new ContainerBuilder();
        $extension = new FilesystemExtension();

        $extension->load([
            [
                'storages' => [
                    'first' => [
                        'type' => 'local',
                        'options' => [
                            'path' => '/tmp',
                        ],
                    ],
                    'second' => [
                        'type' => 'local',
                        'stream_wrapper_protocol' => 'localfs2',
                        'options' => [
                            'path' => '/tmp',
                        ],
                    ],
                ],
            ],
        ], $container);

        $registerer = $container->getDefinition('kcs_filesystem.stream_wrapper_registerer');
        self::assertEquals([
            'localfs2' => new Reference('kcs_filesystem.filesystem.second'),
        ], $registerer->getArgument(0));
    }
}
