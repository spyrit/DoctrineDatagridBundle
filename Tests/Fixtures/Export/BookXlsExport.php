<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export;

use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\AbstractXlsExport;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;

class BookXlsExport extends AbstractXlsExport
{
    public function getFilename(): string
    {
        return 'books.xlsx';
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
