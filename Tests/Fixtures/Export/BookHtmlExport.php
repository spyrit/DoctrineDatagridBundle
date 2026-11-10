<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Html;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\AbstractSpreadsheetExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;

class BookHtmlExport extends AbstractSpreadsheetExport
{
    public function getFilename(): string
    {
        return 'books.html';
    }

    public function getContentType(): string
    {
        return 'text/html';
    }

    public function createWriter(Spreadsheet $spreadsheet): IWriter
    {
        return new Html($spreadsheet);
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
