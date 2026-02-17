<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagridFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;

final class DoctrineDatagridTest extends TestCase
{
    private function createDatagrid(Request $request, array &$sessionStore, array $params = [], ?RouterInterface $router = null): DoctrineDatagrid
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $router = $router ?? $this->createMock(RouterInterface::class);

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

    public function testGetSessionName(): void
    {
        $request = new Request();
        $store = [];
        $dg = $this->createDatagrid($request, $store);
        $this->assertSame('datagrid.dg', $dg->getSessionName());
    }

    public function testRoutingHelpersBuildExpectedParameters(): void
    {
        $request = new Request();
        $store = [];

        $router = $this->createMock(RouterInterface::class);
        // Assert parameters for pagination
        $router->expects($this->atLeastOnce())
            ->method('generate')
            ->with(
                $this->anything(),
                $this->callback(function (array $params) {
                    // Accept any route name, just validate mandatory params exist
                    return isset($params['action'], $params['datagrid']);
                })
            )
            ->willReturn('/ok');

        $dg = $this->createDatagrid($request, $store, [], $router);
        $this->assertSame('/ok', $dg->getPaginationPath('route_name', 2));
        $this->assertSame('/ok', $dg->getResetPath('route_name'));
        $this->assertSame('/ok', $dg->getSortPath('route_name', 'col', 'asc'));
        $this->assertSame('/ok', $dg->getRemoveSortPath('route_name', 'col'));
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
        // Using public API: set session "sort" by calling setDefaultSort then updateSort twice
        $dg->setDefaultSort(['foo' => 'asc', 'bar' => 'desc']);

        $this->assertSame(2, $dg->getSortCount());
        $dg->removeSort();
        $this->assertSame(1, $dg->getSortCount());
        $this->assertFalse($dg->isSortedColumn('bar'));
    }

    public function testPaginationStateUsingSession(): void
    {
        $request = new Request();
        $store = [];
        $dg = $this->createDatagrid($request, $store);

        $this->assertSame(1, $dg->getCurrentPage());
        $dg->setCurrentPage(3);
        $this->assertSame(3, $dg->getCurrentPage());

        $dg->setDefaultMaxPerPage(30);
        $this->assertSame(30, $dg->getMaxPerPage());
        $dg->setMaxPerPage(50);
        $this->assertSame(50, $dg->getMaxPerPage());
        $this->assertSame([15, 30, 50], $dg->getAvailableMaxPerPage());
    }

    public function testDynamicColumnsAvailability(): void
    {
        $request = new Request();
        $store = [];

        // Anonymous subclass to provide default/appendable columns
        $dg = new class(...$this->extractCtorArgs($request, $store)) extends DoctrineDatagrid {
            public function getDefaultColumns(): array { return ['id' => 'ID', 'name' => 'Name']; }
            public function getAppendableColumns(): array { return ['email' => 'Email', 'country' => 'Country']; }
        };

        // When nothing stored in session, current columns are defaults, so available appendables exclude defaults
        $available = $dg->getAvailableAppendableColumns();
        $this->assertArrayHasKey('email', $available);
        $this->assertArrayHasKey('country', $available);
        $this->assertArrayNotHasKey('id', $available);
        $this->assertArrayNotHasKey('name', $available);
    }

    public function testFactoryCreatesDatagridWithGivenName(): void
    {
        $request = new Request();
        $store = [];

        [$em, $requestStack, $formFactory, $router] = $this->extractRawServices($request, $store);
        $factory = new DoctrineDatagridFactory($em, $requestStack, $formFactory, $router);
        $dg = $factory->create('custom');

        $this->assertInstanceOf(DoctrineDatagrid::class, $dg);
        $this->assertSame('datagrid.custom', $dg->getSessionName());
    }

    /**
     * Helper to reuse the same service mocks as createDatagrid but return raw args for subclassing.
     *
     * @return array{0: EntityManagerInterface, 1: RequestStack, 2: FormFactoryInterface, 3: RouterInterface}
     */
    private function extractRawServices(Request $request, array &$sessionStore): array
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $router = $this->createMock(RouterInterface::class);

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

        $request->setSession($session);

        $requestStack = $this->getMockBuilder(RequestStack::class)
            ->onlyMethods(['getCurrentRequest', 'getSession'])
            ->getMock();
        $requestStack->method('getCurrentRequest')->willReturn($request);
        $requestStack->method('getSession')->willReturn($session);

        return [$em, $requestStack, $formFactory, $router];
    }

    /**
     * Same as extractRawServices but returns spreadable constructor args for DoctrineDatagrid.
     *
     * @return array{0: EntityManagerInterface, 1: RequestStack, 2: FormFactoryInterface, 3: RouterInterface, 4: string, 5: array}
     */
    private function extractCtorArgs(Request $request, array &$sessionStore): array
    {
        [$em, $requestStack, $formFactory, $router] = $this->extractRawServices($request, $sessionStore);
        return [$em, $requestStack, $formFactory, $router, 'dg', []];
    }
}
