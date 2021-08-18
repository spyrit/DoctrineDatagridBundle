DoctrineDatagridBundle
======================

This bundle helps you to create and manage simple to complex datagrids quickly and easily.

Unlike other similar bundle already available on Github and/or Packagist, there is no magic method that will render the datagrid in you view. This technical choice allow you to completely customize your datagrid aspect and render (filter fields, buttons, columns, data displayed in each column, pagination links and informations, etc.).

This make it easy to implement and use in both back-end and front-end applications.

Still skeptical? Let's see how it works!

## Installation

### Get the code

To install this bundle automatically, use Composer:

    composer req spyrit/doctrine-datagrid-bundle

### Use it

Create your datagrids:

    <?php

    namespace App\Datagrid;

    use App\Entity\Video;
    use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagrid;
    use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\DoctrineDatagridFactory;
    use Symfony\Component\Form\Extension\Core\Type\TextType;

    class VideoDatagrid
    {
        private DoctrineDatagrid $datagrid;

        public function __construct(DoctrineDatagridFactory $factory)
        {
            $this->datagrid = $factory
                ->create('video', ['multi_sort' => false])
                ->select(['video'])
                ->id('video.id')
                ->query(function ($qb) {
                    return $qb->from(Video::class, 'video');
                })
                ->filter('title', TextType::class, [
                    'attr' => [
                        'placeholder' => 'Title',
                    ],
                    'translation_domain' => false,
                    'required' => false,
                ], function ($value, $qb) {
                    return $qb->andWhere('video.title LIKE :title')
                        ->setParameter('title', '%'.$value.'%');
                })
                ->setDefaultSort(['video.id' => 'desc'])
                ->setDefaultMaxPerPage(30);
        }

        public function execute(): DoctrineDatagrid
        {
            return $this->datagrid->execute();
        }
    }

Use your datagrids in your controllers:

    <?php

    namespace App\Controller\Admin;

    use App\Datagrid\VideoDatagrid;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\Routing\Annotation\Route;

    /**
    * @Route("/video")
    */
    class VideoController extends Controller
    {
        /**
         * @Route("/list", name="video_index", methods={"GET","POST"})
         */
        public function index(VideoDatagrid $datagrid): Response
        {
            return $this->render('video/index.html.twig', [
                'datagrid' => $datagrid->execute(),
            ]);
        }
    }

Render them in your views:

    {% extends 'layout.html.twig' %}
    {% import '@DoctrineDatagrid/Datagrid/macros.html.twig' as macros %}

    {% set form = datagrid.filterFormView %}
    {% set route = app.request.attributes.get('_route') %}

    {% block body %}
        <table class="table">
            <thead>
                <tr id="filters">
                    {{ form_start(form) }}
                    <td>
                        {{ form_widget(form.title) }}
                    </td>
                    <td class="text-right">
                        <button class="btn btn-primary">{{ 'Filter' }}</button>
                        <a href="{{ datagrid.resetPath(route) }}" class="btn btn-secondary">{{ 'Reset' }}</a>
                    </td>
                    {{ form_end(form) }}
                </tr>
                <tr>
                    <th>{{ 'Title' }}</th>
                    <th>{{ 'Description' }}</th>
                </tr>
            </thead>
            <tbody>
                {% for video in datagrid.results %}
                    <tr>
                        <td>{{ video.title }}</td>
                        <td>{{ video.description }}</td>
                    </tr>
                {% else %}
                    <tr>
                        <td colspan="2">{{ 'No video' }}</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
        <div class="row">
            <div class="col-md-4"></div>
            <div class="col-md-4">
                {% include '@DoctrineDatagrid/Datagrid/pagination_info.html.twig' %}
            </div>
            <div class="col-md-4">
                {% include '@DoctrineDatagrid/Datagrid/pagination.html.twig' %}
            </div>
        </div>
    {% endblock %}

## Credit

Our special thanks go to ...

- Charles SANQUER for its fork of the *LightCSV* library : *Colibri CSV* used in the export feature.
- Subosito for its standalone *Inflector* [class](http://subosito.com/inflector-in-symfony-2/)  transformed in a service for our needs.
