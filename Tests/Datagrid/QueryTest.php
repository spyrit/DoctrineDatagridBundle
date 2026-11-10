<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\DatagridTestTrait;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\OrmTestTrait;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;

final class QueryTest extends TestCase
{
    use DatagridTestTrait;
    use OrmTestTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->createEntityManager();
        $this->loadBooks($this->em);
    }

    public function testQueryCallbackReceivesAFreshQueryBuilder(): void
    {
        $received = null;
        $dg = $this->createDatagrid(em: $this->em)->query(function (QueryBuilder $qb) use (&$received): QueryBuilder {
            $received = $qb;

            return $qb->from(Book::class, 'b');
        });

        $this->assertSame($received, $dg->getQueryBuilder());
    }

    public function testExecutePaginatesResults(): void
    {
        $dg = $this->bookDatagrid();
        $dg->setDefaultMaxPerPage(3);
        $dg->setCurrentPage(3);
        $dg->execute();

        $this->assertSame(7, $dg->getNbResults());
        $this->assertSame(3, $dg->getNbPages());
        // 1984, A Nice Cup of Tea, Foundation | I, Robot, Politics…, The End of Eternity | Why I Write
        $this->assertSame(['Why I Write'], $this->titles($dg->getResults()));
    }

    public function testPageActionUpdatesCurrentPage(): void
    {
        $dg = $this->bookDatagrid(new Request(['action' => 'page', 'datagrid' => 'dg', 'param1' => '2']));
        $dg->setDefaultMaxPerPage(5);
        $dg->execute();

        $this->assertSame(2, $dg->getCurrentPage());
        $this->assertCount(2, iterator_to_array($dg->getResults()));
    }

    public function testLimitActionOnlyAcceptsAvailableValues(): void
    {
        $accepted = $this->bookDatagrid(new Request(['action' => 'limit', 'datagrid' => 'dg', 'param1' => '15']))->execute();
        $this->assertSame(15, $accepted->getMaxPerPage());
        $this->assertSame(1, $accepted->getNbPages());

        $rejected = $this->bookDatagrid(new Request(['action' => 'limit', 'datagrid' => 'dg', 'param1' => '100000']))->execute();
        $this->assertSame(15, $rejected->getMaxPerPage(), 'The limit stored in session is kept');
    }

    public function testRequestedDatagridWithoutActionIsHandled(): void
    {
        $dg = $this->bookDatagrid(new Request(['datagrid' => 'dg']))->execute();

        $this->assertSame(7, $dg->getNbResults());
    }

    public function testSortIsAppliedToTheQuery(): void
    {
        $dg = $this->bookDatagrid(new Request(['action' => 'sort', 'datagrid' => 'dg', 'param1' => 'b.title', 'param2' => 'DESC']));
        $dg->setDefaultMaxPerPage(2);
        $dg->execute();

        $this->assertSame(['Why I Write', 'The End of Eternity'], $this->titles($dg->getResults()));
    }

    public function testPostedFilterIsAppliedAndStoredInSession(): void
    {
        $request = Request::create('/', 'POST', ['filter_dg' => ['genre' => 'essay']]);
        $dg = $this->bookDatagrid($request);
        $dg->setCurrentPage(2);
        $dg->execute();

        $this->assertSame(3, $dg->getNbResults());
        $this->assertSame(1, $dg->getCurrentPage());
        $this->assertTrue($dg->isFiltered());
        $this->assertNotNull($dg->getFilterFormView());

        // Next GET request: the filter is restored from the session
        $this->assertSame(3, $this->bookDatagrid()->execute()->getNbResults());
    }

    public function testGetAllResultsIgnoresPagination(): void
    {
        $request = Request::create('/', 'POST', ['filter_dg' => ['genre' => 'novel']]);
        $dg = $this->bookDatagrid($request);
        $dg->setDefaultMaxPerPage(2);
        $dg->execute();

        $this->assertCount(2, iterator_to_array($dg->getResults()));
        $this->assertCount(4, $dg->getAllResults());
    }

    public function testGetAllResultsWithoutExecuteAppliesFilters(): void
    {
        // Filter submitted on a previous request, then e.g. an export action calling getAllResults() directly
        $this->bookDatagrid(Request::create('/', 'POST', ['filter_dg' => ['genre' => 'essay']]))->execute();

        $this->assertCount(3, $this->bookDatagrid()->getAllResults());
    }

    public function testGroupBy(): void
    {
        $dg = $this->createDatagrid(em: $this->em)
            ->query(fn (QueryBuilder $qb): QueryBuilder => $qb->from(Book::class, 'b'))
            ->select(['b.genre', 'COUNT(b.id) AS total'])
            ->id('b.genre')
            ->groupBy('b.genre')
            ->shouldFetchJoinCollection(false)
            ->setDefaultSort(['b.genre' => 'ASC'])
            ->execute();

        $this->assertSame(2, $dg->getNbResults());
        $this->assertEquals(
            [['genre' => 'essay', 'total' => 3], ['genre' => 'novel', 'total' => 4]],
            iterator_to_array($dg->getResults()),
        );
    }

    public function testUnorderedQueryBuilderIsAClone(): void
    {
        $dg = $this->bookDatagrid();
        $qb = $dg->getQueryBuilder()->orderBy('b.title');

        $unordered = $dg->getUnorderedQueryBuilder($qb);

        $this->assertNotSame($qb, $unordered);
        $this->assertSame([], $unordered->getDQLPart('orderBy'));
        $this->assertNotEmpty($qb->getDQLPart('orderBy'));
    }

    private function bookDatagrid(?Request $request = null): DoctrineDatagrid
    {
        return $this->createDatagrid($request, em: $this->em)
            ->query(fn (QueryBuilder $qb): QueryBuilder => $qb->from(Book::class, 'b'))
            ->select('b')
            ->id('b.id')
            ->setDefaultSort(['b.title' => 'ASC'])
            ->filter('genre', TextType::class, [], fn (string $genre, QueryBuilder $qb): QueryBuilder => $qb
                ->andWhere('b.genre = :genre')
                ->setParameter('genre', $genre))
        ;
    }

    /**
     * @param iterable<Book> $books
     *
     * @return string[]
     */
    private function titles(iterable $books): array
    {
        $titles = [];
        foreach ($books as $book) {
            $titles[] = $book->title;
        }

        return $titles;
    }
}
