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

final class FilterTest extends TestCase
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

    public function testResetRestoresDefaultSortAndResetsPage(): void
    {
        // First create a datagrid and set defaults
        $request = new Request();
        $store = [];
        $dg = $this->createDatagrid($request, $store);
        $dg->setDefaultSort(['foo' => 'asc']);

        // Change state: page and sort (override default 'asc' to 'desc')
        $dg->setCurrentPage(4);
        $this->assertSame(4, $dg->getCurrentPage());

        $requestSort = new Request([
            'action' => 'sort',
            'datagrid' => 'dg',
            'param1' => 'foo',
            'param2' => 'desc',
        ]);
        // Use same session store to persist state across instances
        $dg2 = $this->createDatagrid($requestSort, $store);
        // Re-apply defaults for this instance as well
        $dg2->setDefaultSort(['foo' => 'asc']);
        $dg2->updateSort();
        $this->assertSame('desc', $dg2->getSortedColumnOrder('foo'));

        // Reset should clear session and restore default sort + reset page
        $dg2->reset();

        $this->assertSame(1, $dg2->getCurrentPage());
        // After reset, the default sort ('asc') should be effective again
        $this->assertTrue($dg2->isSortedColumn('foo'));
        $this->assertSame('asc', $dg2->getSortedColumnOrder('foo'));
    }
}
