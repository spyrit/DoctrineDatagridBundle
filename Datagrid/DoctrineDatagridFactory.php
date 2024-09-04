<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
         protected EntityManagerInterface $entityManager,
         protected RequestStack $requestStack,
         protected FormFactoryInterface $formFactory,
         protected RouterInterface $router
    ) {
    }

    /**
     * Create an instance of DoctrineDatagrid.
     */
    public function create(string $name, array $params = []): DoctrineDatagrid
    {
        return new DoctrineDatagrid(
            $this->entityManager,
            $this->requestStack,
            $this->formFactory,
            $this->router,
            $name,
            $params
        );
    }
}
