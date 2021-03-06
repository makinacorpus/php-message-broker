<?php

declare(strict_types=1);

namespace Goat\Bridge\Symfony\Tests\DependencyInjection;

use Goat\Query\Symfony\GoatQueryBundle;
use Goat\Runner\Runner;
use MakinaCorpus\MessageBroker\MessageConsumerFactory;
use MakinaCorpus\MessageBroker\MessagePublisher;
use MakinaCorpus\MessageBroker\Bridge\Symfony\DependencyInjection\MessageBrokerExtension;
use MakinaCorpus\Normalization\NameMap;
use MakinaCorpus\Normalization\Serializer;
use MakinaCorpus\Normalization\NameMap\DefaultNameMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class KernelConfigurationTest extends TestCase
{
    private function getContainer()
    {
        // Code inspired by the SncRedisBundle, all credits to its authors.
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.debug'=> false,
            'kernel.bundles' => [
                GoatQueryBundle::class => ['all' => true],
            ],
            'kernel.cache_dir' => \sys_get_temp_dir(),
            'kernel.environment' => 'test',
            'kernel.root_dir' => \dirname(__DIR__),
        ]));

        // OK, we will need this.
        $runnerDefinition = new Definition();
        $runnerDefinition->setClass(Runner::class);
        $runnerDefinition->setSynthetic(true);
        $container->setDefinition('goat.runner.default', $runnerDefinition);
        $container->setAlias(Runner::class, 'goat.runner.default');

        $nameMapDefinition = new Definition();
        $nameMapDefinition->setClass(DefaultNameMap::class);
        $container->setDefinition(NameMap::class, $nameMapDefinition);
        $container->setAlias('normalization.name_map', NameMap::class);

        $normalizationSerializerDefinition = new Definition();
        $normalizationSerializerDefinition->setClass(Serializer::class);
        $container->setDefinition(Serializer::class, $normalizationSerializerDefinition);
        $container->setAlias('normalization.serializer', Serializer::class);

        return $container;
    }

    private function getMinimalConfig(): array
    {
        return [];
    }

    /**
     * Test default config for resulting tagged services
     */
    public function testTaggedServicesConfigLoad()
    {
        $extension = new MessageBrokerExtension();
        $config = $this->getMinimalConfig();
        $extension->load([$config], $container = $this->getContainer());

        self::assertTrue($container->hasAlias(MessageConsumerFactory::class));
        self::assertTrue($container->hasAlias('message_broker.consumer_factory'));
        self::assertTrue($container->hasDefinition('message_broker.message_consumer_factory.goat_query'));

        self::assertTrue($container->hasAlias(MessagePublisher::class));
        self::assertTrue($container->hasAlias('message_broker.publisher'));
        self::assertTrue($container->hasDefinition('message_broker.message_publisher.goat_query'));

        $container->compile();
    }
}
