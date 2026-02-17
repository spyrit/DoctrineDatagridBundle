<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class FilterObject
{
    protected array $data = [];

    protected array $types = [];

    protected array $options = [];

    protected FormBuilderInterface $builder;

    protected $form;

    public function __construct(FormFactory $factory, $name, $options = ['csrf_protection' => false])
    {
        $version = Kernel::VERSION;
        switch (substr($version, 0, 1)) {
            case '2': $type = 'form'; break;
            default: $type = FormType::class;
        }
        $this->builder = $factory->createNamedBuilder('filter_'.$name, $type, null, $options);
    }

    public function add(string $name, mixed $type, array $options = [], $value = null): void
    {
        $this->options[$name] = $options;
        $this->types[$name] = $type;
        $this->data[$name] = $value;

        $this->builder->add($name, $type, $options);
    }

    public function submit(array $data): void
    {
        $this->data = $data;
        $this->form = $this->getForm();
        $this->form->submit($this->data);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getBuilder(): FormBuilderInterface
    {
        return $this->builder;
    }

    public function getForm(): FormInterface
    {
        if (!$this->form) {
            return $this->builder->getForm();
        }

        return $this->form;
    }

    public function getType($field): mixed
    {
        return $this->types[$field] ?? null;
    }

    public function getOptions(mixed $field): mixed
    {
        return $this->options[$field] ?? null;
    }

    public function getOption(mixed $field, mixed $option, mixed $default = null): mixed
    {
        if (isset($this->options[$field][$option])) {
            return $this->options[$field][$option];
        }

        return $default;
    }
}
