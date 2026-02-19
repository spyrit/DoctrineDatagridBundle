<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Webmozart\Assert\Assert;

class Util
{
    public static function triggerDeprecation(string $message, string $version): void
    {
        trigger_deprecation('spyrit/doctrine-datagrid-bundle', $version, $message);
    }

    public static function validateSortDirection(string $direction): string
    {
        if (strtolower($direction) === $direction) {
            Util::triggerDeprecation('Providing lowercase sort order is deprecated ("asc"|"desc"), use uppercase instead ("ASC"|"DESC").', DoctrineDatagrid::VERSION_SYMFONY7);
        }

        $sortDirection = strtoupper($direction);
        Assert::inArray($sortDirection, DoctrineDatagrid::ALLOWED_SORT_DIRECTIONS, 'Invalid sort direction provided. Allowed values are "ASC", "DESC"');

        return $sortDirection;
    }
}