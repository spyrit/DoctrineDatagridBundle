<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Export;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\DatagridTestTrait;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export\BookCsvExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export\BookXlsExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export\LegacyBookCsvExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\OrmTestTrait;

final class ExportTest extends TestCase
{
    use DatagridTestTrait;
    use OrmTestTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->createEntityManager();
        $this->loadBooks($this->em);
    }

    public function testCsvExport(): void
    {
        $response = $this->datagrid()->export('csv', ['csvWriter' => ['encoding' => 'UTF-8', 'eol' => "\n"]])->getResponse();

        $this->assertSame('text/csv', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=books.csv', $response->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));
        $this->assertSame(
            "Title;Genre\nPolitics and the English Language;essay\nWhy I Write;essay\n",
            $response->getContent(),
        );
    }

    public function testExportWithoutPostExecuteOverrideDoesNotTriggerDeprecation(): void
    {
        // failOnDeprecation in phpunit.xml.dist makes this test fail on any deprecation
        $this->assertNotEmpty($this->datagrid()->export('csv')->getResponse()->getContent());
    }

    #[IgnoreDeprecations]
    public function testOverridingPostExecuteIsDeprecated(): void
    {
        $this->expectUserDeprecationMessageMatches('/Overriding ".*::postExecute\(\)" is deprecated/');

        $export = $this->datagrid()->export('legacy');

        $this->assertInstanceOf(LegacyBookCsvExport::class, $export);
        $this->assertTrue($export->postExecuted);
    }

    public function testUnknownExport(): void
    {
        $this->expectExceptionMessage('The "pdf" export doesn\'t exist in this datagrid.');

        $this->datagrid()->export('pdf');
    }

    public function testXlsxExport(): void
    {
        if (!class_exists(Spreadsheet::class)) {
            $this->markTestSkipped('phpoffice/phpspreadsheet is not installed.');
        }

        $response = $this->datagrid()->export('xlsx')->getResponse();

        $this->assertSame('attachment; filename=books.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    private function datagrid(): DoctrineDatagrid
    {
        $dg = $this->createDatagrid(em: $this->em)
            ->query(fn (QueryBuilder $qb): QueryBuilder => $qb
                ->from(Book::class, 'b')
                ->andWhere('b.genre = :genre')
                ->setParameter('genre', 'essay')
                ->andWhere('b.author IS NOT NULL'))
            ->select('b')
            ->id('b.id')
            ->setDefaultSort(['b.title' => 'ASC'])
            ->setExports([
                'csv' => BookCsvExport::class,
                'legacy' => LegacyBookCsvExport::class,
                'xlsx' => BookXlsExport::class,
            ]);

        return $dg;
    }
}
