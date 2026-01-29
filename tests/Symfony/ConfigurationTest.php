<?php

declare(strict_types=1);

namespace Tests\Symfony;

use Kcs\Filesystem\Symfony\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigurationTest extends TestCase
{
    public function testS3BucketIsRequired(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"bucket" options is required if filesystem type is s3');

        $processor->processConfiguration($configuration, [
            [
                'storages' => [
                    's3_storage' => [
                        'type' => 's3',
                        'options' => [
                            'region' => 'eu-west-1',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testLocalPathIsRequired(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"path" options is required if filesystem type is local');

        $processor->processConfiguration($configuration, [
            [
                'storages' => [
                    'local_storage' => [
                        'type' => 'local',
                        'options' => [],
                    ],
                ],
            ],
        ]);
    }

    public function testGcsBucketIsRequired(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"bucket" options is required if filesystem type is gcs');

        $processor->processConfiguration($configuration, [
            [
                'storages' => [
                    'gcs_storage' => [
                        'type' => 'gcs',
                        'options' => [
                            'project_id' => 'my-project',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
