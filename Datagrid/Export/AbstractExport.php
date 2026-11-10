<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use Doctrine\ORM\QueryBuilder;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Util;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Base class of the exports: runs the export and serves its content as a file download.
 */
abstract class AbstractExport implements Export
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
     * MIME type of the generated file, e.g. "text/csv".
     */
    abstract public function getContentType(): string;

    /**
     * Generates the content of the file.
     */
    abstract protected function generateContent(): string;

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

    public function execute(): static
    {
        $this->preExecute();
        $this->callDeprecatedPostExecute();

        $this->content = $this->generateContent();

        return $this;
    }

    public function getResponse(): Response
    {
        $content = (string) $this->content;
        $response = new Response($content);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->getFilename()
        );

        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', $this->getContentType());
        $response->headers->set('Expires', '0');
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $response->headers->set('Content-Length', (string) \strlen($content));
        $response->setCharset('UTF-8');

        return $response;
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

        Util::triggerDeprecation(\sprintf('Overriding "postExecute()" in "%s" is deprecated, override "preExecute()" instead.', static::class), DoctrineDatagrid::VERSION_SYMFONY7);
        $this->postExecute();
    }
}
