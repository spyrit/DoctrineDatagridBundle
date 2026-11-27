<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export;

use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\CsvExport;

/**
 * Export of an aggregated (GROUP BY) datagrid.
 */
class GenreCountCsvExport extends CsvExport
{
    public function getFilename(): string
    {
        return 'genres.csv';
    }

    public function getHeader(): array
    {
        return ['Genre', 'Total'];
    }

    /**
     * @param array{genre: string, total: int} $object
     */
    public function getRow($object): array
    {
        return [$object['genre'], $object['total']];
    }
}
