<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\DatagridTestTrait;
use Symfony\Component\HttpFoundation\Request;

final class FilterTest extends TestCase
{
    use DatagridTestTrait;

    public function testResetRestoresDefaultSortAndResetsPage(): void
    {
        $dg = $this->createDatagrid();
        $dg->setDefaultSort(['foo' => 'ASC']);
        $dg->setCurrentPage(4);
        $this->assertSame(4, $dg->getCurrentPage());

        // Same session, new request
        $dg2 = $this->createDatagrid(new Request(['action' => 'sort', 'datagrid' => 'dg', 'param1' => 'foo', 'param2' => 'DESC']));
        $dg2->setDefaultSort(['foo' => 'ASC']);
        $dg2->updateSort();
        $this->assertSame('DESC', $dg2->getSortedColumnOrder('foo'));

        $dg2->reset();

        $this->assertSame(1, $dg2->getCurrentPage());
        $this->assertSame('ASC', $dg2->getSortedColumnOrder('foo'));
    }

    public function testIsFiltered(): void
    {
        $dg = $this->createDatagrid();
        $this->assertFalse($dg->isFiltered());

        $dg->setFilterValue('genre', 'essay');
        $this->assertTrue($dg->isFiltered());

        $dg->resetFilters();
        $this->assertFalse($dg->isFiltered());
    }
}
