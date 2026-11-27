<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

/**
 * Builds datagrids on top of real Symfony services (in-memory session, request stack, form factory).
 */
trait DatagridTestTrait
{
    private ?Session $session = null;

    /**
     * @template T of DoctrineDatagrid
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function createDatagrid(
        ?Request $request = null,
        array $params = [],
        ?RouterInterface $router = null,
        ?EntityManagerInterface $em = null,
        string $name = 'dg',
        string $class = DoctrineDatagrid::class,
    ): DoctrineDatagrid {
        return new $class(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $this->createRequestStack($request ?? new Request()),
            $this->createFormFactory(),
            $router ?? $this->createStub(RouterInterface::class),
            $name,
            $params,
        );
    }

    /**
     * The session is shared by every request created during a test, like a real user session.
     */
    private function createRequestStack(Request $request): RequestStack
    {
        $this->session ??= new Session(new MockArraySessionStorage());
        $request->setSession($this->session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function createFormFactory(): FormFactoryInterface
    {
        // Like a full-stack application: the CSRF extension defines the "csrf_protection" option
        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension(new CsrfTokenManager()))
            ->getFormFactory();
    }
}
