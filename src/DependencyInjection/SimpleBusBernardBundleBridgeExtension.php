<?php

namespace SimpleBus\BernardBundleBridge\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class SimpleBusBernardBundleBridgeExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    private $alias;

    public function __construct($alias)
    {
        $this->alias = $alias;
    }

    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new Configuration($this->alias);
    }

    public function prepend(ContainerBuilder $container)
    {
        $this->requireBundle('SimpleBusAsynchronousBundle', $container);
        $this->requireBundle('BernardBundle', $container);

        $container->prependExtensionConfig('simple_bus_asynchronous', [
            'commands' => [
                'publisher_service_id' => 'simple_bus.bernard_bundle_bridge.command_publisher',
            ],
            'events' => [
                'publisher_service_id' => 'simple_bus.bernard_bundle_bridge.event_publisher',
            ],
        ]);
    }

    protected function loadInternal(array $config, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        if ($container->getParameter('kernel.debug')) {
            $loader->load('debug.xml');
        }

        $this->configureQueueResolverForType($config['commands'], $container, 'commands');
        $this->configureQueueResolverForType($config['events'], $container, 'events');

        if (!empty($config['logger'])) {
            $container
                ->getDefinition('simple_bus.bernard_bundle_bridge.listener.logger')
                ->replaceArgument(0, new Reference($config['logger']))
                ->addTag('kernel.event_subscriber')
            ;
        }

        if (!empty($config['encryption']['enabled'])) {
            $loader->load('encryption.xml');

            $this->configureEncrypter($config['encryption'], $container);
        }

        $container->setParameter('simple_bus.bernard_bundle_bridge.encryption.enabled', $config['encryption']['enabled']);
    }

    private function configureQueueResolverForType(array $config, ContainerBuilder $container, $type)
    {
        $queueNameResolver = $config['queue_name_resolver'];

        if (in_array($queueNameResolver, ['fixed', 'class_based', 'mapped'])) {
            $definition = new DefinitionDecorator(sprintf('simple_bus.bernard_bundle_bridge.routing.%s_queue_name_resolver', $queueNameResolver));

            if ($queueNameResolver === 'fixed') {
                $definition->replaceArgument(0, $config['queue_name']);
            } elseif ($queueNameResolver === 'mapped') {
                $definition->replaceArgument(0, $config['queues_map']);
                $definition->replaceArgument(1, $config['queue_name']);
            }

            $container->setDefinition(
                sprintf('simple_bus.bernard_bundle_bridge.routing.%s_queue_name_resolver', $type),
                $definition
            );
        } else {
            $container->setAlias(
                sprintf('simple_bus.bernard_bundle_bridge.routing.%s_queue_name_resolver', $type),
                $queueNameResolver
            );
        }
    }

    private function configureEncrypter(array $config, ContainerBuilder $container)
    {
        if (in_array($config['encrypter'], ['nelmio', 'rot13'])) {
            $container
                ->getDefinition('simple_bus.bernard_bundle_bridge.encrypter.'.$config['encrypter'])
                ->setAbstract(false)
                ->setArguments([
                    $config['secret'],
                    $config['algorithm'],
                ])
            ;
            $container->setAlias('simple_bus.bernard_bundle_bridge.encrypter', 'simple_bus.bernard_bundle_bridge.encrypter.'.$config['encrypter']);
        } else {
            $container->setAlias('simple_bus.bernard_bundle_bridge.encrypter', $config['encrypter']);
        }
    }

    private function requireBundle($bundleName, ContainerBuilder $container)
    {
        if (!isset($container->getParameter('kernel.bundles')[$bundleName])) {
            throw new \LogicException(sprintf('You need to enable "%s" as well', $bundleName));
        }
    }
}
