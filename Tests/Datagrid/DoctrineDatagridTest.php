<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagridFactory;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\DatagridTestTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

final class DoctrineDatagridTest extends TestCase
{
    use DatagridTestTrait;

    public function testGetSessionName(): void
    {
        $this->assertSame('datagrid.dg', $this->createDatagrid()->getSessionName());
    }

    public function testConstructionDoesNotRequireASession(): void
    {
        // e.g. a datagrid built from a console command, before any request is handled
        $dg = new DoctrineDatagrid(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $this->createFormFactory(),
            $this->createStub(RouterInterface::class),
            'dg',
        );

        $this->assertSame('datagrid.dg', $dg->getSessionName());
    }

    public function testRoutingHelpersBuildExpectedParameters(): void
    {
        $generated = [];
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(function (string $route, array $params) use (&$generated): string {
            $generated[] = $params;

            return '/'.$route;
        });

        $dg = $this->createDatagrid(router: $router);

        $this->assertSame('/books', $dg->getPaginationPath('books', 2, ['foo' => 'bar']));
        $dg->getResetPath('books');
        $dg->getSortPath('books', 'b.title', 'DESC');
        $dg->getRemoveSortPath('books', 'b.title');
        $dg->getNewColumnPath('books', 'email', 'name');
        $dg->getRemoveColumnPath('books', 'email');
        $dg->getMaxPerPagePath('books', 50);

        $this->assertSame([
            ['action' => 'page', 'datagrid' => 'dg', 'param1' => 2, 'foo' => 'bar'],
            ['action' => 'reset', 'datagrid' => 'dg'],
            ['action' => 'sort', 'datagrid' => 'dg', 'param1' => 'b.title', 'param2' => 'DESC'],
            ['action' => 'remove-sort', 'datagrid' => 'dg', 'param1' => 'b.title'],
            ['action' => 'add-column', 'datagrid' => 'dg', 'param1' => 'email', 'param2' => 'name'],
            ['action' => 'remove-column', 'datagrid' => 'dg', 'param1' => 'email'],
            ['action' => 'limit', 'datagrid' => 'dg', 'param1' => 50],
        ], $generated);
    }

    public function testDynamicColumnsAvailability(): void
    {
        $dg = $this->createDatagrid(class: ColumnsDatagrid::class);

        $this->assertSame(['id' => 'ID', 'name' => 'Name'], $dg->getColumns());
        $this->assertSame(['email' => 'Email', 'country' => 'Country'], $dg->getAvailableAppendableColumns());
    }

    public function testFactoryCreatesDatagridWithGivenName(): void
    {
        $factory = new DoctrineDatagridFactory(
            $this->createStub(EntityManagerInterface::class),
            $this->createRequestStack(new Request()),
            $this->createFormFactory(),
            $this->createStub(RouterInterface::class),
        );

        $this->assertSame('datagrid.custom', $factory->create('custom')->getSessionName());
    }

    public function testBatchHelpers(): void
    {
        $request = new Request(cookies: ['dg_batch' => json_encode(['type' => 'include', 'checked' => [1, 3]])]);
        $dg = $this->createDatagrid($request);

        $this->assertTrue($dg->isBatchChecked(1));
        $this->assertFalse($dg->isBatchChecked(2));
        $this->assertSame(['type' => 'include', 'checked' => [1, 3]], $dg->getBatchData());
    }

    public function testBatchHelpersWithoutCookie(): void
    {
        $dg = $this->createDatagrid();

        $this->assertSame([], $dg->getBatchData());
        $this->assertFalse($dg->isBatchChecked(1));
        $this->assertFalse($dg->hasCheckedBatch());
    }
}

class ColumnsDatagrid extends DoctrineDatagrid
{
    public function getDefaultColumns(): array
    {
        return ['id' => 'ID', 'name' => 'Name'];
    }

    public function getAppendableColumns(): array
    {
        return ['email' => 'Email', 'country' => 'Country'];
    }
}
