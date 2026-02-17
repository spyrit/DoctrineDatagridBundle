<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class DoctrineDatagridFactory
{
    /**
     * Just a simple constructor.
     */
     public function __construct(
         private EntityManagerInterface $em,
         private RequestStack $requestStack,
         private FormFactoryInterface $formFactory,
         private RouterInterface $router
    ) {
    }

    /**
     * Create an instance of DoctrineDatagrid.
     */
    public function create(string $name, array $params = []): DoctrineDatagrid
    {
        return new DoctrineDatagrid($this->em, $this->requestStack, $this->formFactory, $this->router, $name, $params);
    }
}
