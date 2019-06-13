<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class FilterObject
{
    protected $data = [];

    protected $types = [];

    protected $options = [];

    protected $builder;

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

    public function add($name, $type, $options = [], $value = null)
    {
        $this->options[$name] = $options;
        $this->types[$name] = $type;
        $this->data[$name] = $value;

        $this->builder->add($name, $type, $options);
    }

    public function submit($data)
    {
        $this->data = $data;
        $this->form = $this->getForm();
        $this->form->submit($this->data);
    }

    public function getData()
    {
        return $this->data;
    }

    public function getBuilder()
    {
        return $this->builder;
    }

    public function getForm()
    {
        if (!$this->form) {
            return $this->builder->getForm();
        }

        return $this->form;
    }

    public function getType($field)
    {
        return isset($this->types[$field]) ? $this->types[$field] : null;
    }

    public function getOptions($field)
    {
        return isset($this->options[$field]) ? $this->options[$field] : null;
    }

    public function getOption($field, $option, $default = null)
    {
        if (isset($this->options[$field]) && isset($this->options[$field][$option])) {
            return $this->options[$field][$option];
        }

        return $default;
    }
}
