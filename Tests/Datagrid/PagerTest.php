<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\DatagridTestTrait;

final class PagerTest extends TestCase
{
    use DatagridTestTrait;

    public function testPaginationStateUsingSession(): void
    {
        $dg = $this->createDatagrid();

        $this->assertSame(1, $dg->getCurrentPage());
        $dg->setCurrentPage(3);
        $this->assertSame(3, $dg->getCurrentPage());

        $dg->setDefaultMaxPerPage(30);
        $this->assertSame(30, $dg->getMaxPerPage());
        $dg->setMaxPerPage(50);
        $this->assertSame(50, $dg->getMaxPerPage());
        $this->assertSame([15, 30, 50], $dg->getAvailableMaxPerPage());
    }
}
