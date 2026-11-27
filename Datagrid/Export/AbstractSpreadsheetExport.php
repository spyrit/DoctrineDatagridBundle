<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use Doctrine\ORM\QueryBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;

/**
 * Export rendered with PhpSpreadsheet: the format depends on the writer (XLSX, ODS, HTML, PDF...).
 *
 * Example of an ODS export:
 *
 *     public function createWriter(Spreadsheet $spreadsheet): IWriter
 *     {
 *         return new \PhpOffice\PhpSpreadsheet\Writer\Ods($spreadsheet);
 *     }
 *
 *     public function getContentType(): string
 *     {
 *         return 'application/vnd.oasis.opendocument.spreadsheet';
 *     }
 */
abstract class AbstractSpreadsheetExport extends AbstractExport
{
    /**
     * Headers of each sheet, indexed like the data returned by getData().
     *
     * @return array<array-key, array<mixed>>
     */
    abstract public function getHeader(): array;

    abstract public function getRow($object);

    /**
     * Creates the PhpSpreadsheet writer of the export format.
     */
    abstract public function createWriter(Spreadsheet $spreadsheet): IWriter;

    /**
     * Data of each sheet: use string keys to name the sheets.
     */
    public function getData(QueryBuilder $qb): mixed
    {
        return [$qb->getQuery()->execute()];
    }

    protected function generateContent(): string
    {
        if (!class_exists(Spreadsheet::class)) {
            throw new \LogicException(\sprintf('PhpSpreadsheet is required to use "%s". Try `composer require phpoffice/phpspreadsheet`.', static::class));
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $headers = $this->getHeader();

        foreach ($this->getData($this->qb) as $name => $data) {
            $sheet = \is_string($name) ? new Worksheet($spreadsheet, $name) : new Worksheet($spreadsheet);
            $spreadsheet->addSheet($sheet);

            $col = 0;
            $row = 1;
            if (isset($headers[$name])) {
                $col = 1;
                foreach ($headers[$name] as $value) {
                    $sheet->setCellValue([$col, $row], $value);
                    ++$col;
                }
                ++$row;
            }

            foreach ($data as $value) {
                $sheet->fromArray($this->getRow($value), null, 'A'.$row);
                ++$row;
            }

            // $col is the column following the last header
            for ($i = 1; $i < $col; ++$i) {
                $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
            }
        }

        $writer = $this->createWriter($spreadsheet);

        ob_start();
        try {
            $writer->save('php://output');
        } finally {
            $content = ob_get_clean();
        }

        return (string) $content;
    }
}
