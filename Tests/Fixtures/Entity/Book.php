<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        public string $title,
        #[ORM\Column]
        public string $genre,
        #[ORM\ManyToOne(targetEntity: Author::class)]
        public ?Author $author = null,
    ) {
    }
}
