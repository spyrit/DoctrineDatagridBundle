DoctrineDatagridBundle
======================

This bundle helps you to create and manage simple to complex datagrids quickly and easily.

Unlike other similar bundle already available on Github and/or Packagist, there is no magic method that will render the datagrid in you view. This technical choice allow you to completely customize your datagrid aspect and render (filter fields, buttons, columns, data displayed in each column, pagination links and informations, etc.).

This make it easy to implement and use in both back-end and front-end applications.

Still skeptical? Let's see how it works!

## Requirements

- PHP 8.2+
- Symfony 7.4 / 8.x
- Doctrine ORM 2.20+ / 3.x
- `phpoffice/phpspreadsheet` (optional) to export to XLSX

## Installation

### Get the code

To install this bundle automatically, use Composer:

```bash
composer require spyrit/doctrine-datagrid-bundle
```

### Use it

Create your datagrids:

```php
<?php

namespace App\Datagrid;

use App\Entity\Video;
use Doctrine\ORM\QueryBuilder;
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
            ->query(fn (QueryBuilder $qb): QueryBuilder => $qb->from(Video::class, 'video'))
            ->filter('title', TextType::class, [
                'attr' => [
                    'placeholder' => 'Title',
                ],
                'translation_domain' => false,
                'required' => false,
            ], fn (string $value, QueryBuilder $qb): QueryBuilder => $qb
                ->andWhere('video.title LIKE :title')
                ->setParameter('title', '%'.$value.'%'))
            // Restrict the columns a user can sort on (recommended)
            ->setAllowedSorts(['video.id', 'video.title'])
            ->setDefaultSort(['video.id' => 'DESC'])
            ->setDefaultMaxPerPage(30);
    }

    public function execute(): DoctrineDatagrid
    {
        return $this->datagrid->execute();
    }
}
```

> Sort directions must be uppercase (`ASC` / `DESC`): lowercase values are deprecated.

Use your datagrids in your controllers:

```php
<?php

namespace App\Controller\Admin;

use App\Datagrid\VideoDatagrid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video')]
class VideoController extends AbstractController
{
    #[Route('/list', name: 'video_index', methods: ['GET', 'POST'])]
    public function index(VideoDatagrid $datagrid): Response
    {
        return $this->render('video/index.html.twig', [
            'datagrid' => $datagrid->execute(),
        ]);
    }
}
```

Render them in your views:

```twig
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
```

## Exports

Declare export classes with `setExports(['csv' => BookCsvExport::class])`, then call
`$datagrid->export('csv')->getResponse()`. Override `preExecute()` to alter the query before the export
(`postExecute()` is deprecated). Available base classes:

- `CsvExport`: CSV, no dependency;
- `AbstractXlsExport`: XLSX (requires `phpoffice/phpspreadsheet`);
- `AbstractSpreadsheetExport`: any format supported by PhpSpreadsheet (ODS, HTML, PDF...), by providing the writer
  and the content type;
- `AbstractExport`: any other format, by implementing `generateContent()` and `getContentType()`.

```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\AbstractSpreadsheetExport;

class BookOdsExport extends AbstractSpreadsheetExport
{
    public function createWriter(Spreadsheet $spreadsheet): IWriter
    {
        return new Ods($spreadsheet);
    }

    public function getContentType(): string
    {
        return 'application/vnd.oasis.opendocument.spreadsheet';
    }

    public function getFilename(): string
    {
        return 'books.ods';
    }

    public function getHeader(): array
    {
        return [['Title', 'Genre']];
    }

    public function getRow($book): array
    {
        return [$book->getTitle(), $book->getGenre()];
    }
}
```

Other PhpSpreadsheet writers: `Html` (`text/html`, only the first sheet unless `writeAllSheets()` is called),
`Pdf\Dompdf`, `Pdf\Tcpdf` or `Pdf\Mpdf` (`application/pdf`). The PDF writers need an extra library that this
bundle does not require: check its license before choosing one (`mpdf/mpdf` is GPL-2.0, `dompdf/dompdf` LGPL-2.1,
`tecnickcom/tcpdf` LGPL-3.0). HTML and PDF rendering are slow and memory-hungry on large datagrids.

CSV options can be given with the `csvWriter` parameter, e.g.
`$datagrid->export('csv', ['csvWriter' => ['encoding' => 'UTF-8', 'delimiter' => ',', 'bom' => true]])`.
Available options (same as the former *Colibri CSV* library, Excel-friendly defaults):
`delimiter` (`;`), `enclosure` (`"`), `enclosing_mode` (`minimal`|`all`|`nonnumeric`), `encoding` (`CP1252`),
`eol` (`windows`|`unix`|`mac`), `escape` (`\`), `escape_double` (`true`), `bom` (`false`),
`translit` (`translit`|`ignore`|`null`), `first_row_header` (`false`), `trim` (`false`).

## Batch actions (JavaScript)

`Resources/public/js/jquery.batch.js` (published with `assets:install`) only requires jQuery
(and Bootstrap popovers for the "select all" feature): the *jquery.cookie* plugin is no longer needed.

## Development

```bash
composer install
composer test      # PHPUnit
composer phpstan   # static analysis
composer cs        # coding standards (composer cs-fix to fix them)
```

## Credit

Our special thanks go to ...

- Charles SANQUER for its fork of the *LightCSV* library : *Colibri CSV*, which inspired the CSV writer of the export feature.
- Subosito for its standalone *Inflector* [class](http://subosito.com/inflector-in-symfony-2/)  transformed in a service for our needs.
