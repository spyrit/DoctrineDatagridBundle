<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests;

use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagridFactory;
use Spyrit\Bundle\DoctrineDatagridBundle\DoctrineDatagridBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DoctrineDatagridBundleTest extends TestCase
{
    public function testFactoryServiceIsRegistered(): void
    {
        $bundle = new DoctrineDatagridBundle();
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = $bundle->getContainerExtension();

        $this->assertNotNull($extension);
        $this->assertSame('doctrine_datagrid', $extension->getAlias());

        $extension->load([], $container);

        $this->assertTrue($container->hasDefinition(DoctrineDatagridFactory::class));
        $this->assertTrue($container->getDefinition(DoctrineDatagridFactory::class)->isAutowired());
    }

    public function testTemplatesAreLocatedInTheBundlePath(): void
    {
        $this->assertFileExists((new DoctrineDatagridBundle())->getPath().'/Resources/views/Datagrid/pagination.html.twig');
    }
}
