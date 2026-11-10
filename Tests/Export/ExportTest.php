<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Export;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\DatagridTestTrait;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export\BookCsvExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export\BookHtmlExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export\BookOdsExport;
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
        $this->expectUserDeprecationMessageMatches('/Overriding "postExecute\(\)" in ".*LegacyBookCsvExport" is deprecated/');

        $export = $this->datagrid()->export('legacy');

        $this->assertInstanceOf(LegacyBookCsvExport::class, $export);
        $this->assertTrue($export->postExecuted);
    }

    public function testUnknownExport(): void
    {
        $this->expectExceptionMessage('The "pdf" export doesn\'t exist in this datagrid.');

        $this->datagrid()->export('pdf');
    }

    public function testCsvResponseKeepsItsHeaders(): void
    {
        $headers = $this->datagrid()->export('csv')->getResponse()->headers;

        $this->assertSame('File Transfer', $headers->get('Content-Description'));
        $this->assertSame('binary', $headers->get('Content-Transfer-Encoding'));
        $this->assertSame('public', $headers->get('Pragma'));
        $this->assertSame('0', $headers->get('Expires'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function spreadsheetExports(): iterable
    {
        yield 'xlsx' => ['xlsx', 'books.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        yield 'ods' => ['ods', 'books.ods', 'application/vnd.oasis.opendocument.spreadsheet'];
        yield 'html' => ['html', 'books.html', 'text/html'];
    }

    #[DataProvider('spreadsheetExports')]
    public function testSpreadsheetExport(string $export, string $filename, string $contentType): void
    {
        if (!class_exists(Spreadsheet::class)) {
            $this->markTestSkipped('phpoffice/phpspreadsheet is not installed.');
        }

        $response = $this->datagrid()->export($export)->getResponse();

        $this->assertSame('attachment; filename='.$filename, $response->headers->get('Content-Disposition'));
        $this->assertSame($contentType, $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));

        $reader = IOFactory::createReader(ucfirst($export));
        $file = tempnam(sys_get_temp_dir(), 'export');
        try {
            file_put_contents($file, $response->getContent());
            $rows = $reader->load($file)->getActiveSheet()->toArray();
        } finally {
            unlink($file);
        }

        $this->assertSame([
            ['Title', 'Genre'],
            ['Politics and the English Language', 'essay'],
            ['Why I Write', 'essay'],
        ], $rows);
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
                'ods' => BookOdsExport::class,
                'html' => BookHtmlExport::class,
            ]);

        return $dg;
    }
}
