<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

use Symfony\Component\HttpFoundation\Response;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
interface Export
{
    public function execute(): static;

    public function getResponse(): Response;

    public function getFilename(): string;
}
