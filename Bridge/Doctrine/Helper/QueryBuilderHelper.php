<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Bridge\Doctrine\Helper;

use Doctrine\ORM\QueryBuilder;

class QueryBuilderHelper
{
    public static function addLeftJoin(string $field, string $alias, QueryBuilder $qb): QueryBuilder
    {
        $parts = $qb->getDQLParts()['join'];
        $exists = false;

        foreach ($parts as $joins) {
            foreach ($joins as $join) {
                foreach ((array) $join as $key => $val) {
                    if ($val == $alias) {
                        $exists = true;
                        break 3;
                    }
                }
            }
        }

        /*
         * The following code is only available for Doctrine 2.5
         */
        /*$parts = $qb->getDQLPart('join');
        $exists = false;

        foreach ($parts as $joins)
        {
            foreach ($joins as $join)
            {
                if ($join->getAlias() === $alias)
                {
                    $exists = true;
                    break 2;
                }
            }
        }*/

        if (!$exists) {
            $qb->leftJoin($field, $alias);
        }

        return $qb;
    }
}
