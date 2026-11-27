<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Bridge\Doctrine\Helper\QueryBuilderHelper;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\OrmTestTrait;

final class QueryBuilderHelperTest extends TestCase
{
    use OrmTestTrait;

    public function testJoinIsOnlyAddedOnce(): void
    {
        $qb = $this->createEntityManager()->createQueryBuilder()->select('b')->from(Book::class, 'b');

        QueryBuilderHelper::addLeftJoin('b.author', 'a', $qb);
        QueryBuilderHelper::addLeftJoin('b.author', 'a', $qb);

        $this->assertSame(
            'SELECT b FROM '.Book::class.' b LEFT JOIN b.author a',
            $qb->getDQL(),
        );
    }
}
