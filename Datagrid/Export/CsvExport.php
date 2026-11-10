<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use Doctrine\ORM\QueryBuilder;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Util;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
abstract class CsvExport implements Export
{
    protected ?string $content = null;

    protected array $params;

    protected QueryBuilder $qb;

    public function __construct(QueryBuilder $qb, array $params)
    {
        $this->qb = $qb;
        $this->params = $params;
    }

    /**
     * Do anything you want before the export, like modifying the query.
     */
    public function preExecute(): void
    {
    }

    /**
     * @deprecated since 7.0, override preExecute() instead
     */
    #[\Deprecated(message: 'postExecute has been deprecated, as it is implemented as a preExecute. Use preExecute instead', since: DoctrineDatagrid::VERSION_SYMFONY7)]
    public function postExecute()
    {
        return $this;
    }

    /**
     * Keeps BC for exports still overriding postExecute(), without triggering
     * a deprecation for the others.
     */
    private function callDeprecatedPostExecute(): void
    {
        if (self::class === (new \ReflectionMethod($this, 'postExecute'))->getDeclaringClass()->getName()) {
            return;
        }

        Util::triggerDeprecation(sprintf('Overriding "%s::postExecute()" is deprecated, override "preExecute()" instead.', self::class), DoctrineDatagrid::VERSION_SYMFONY7);
        $this->postExecute();
    }

    public function execute(): static
    {
        $this->preExecute();
        $this->callDeprecatedPostExecute();

        $writer = new CsvWriter($this->getCsvWriterOptions());

        if ($this->getHeader()) {
            $writer->writeRow($this->getHeader());
        }

        $results = $this->qb->getQuery()->execute();

        foreach ($results as $result) {
            $writer->writeRow($this->getRow($result));
        }

        $this->content = $writer->getContent();

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
        $response->headers->set('Content-Length', (string) strlen($this->content));
        $response->setCharset('UTF-8');

        return $response;
    }

    abstract public function getHeader();

    abstract public function getRow($object);

    public function getDelimiter(): string
    {
        return ';';
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
