<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

final class MessageBrokerConfiguration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('message_broker');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                // @todo Nothing here for now.
            ->end()
        ;

        return $treeBuilder;
    }
}
