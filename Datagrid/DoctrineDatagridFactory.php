<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;
use Symfony\Component\DependencyInjection\Container;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class DoctrineDatagridFactory
{
    protected $container;
    
    /**
     * Just a simple constructor
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    
    /**
     * Create an instance of DoctrineDatagrid
     * @param string $name
     * @return \DoctrineDatagridBundle\Datagrid\DoctrineDatagrid
     */
    public function create($name, $params = array())
    {
        return new DoctrineDatagrid($this->container, $name, $params);
    }
}
