<?php

declare(strict_types=1);

namespace Tests\Symfony;

use Kcs\Filesystem\Symfony\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigurationTest extends TestCase
{
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
