<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use CSanquer\ColibriCsv\CsvWriter;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
abstract class CsvExport implements Export
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

    public function postExecute()
    {
        return $this;
    }

    public function execute()
    {
        $this->postExecute();

        $writer = new CsvWriter($this->getCsvWriterOptions());
        $writer->createTempStream();

        if ($this->getHeader()) {
            $writer->writeRow($this->getHeader());
        }

        $results = $this->qb->getQuery()->execute();

        foreach ($results as $result) {
            $writer->writeRow($this->getRow($result));
        }

        $this->content = $writer->getFileContent();
        $writer->close();

        return $this;
    }

    public function getResponse()
    {
        $response = new Response($this->content);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->getFilename()
        );

        $response->headers->set('Content-Description', 'File Transfer');
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->headers->set('Expires', '0');
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $response->headers->set('Pragma', 'public');
        $response->headers->set('Content-Length', strlen($this->content));
        $response->setCharset('UTF-8');

        return $response;
    }

    abstract public function getHeader();

    abstract public function getRow($object);

    public function getDelimiter()
    {
        return ';';
    }

    protected function getCsvWriterOptions()
    {
        if (isset($this->params['csvWriter'])) {
            return $this->params['csvWriter'];
        }

        return [];
    }
}
