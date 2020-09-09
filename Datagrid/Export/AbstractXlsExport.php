<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use Doctrine\ORM\QueryBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

abstract class AbstractXlsExport implements Export
{
    protected $content;

    /**
     * @var array
     */
    protected $params;

    /**
     * @var QueryBuilder
     */
    protected $qb;

    public function __construct($qb, $params)
    {
        $this->qb = $qb;
        $this->params = $params;
    }

    abstract public function getFilename();

    abstract public function getHeader();

    abstract public function getRow($object);

    public function getData(QueryBuilder $qb)
    {
        return [$qb->getQuery()->execute()];
    }

    public function postExecute()
    {
        return $this;
    }

    public function execute()
    {
        $this->postExecute();

        $spreadsheet = new Spreadsheet();

        $spreadsheet->removeSheetByIndex(0);

        $headers = $this->getHeader();
        $datas = $this->getData($this->qb);

        $writer = new Xlsx($spreadsheet);

        $row = 1;

        foreach ($datas as $name => $data) {
            $col = 0;
            if (is_string($name)) {
                $sheet = new Worksheet($spreadsheet, $name);
            } else {
                $sheet = new Worksheet($spreadsheet);
            }
            $spreadsheet->addSheet($sheet);
            $row = 1;
            if (isset($headers[$name])) {
                $col = 1;
                foreach ($headers[$name] as $value) {
                    $sheet->setCellValueByColumnAndRow($col, $row, $value);
                    ++$col;
                }
                ++$row;
            }

            foreach ($data as $value) {
                $sheet->fromArray(
                    $this->getRow($value),
                    null,
                    'A'.$row
                );
                ++$row;
            }
            for ($i = 1; $i <= $col; ++$i) {
                $sheet
                    ->getColumnDimensionByColumn($i)
                    ->setAutoSize(true)
                ;
            }
        }

        ob_start();
        $writer->save('php://output');
        $this->content = ob_get_clean();

        return $this;
    }

    public function getResponse()
    {
        $response = new Response($this->content);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->getFilename()
        );

        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Expires', '0');
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $response->headers->set('Content-Length', (string) strlen($this->content));
        $response->setCharset('UTF-8');

        return $response;
    }
}
