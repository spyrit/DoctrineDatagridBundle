<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * XLSX (Excel) export.
 */
abstract class AbstractXlsExport extends AbstractSpreadsheetExport
{
    public function createWriter(Spreadsheet $spreadsheet): IWriter
    {
        return new Xlsx($spreadsheet);
    }

    public function getContentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
