<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\DatagridTestTrait;
use Symfony\Component\HttpFoundation\Request;

final class SortTest extends TestCase
{
    use DatagridTestTrait;

    public function testDefaultSortStateFromConfiguration(): void
    {
        $dg = $this->createDatagrid();
        $dg->setDefaultSort(['foo' => 'ASC']);

        $this->assertTrue($dg->isSortedColumn('foo'));
        $this->assertSame('ASC', $dg->getSortedColumnOrder('foo'));
        $this->assertSame(0, $dg->getSortedColumnPriority('foo'));
        $this->assertSame(1, $dg->getSortCount());
    }

    public function testUpdateSortWithWhitelist(): void
    {
        $dg = $this->createDatagrid($this->sortRequest('bar', 'DESC'), ['multi_sort' => true]);
        $dg->setDefaultSort(['foo' => 'ASC']);
        $dg->setAllowedSorts(['bar', 'baz']);

        $dg->updateSort();

        $this->assertSame('DESC', $dg->getSortedColumnOrder('bar'));
        // Multi sort keeps the previous sorts
        $this->assertSame(2, $dg->getSortCount());
    }

    public function testUpdateSortIgnoresColumnsOutsideTheWhitelist(): void
    {
        $dg = $this->createDatagrid($this->sortRequest('password', 'DESC'));
        $dg->setAllowedSorts(['bar']);

        $dg->updateSort();

        $this->assertFalse($dg->isSortedColumn('password'));
        $this->assertSame(0, $dg->getSortCount());
    }

    public function testSingleSortReplacesPreviousSorts(): void
    {
        $dg = $this->createDatagrid($this->sortRequest('bar', 'ASC'), ['multi_sort' => false]);
        $dg->setDefaultSort(['foo' => 'ASC']);

        $dg->updateSort();

        $this->assertFalse($dg->isSortedColumn('foo'));
        $this->assertSame('ASC', $dg->getSortedColumnOrder('bar'));
    }

    #[IgnoreDeprecations]
    public function testLowercaseSortDirectionIsDeprecatedAndNormalized(): void
    {
        $this->expectUserDeprecationMessageMatches('/Providing lowercase sort order is deprecated/');

        $dg = $this->createDatagrid($this->sortRequest('bar', 'desc'));
        $dg->updateSort();

        $this->assertSame('DESC', $dg->getSortedColumnOrder('bar'));
    }

    public function testInvalidSortDirectionIsRejected(): void
    {
        $dg = $this->createDatagrid($this->sortRequest('bar', 'SIDEWAYS'));

        $this->expectException(\InvalidArgumentException::class);
        $dg->updateSort();
    }

    public function testRemoveSort(): void
    {
        $dg = $this->createDatagrid(new Request(['action' => 'remove-sort', 'datagrid' => 'dg', 'param1' => 'bar']));
        $dg->setDefaultSort(['foo' => 'ASC', 'bar' => 'DESC']);

        $this->assertSame(2, $dg->getSortCount());
        $dg->removeSort();
        $this->assertSame(1, $dg->getSortCount());
        $this->assertFalse($dg->isSortedColumn('bar'));
    }

    private function sortRequest(string $column, string $direction): Request
    {
        return new Request(['action' => 'sort', 'datagrid' => 'dg', 'param1' => $column, 'param2' => $direction]);
    }
}
