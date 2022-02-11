<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Bridge\Symfony;

use MakinaCorpus\MessageBroker\Bridge\Symfony\DependencyInjection\MessageBrokerExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @codeCoverageIgnore
 */
final class MessageBrokerBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MessageBrokerExtension();
    }
}
