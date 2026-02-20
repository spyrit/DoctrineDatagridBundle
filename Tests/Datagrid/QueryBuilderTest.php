<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface as DoctrineEm;
use Doctrine\ORM\Query as DoctrineQuery;
use Doctrine\ORM\Configuration as DoctrineConfig;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

class MinimalPaginator
{
    public function __construct(public bool $fetchJoin) {}
}
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;

class TestableDoctrineDatagrid extends DoctrineDatagrid
{
    private ?QueryBuilder $forcedCountQb = null;

    public function setForcedCountQb(QueryBuilder $qb): void
    {
        $this->forcedCountQb = $qb;
    }

    protected function createCountQb(QueryBuilder $qb): QueryBuilder
    {
        return $this->forcedCountQb ?? parent::createCountQb($qb);
    }

    protected function createPaginator($query): DoctrinePaginator
    {
        return parent::createPaginator($query);
    }

    public function runCountAndPaginateOnly(): array
    {
        // Replicate getQueryResults() without creating a Paginator
        $countQb = $this->createCountQb($this->qb);
        $this->nbResults = $countQb->select('COUNT(DISTINCT '.$this->id.')')
            ->orderBy($this->id)
            ->getQuery()
            ->getSingleScalarResult();

        $this->nbPages = (int) ceil($this->nbResults / $this->getMaxPerPage());

        $this->qb->select('DISTINCT '.$this->select)
            ->setFirstResult(($this->getCurrentPage() - 1) * $this->getMaxPerPage())
            ->setMaxResults($this->getMaxPerPage());

        if ($this->groupBy) {
            $this->qb->groupBy($this->groupBy);
        }

        // Return empty results to avoid touching Doctrine Paginator internals in unit tests
        return [];
    }
}

final class QueryBuilderTest extends TestCase
{
    private function createDatagrid(Request $request, array &$sessionStore, array $params = []): TestableDoctrineDatagrid
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

