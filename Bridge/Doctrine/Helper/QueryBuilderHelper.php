<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Bridge\Doctrine\Helper;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

class QueryBuilderHelper
{
    public static function addLeftJoin(string $field, string $alias, QueryBuilder $qb): QueryBuilder
    {
        /** @var array<string, Join[]> $joinsByRootAlias */
        $joinsByRootAlias = $qb->getDQLPart('join');

        foreach ($joinsByRootAlias as $joins) {
            foreach ($joins as $join) {
                if ($join->getAlias() === $alias) {
                    return $qb;
                }
            }
        }

        return $qb->leftJoin($field, $alias);
    }
}
