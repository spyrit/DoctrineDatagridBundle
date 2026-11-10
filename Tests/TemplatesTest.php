<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use Twig\TwigFilter;

final class TemplatesTest extends TestCase
{
    private const VIEWS_DIR = __DIR__.'/../Resources/views';

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        foreach (glob(self::VIEWS_DIR.'/Datagrid/*.html.twig') as $file) {
            yield basename($file) => ['Datagrid/'.basename($file)];
        }
    }

    #[DataProvider('templates')]
    public function testTemplateCompiles(string $template): void
    {
        $twig = $this->createTwig();
        $code = $twig->getLoader()->getSourceContext($template)->getCode();

        $this->assertNotEmpty($twig->compileSource(new Source($code, $template)));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function paginationCases(): iterable
    {
        yield 'middle page' => [5, 10, '1 … 3 4 [5] 6 7 … 10'];
        yield 'first page' => [1, 10, '[1] 2 3 … 10'];
        yield 'last page' => [10, 10, '1 … 8 9 [10]'];
        yield 'no gap' => [3, 5, '1 2 [3] 4 5'];
        yield 'two pages' => [2, 2, '1 [2]'];
        yield 'single page' => [1, 1, ''];
    }

    #[DataProvider('paginationCases')]
    public function testPagination(int $page, int $pages, string $expected): void
    {
        $datagrid = new class($page, $pages) {
            public function __construct(public int $currentPage, public int $nbPages)
            {
            }

            public function getPaginationPath(string $route, int $page, array $extraParams = []): string
            {
                return '/'.$route.'/'.$page;
            }
        };

        $html = $this->createTwig()->render('Datagrid/pagination.html.twig', ['datagrid' => $datagrid, 'route' => 'books']);

        $this->assertStringNotContainsString('</li> <li', $html, 'No whitespace between items (inline Bootstrap 3 lists)');
        $this->assertSame($expected, $this->summarize($html));
    }

    private function summarize(string $html): string
    {
        preg_match_all('#<li class="page-item( active| disabled)?"><a[^>]*>([^<]+)</a></li>#', $html, $matches, \PREG_SET_ORDER);

        return implode(' ', array_map(static fn (array $m): string => match ($m[1]) {
            ' active' => '['.$m[2].']',
            ' disabled' => '…',
            default => $m[2],
        }, $matches));
    }

    private function createTwig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(self::VIEWS_DIR), ['strict_variables' => true]);
        // Provided by symfony/twig-bridge in applications
        $twig->addFilter(new TwigFilter('trans', static fn (string $message): string => $message));

        return $twig;
    }
}
