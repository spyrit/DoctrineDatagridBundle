<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle;

use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagridFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class DoctrineDatagridBundle extends AbstractBundle
{
    /**
     * The bundle sources live at the package root (no "src/" directory).
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(DoctrineDatagridFactory::class)
                ->autowire()
        ;
    }
}
