<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use Symfony\Component\HttpFoundation\Response;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
abstract class CsvExport extends AbstractExport
{
    abstract public function getHeader();

    abstract public function getRow($object);

    public function getContentType(): string
    {
        return 'text/csv';
    }

    public function getResponse(): Response
    {
        $response = parent::getResponse();
        $response->headers->set('Content-Description', 'File Transfer');
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->headers->set('Pragma', 'public');

        return $response;
    }

    public function getDelimiter(): string
    {
        return ';';
    }

    protected function generateContent(): string
    {
        $writer = new CsvWriter($this->getCsvWriterOptions());

        if ($this->getHeader()) {
            $writer->writeRow($this->getHeader());
        }

        foreach ($this->qb->getQuery()->execute() as $result) {
            $writer->writeRow($this->getRow($result));
        }

        return $writer->getContent();
    }

    /**
     * Options of the CSV writer, from the "csvWriter" export parameter.
     *
     * @see CsvWriter::__construct()
     */
    protected function getCsvWriterOptions(): array
    {
        if (isset($this->params['csvWriter'])) {
            return $this->params['csvWriter'];
        }

        return [];
    }
}
