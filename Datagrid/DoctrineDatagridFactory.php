<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;

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
     * Just a simple constructor.
     */
     public function __construct(
         ManagerRegistry $doctrine,
         RequestStack $requestStack,
         SessionInterface $session,
         FormFactoryInterface $formFactory,
         RouterInterface $router
    ) {
        $this->doctrine = $doctrine;
        $this->request_stack = $requestStack;
        $this->session = $session;
        $this->form_factory = $formFactory;
        $this->router = $router;
    }

    /**
     * Create an instance of DoctrineDatagrid.
     */
    public function create(string $name, array $params = []): DoctrineDatagrid
    {
        return new DoctrineDatagrid($this->doctrine, $this->request_stack, $this->session, $this->form_factory, $this->router, $name, $params);
    }
}
