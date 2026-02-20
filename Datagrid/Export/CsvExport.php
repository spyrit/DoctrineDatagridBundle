<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use CSanquer\ColibriCsv\CsvWriter;
use Doctrine\ORM\QueryBuilder;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
abstract class CsvExport implements Export
{
    protected $content;

    protected array $params;

    protected QueryBuilder $qb;

    public function __construct($qb, $params)
    {
        $this->qb = $qb;
        $this->params = $params;
    }

    /**
     * Do anything you want before the export, like modifying the query
     */
    public function preExecute(): void
    {
    }

    #[\Deprecated(since: DoctrineDatagrid::VERSION_SYMFONY7, message: 'postExecute has been deprecated, as it is implemented as a preExecute. Use preExecute instead')]
    public function postExecute()
    {
        return $this;
    }

    public function execute(): static
    {
        $this->preExecute();

        // postExecute before executing ? Meh.
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

    public function getResponse(): Response
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

    public function getDelimiter(): string
    {
        return ';';
    }

    protected function getCsvWriterOptions(): array
    {
        if (isset($this->params['csvWriter'])) {
            return $this->params['csvWriter'];
        }

        return [];
    }
}
