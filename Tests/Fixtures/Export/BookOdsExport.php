<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\AbstractSpreadsheetExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;

class BookOdsExport extends AbstractSpreadsheetExport
{
    public function getFilename(): string
    {
        return 'books.ods';
    }

    public function getContentType(): string
    {
        return 'application/vnd.oasis.opendocument.spreadsheet';
    }

    public function createWriter(Spreadsheet $spreadsheet): IWriter
    {
        return new Ods($spreadsheet);
    }

    public function getHeader(): array
    {
        return [['Title', 'Genre']];
    }

    /**
     * @param Book $object
     */
    public function getRow($object): array
    {
        return [$object->title, $object->genre];
    }
}
