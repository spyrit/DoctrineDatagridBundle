<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;

final class SortTest extends TestCase
{
    private function createDatagrid(Request $request, array &$sessionStore, array $params = []): DoctrineDatagrid
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // Session mock with in-memory storage
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(function (string $name, $default = null) use (&$sessionStore) {
            return $sessionStore[$name] ?? $default;
        });
        $session->method('set')->willReturnCallback(function (string $name, $value) use (&$sessionStore) {
            $sessionStore[$name] = $value;
        });
        $session->method('remove')->willReturnCallback(function (string $name) use (&$sessionStore) {
            unset($sessionStore[$name]);
        });

        // Attach session to request
        $request->setSession($session);

        // RequestStack mock that provides current request and direct session access
        $requestStack = $this->getMockBuilder(RequestStack::class)
            ->onlyMethods(['getCurrentRequest', 'getSession'])
            ->getMock();
        $requestStack->method('getCurrentRequest')->willReturn($request);
        $requestStack->method('getSession')->willReturn($session);

        return new DoctrineDatagrid($em, $requestStack, $formFactory, $router, 'dg', $params);
    }

    public function testDefaultSortStateFromConfiguration(): void
    {
        $request = new Request();
        $store = [];
        $dg = $this->createDatagrid($request, $store);

        $dg->setDefaultSort(['foo' => 'asc']);

        $this->assertTrue($dg->isSortedColumn('foo'));
        $this->assertSame('asc', $dg->getSortedColumnOrder('foo'));
        $this->assertSame(0, $dg->getSortedColumnPriority('foo'));
        $this->assertSame(1, $dg->getSortCount());
    }

    public function testUpdateSortWithWhitelist(): void
    {
        // Prepare a sort request for column "bar" desc
        $request = new Request([
            'action' => 'sort',
            'datagrid' => 'dg',
            'param1' => 'bar',
            'param2' => 'desc',
        ]);
        $store = [];
        $dg = $this->createDatagrid($request, $store, ['multi_sort' => true]);
        $dg->setDefaultSort(['foo' => 'asc']);
        $dg->setAllowedSorts(['bar', 'baz']);

        $dg->updateSort();

        $this->assertTrue($dg->isSortedColumn('bar'));
        $this->assertSame('desc', $dg->getSortedColumnOrder('bar'));
        // Multi sort keeps previous defaults too
        $this->assertSame(2, $dg->getSortCount());
    }

    public function testRemoveSort(): void
    {
        // Start with two sorts stored in session
        $request = new Request([
            'action' => 'remove_sort',
            'datagrid' => 'dg',
            'param1' => 'bar',
        ]);
        $store = [];
        $dg = $this->createDatagrid($request, $store);
        // prime session with sorts
        $dg->setDefaultSort(['foo' => 'asc', 'bar' => 'desc']);

        $this->assertSame(2, $dg->getSortCount());
        $dg->removeSort();
        $this->assertSame(1, $dg->getSortCount());
        $this->assertFalse($dg->isSortedColumn('bar'));
    }
}
