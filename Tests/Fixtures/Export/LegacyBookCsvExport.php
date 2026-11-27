<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Export;

/**
 * Export still relying on the deprecated postExecute() hook.
 */
class LegacyBookCsvExport extends BookCsvExport
{
    public bool $postExecuted = false;

    public function postExecute(): static
    {
        $this->postExecuted = true;

        return $this;
    }
}
