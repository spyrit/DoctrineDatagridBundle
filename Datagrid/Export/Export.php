<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
interface Export
{
    public function execute();
    
    public function getResponse();
    
    public function getFilename();
}
