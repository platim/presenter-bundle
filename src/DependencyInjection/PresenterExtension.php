<?php

declare(strict_types=1);

namespace Platim\PresenterBundle\DependencyInjection;

use Platim\Presenter\Contracts\DataProvider\DataProviderInterface;
use Platim\PresenterBundle\Attribute\AsPresenterHandler;
use Platim\PresenterBundle\Attribute\NameConverter;
use Platim\PresenterBundle\Attribute\Presenter;
use Platim\PresenterBundle\PresenterHandler\PresenterHandlerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class PresenterExtension extends ConfigurableExtension
{
    /**
     * Configures the passed container according to the merged configuration.
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(DataProviderInterface::class)
            ->addTag('presenter.data_provider');

        $container->registerAttributeForAutoconfiguration(
            AsPresenterHandler::class,
            static function (ChildDefinition $definition, AsPresenterHandler $attribute): void {
                $tagAttributes = get_object_vars($attribute);

                $definition->addTag('presenter.handler', $tagAttributes);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            NameConverter::class,
            static function (ChildDefinition $definition, Presenter $attribute): void {
                $tagAttributes = get_object_vars($attribute);

                $definition->addTag('presenter.name_converter', $tagAttributes);
            }
        );

        $container->registerForAutoconfiguration(PresenterHandlerInterface::class)
            ->addTag('presenter.handler');

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config')
        );
        $loader->load('services.yml');
    }
}
