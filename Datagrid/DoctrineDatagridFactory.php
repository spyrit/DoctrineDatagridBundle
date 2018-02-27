<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;
use Symfony\Component\DependencyInjection\Container;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class DoctrineDatagridFactory
{
    protected $doctrine;
    protected $request_stack;
    protected $session;
    protected $form_factory;
    protected $router;

    /**
     * Just a simple constructor
     * @param Container $container
     */
    public function __construct($doctrine, $request_stack, $session, $form_factory, $router)
    {
        $this->doctrine = $doctrine;
        $this->request_stack = $request_stack;
        $this->session = $session;
        $this->form_factory = $form_factory;
        $this->router = $router;
    }
    
    /**
     * Create an instance of DoctrineDatagrid
     * @param string $name
     * @return \DoctrineDatagridBundle\Datagrid\DoctrineDatagrid
     */
    public function create($name, $params = array())
    {
        return new DoctrineDatagrid($this->doctrine, $this->request_stack, $this->session, $this->form_factory, $this->router, $name, $params);
    }
}
