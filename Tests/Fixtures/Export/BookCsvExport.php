<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export;

use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\CsvExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;

class BookCsvExport extends CsvExport
{
    public function getFilename(): string
    {
        return 'books.csv';
    }

    public function getHeader(): array
    {
        return ['Title', 'Genre'];
    }

    /**
     * @param Book $object
     */
    public function getRow($object): array
    {
        return [$object->title, $object->genre];
    }
}
