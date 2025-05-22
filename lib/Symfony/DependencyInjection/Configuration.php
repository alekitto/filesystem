<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('filesystem');
        $rootNode = $treeBuilder->getRootNode();

        /** @phpstan-ignore-next-line */
        $rootNode
            ->fixXmlConfig('storage')
            ->children()
                ->arrayNode('storages')
                    ->useAttributeAsKey('name')
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->validate()
                            ->ifTrue(static function (array $value): bool {
                                return ($value['type'] ?? null) === 's3'
                                    && ($value['options']['bucket'] ?? null) === null;
                            })
                            ->thenInvalid('"bucket" options is required if filesystem type is s3')
                        ->end()
                        ->validate()
                            ->ifTrue(static function (array $value): bool {
                                return ($value['type'] ?? null) === 'local'
                                    && ($value['options']['path'] ?? null) === null;
                            })
                            ->thenInvalid('"path" options is required if filesystem type is local')
                        ->end()
                        ->performNoDeepMerging()
                        ->children()
                            ->scalarNode('type')->isRequired()->end()
                            ->scalarNode('stream_wrapper_protocol')->end()
                            ->arrayNode('options')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    // Local
                                    ->scalarNode('path')->end()
                                    ->scalarNode('file_permissions')->end()
                                    ->scalarNode('dir_permissions')->end()

                                    // S3
                                    ->scalarNode('prefix')->end()
                                    ->scalarNode('bucket')->end()
                                    ->scalarNode('region')->end()
                                    ->scalarNode('access_key')->end()
                                    ->scalarNode('secret_key')->end()
                                    ->scalarNode('endpoint')->end()
                                    ->scalarNode('client')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
