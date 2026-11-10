<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\CsvWriter;

final class CsvWriterTest extends TestCase
{
    public function testDefaultsMatchTheExcelDialect(): void
    {
        $content = (new CsvWriter())->writeRow(['Élodie', 'a;b'])->getContent();

        // CP1252, ";" delimiter, minimal enclosing, CRLF
        $this->assertSame("\xC9lodie;\"a;b\"\r\n", $content);
    }

    public function testNullAndNonStringValues(): void
    {
        $writer = new CsvWriter(['encoding' => 'UTF-8', 'eol' => 'unix']);
        $writer->writeRow(['a', null, 12, 1.5, true, false, new class implements \Stringable {
            public function __toString(): string
            {
                return 'str';
            }
        }]);

        $this->assertSame("a;;12;1.5;1;;str\n", $writer->getContent());
    }

    public function testNonStringableValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"DateTimeImmutable" given');

        (new CsvWriter())->writeRow([new \DateTimeImmutable()]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function optionsCases(): iterable
    {
        yield 'enclosure doubled' => [[], "\"il a dit \"\"oui\"\"\";x\n"];
        yield 'enclosure escaped' => [['escape_double' => false], "\"il a dit \\\"oui\\\"\";x\n"];
        yield 'enclose all' => [['enclosing_mode' => 'all'], "\"il a dit \"\"oui\"\"\";\"x\"\n"];
        yield 'custom delimiter' => [['delimiter' => ','], "\"il a dit \"\"oui\"\"\",x\n"];
        yield 'BOM' => [['bom' => true], "\xEF\xBB\xBF\"il a dit \"\"oui\"\"\";x\n"];
        yield 'header from keys' => [['first_row_header' => true], "col1;col2\n\"il a dit \"\"oui\"\"\";x\n"];
        yield 'case-insensitive options' => [['DELIMITER' => '|'], "\"il a dit \"\"oui\"\"\"|x\n"];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('optionsCases')]
    public function testOptions(array $options, string $expected): void
    {
        $writer = new CsvWriter($options + ['encoding' => 'UTF-8', 'eol' => "\n"]);
        $writer->writeRow(['col1' => 'il a dit "oui"', 'col2' => 'x']);

        $this->assertSame($expected, $writer->getContent());
    }

    public function testUnsupportedEncoding(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new CsvWriter(['encoding' => 'NOT-AN-ENCODING']))->writeRow(['a']);
    }
}
