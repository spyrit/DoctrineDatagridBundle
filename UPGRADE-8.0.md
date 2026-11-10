# Upgrade from 7.x to 8.0

## Requirements

- PHP 8.2+ (unchanged)
- Symfony **7.4** or 8.x: Symfony 7.0 to 7.3 are no longer supported (end of life).
- Doctrine ORM **2.20+** or 3.x.
- `phpoffice/phpspreadsheet` 1.23+ when using `AbstractXlsExport` (still optional).

## CSV exports

`csanquer/colibri-csv` is no longer a dependency: it is abandoned and triggered a PHP 8.1+ deprecation
for every `null` cell. `CsvExport` now uses the bundle's own
`Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\CsvWriter`.

- The `csvWriter` export options are unchanged (same names, same defaults), and so is the generated CSV.
- If your code uses `CSanquer\ColibriCsv\*` classes directly (e.g. in an overridden `execute()`), either
  require `csanquer/colibri-csv` in your project or switch to the new `CsvWriter`
  (`getFileContent()` becomes `getContent()`, no `createTempStream()` / `close()` calls needed).
- A cell value must be a scalar, `null` or a `\Stringable` object: other values (e.g. `\DateTimeInterface`)
  now throw an `\InvalidArgumentException`. Format them in `getRow()`.

## Exports

`CsvExport` and `AbstractXlsExport` now share a new `AbstractExport` base class (common `getResponse()`), and
`AbstractXlsExport` extends the new `AbstractSpreadsheetExport`, which accepts any PhpSpreadsheet writer
(ODS, HTML, PDF...). Existing exports keep working unchanged (same content and HTTP headers), except:

- XLSX exports no longer contain an extra empty column after the last header.
- If your export already defines a `getContentType()`, `generateContent()` or `createWriter()` method,
  check that its signature is compatible with the new base classes.

## Exports: `postExecute()`

`postExecute()` was already deprecated since 7.0. It is now only called when an export overrides it,
and a deprecation is triggered in that case: move the code to `preExecute()`.

## Sort directions

Lowercase sort directions (`asc`, `desc`) are deprecated (since 7.0): use `ASC` / `DESC`,
including in `setDefaultSort()` and in the `getSortPath()` calls of your templates.

## `DoctrineDatagrid` subclasses

- The protected `$session` property has been removed: use `$this->getSession()`.
- `getRequestedAction()` now returns `?string` (it threw a `TypeError` when no action was requested).
- `getRequestedLimit()` signature is now `getRequestedLimit(int $default = 0): int`.
- The unused private methods `isRequestedAction()`, `getSessionFilter()` and `setSessionFilter()` have been removed.

## Bundle configuration

- `DependencyInjection\DoctrineDatagridExtension` and `DependencyInjection\Configuration` have been removed:
  the bundle now extends `AbstractBundle`. The `DoctrineDatagridFactory` service is unchanged.
- `Resources/config/routing.yml` (demo routes pointing to a non-existent controller) has been removed:
  remove any import of it from your routing configuration.

## Templates

- The deprecated Twig `spaceless` filter is no longer used; the rendered HTML is unchanged.
- The `batchHeader` macro now displays the number of results of the current page and the total number
  of results (it referenced a non-existent `datagrid.pager` property).

## JavaScript (`jquery.batch.js`)

The `jquery.cookie` plugin is no longer needed: you can remove it from your `package.json` and layouts.
The cookie format is unchanged, so current selections are kept.