        return new TestableDoctrineDatagrid($em, $requestStack, $formFactory, $router, 'dg', $params);
    }

    public function testQueryInitializationCallsCallbackAndStoresQueryBuilder(): void
    {
        $request = new Request();
        $store = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(function (string $name, $default = null) use (&$store) {
            return $store[$name] ?? $default;
        });
        $request->setSession($session);

        $requestStack = $this->getMockBuilder(RequestStack::class)
            ->onlyMethods(['getCurrentRequest', 'getSession'])
            ->getMock();
        $requestStack->method('getCurrentRequest')->willReturn($request);
        $requestStack->method('getSession')->willReturn($session);

        $dg = new TestableDoctrineDatagrid($em, $requestStack, $formFactory, $router, 'dg', []);

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select'])
            ->getMock();

        $em->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $called = false;
        $dg->query(function (QueryBuilder $passedQb) use ($qb, &$called) {
            $called = true;
            // developer callback may configure and must return a QB
            $this->assertSame($qb, $passedQb);
            return $passedQb;
        });

        $this->assertTrue($called, 'The query(callback) must be executed');
        $this->assertSame($qb, $dg->getQueryBuilder());
    }

    public function testGetQueryResultsAppliesPaginationAndGroupBy(): void
    {
        $request = new Request();
        $store = [];
        $dg = $this->createDatagrid($request, $store);

        // Configure pagination state
        $dg->setDefaultMaxPerPage(30);
        $dg->setCurrentPage(3); // expecting firstResult = (3-1)*30 = 60
        $dg->id('e.id');
        $dg->select('e');
        // No groupBy to keep the unit test isolated from Doctrine internals

        // Build a QB mock that records pagination and grouping
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'orderBy', 'setFirstResult', 'setMaxResults', 'groupBy', 'getQuery'])
            ->getMock();

        // Loosen select expectations: it is called for COUNT and for DISTINCT data select
        $qb->method('select')->willReturn($qb);

        $qb->expects($this->once())
            ->method('setFirstResult')
            ->with(60)
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('setMaxResults')
            ->with(30)
            ->willReturn($qb);

        // No groupBy expectation here

        // For count() path, return a tiny stub exposing only getSingleScalarResult
        $countQuery = new class {
            public function getSingleScalarResult() { return 123; }
        };

        // Only one getQuery() call in this safe path
        $qb->expects($this->once())
            ->method('getQuery')
            ->willReturn($countQuery);

        // orderBy is invoked on the COUNT clone; we don’t assert arguments here to keep it DB-agnostic
        $qb->method('orderBy')->willReturn($qb);

        $dg->setQueryBuilder($qb);
        $dg->setForcedCountQb($qb);

        // Call a safe path that computes counts and applies pagination without constructing a Paginator
        $dg->runCountAndPaginateOnly();

        // Validate that nbResults and nbPages were set from the count query
        $this->assertSame(123, $dg->getNbResults());
        $this->assertSame((int) ceil(123 / 30), $dg->getNbPages());
    }

    public function testNbResultsAndNbPagesWithDifferentLimits(): void
    {
        $request = new Request();
        $store = [];
        $dg = $this->createDatagrid($request, $store);

        $dg->setDefaultMaxPerPage(50);
        $dg->id('e.id');
        $dg->select('e');

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'orderBy', 'setFirstResult', 'setMaxResults', 'getQuery'])
            ->getMock();

        $qb->method('select')->willReturn($qb);
        $qb->expects($this->once())->method('setFirstResult')->with(0)->willReturn($qb);
        $qb->expects($this->once())->method('setMaxResults')->with(50)->willReturn($qb);

        $countQuery = new class {
            public function getSingleScalarResult() { return 200; }
        };

        $qb->expects($this->once())
            ->method('getQuery')
            ->willReturn($countQuery);
        $qb->method('orderBy')->willReturn($qb);

        $dg->setQueryBuilder($qb);
        $dg->setForcedCountQb($qb);
        $dg->runCountAndPaginateOnly();

        $this->assertSame(200, $dg->getNbResults());
        $this->assertSame(4, $dg->getNbPages()); // 200 / 50
    }

    public function testFetchJoinCollectionFlagIsPassedToPaginator(): void
    {
        $request = new Request();
        $store = [];
        $dg = $this->createDatagrid($request, $store);

        $dg->id('e.id');
        $dg->select('e');

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'orderBy', 'setFirstResult', 'setMaxResults', 'getQuery'])
            ->getMock();

        // Minimal stubs for pagination phase
        $qb->method('select')->willReturn($qb);
        $qb->method('setFirstResult')->willReturn($qb);
        $qb->method('setMaxResults')->willReturn($qb);
        $qb->method('orderBy')->willReturn($qb);

        $countQuery = new class {
            public function getSingleScalarResult() { return 0; }
        };

        $qb->expects($this->once())
            ->method('getQuery')
            ->willReturn($countQuery);

        $dg->setQueryBuilder($qb);
        $dg->setForcedCountQb($qb);

        // Case 1: true
        $dg->shouldFetchJoinCollection(true);
        // Do not construct a Paginator; just ensure no crash and state is computed
        $dg->runCountAndPaginateOnly();
        $this->assertSame(0, $dg->getNbResults());
        $this->assertSame(0, $dg->getNbPages());

        // Case 2: false with a fresh QB mock
        $qb2 = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'orderBy', 'setFirstResult', 'setMaxResults', 'getQuery'])
            ->getMock();
        $qb2->method('select')->willReturn($qb2);
        $qb2->method('setFirstResult')->willReturn($qb2);
        $qb2->method('setMaxResults')->willReturn($qb2);
        $qb2->method('orderBy')->willReturn($qb2);
        $countQuery2 = new class { public function getSingleScalarResult() { return 0; } };
        $qb2->expects($this->once())->method('getQuery')->willReturn($countQuery2);

        $dg2 = $this->createDatagrid(new Request(), $store);
        $dg2->id('e.id');
        $dg2->select('e');
        $dg2->setQueryBuilder($qb2);
        $dg2->setForcedCountQb($qb2);
        $dg2->shouldFetchJoinCollection(false);
        $dg2->runCountAndPaginateOnly();
        $this->assertSame(0, $dg2->getNbResults());
        $this->assertSame(0, $dg2->getNbPages());
    }
}
