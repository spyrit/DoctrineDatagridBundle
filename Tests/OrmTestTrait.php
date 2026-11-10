<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Author;
use Spyrit\Bundle\DoctrineDatagridBundle\Tests\Fixtures\Entity\Book;

/**
 * Real EntityManager on an in-memory SQLite database (Doctrine Query is final, it cannot be mocked).
 */
trait OrmTestTrait
{
    private function createEntityManager(): EntityManagerInterface
    {
        $paths = [__DIR__.'/Fixtures/Entity'];
        $config = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($connection, $config);

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }

    /**
     * 7 books: 4 "novel" (3 by Asimov), 3 "essay".
     */
    private function loadBooks(EntityManagerInterface $em): void
    {
        $asimov = new Author('Asimov');
        $orwell = new Author('Orwell');
        $em->persist($asimov);
        $em->persist($orwell);

        foreach ([
            ['Foundation', 'novel', $asimov],
            ['I, Robot', 'novel', $asimov],
            ['The End of Eternity', 'novel', $asimov],
            ['1984', 'novel', $orwell],
            ['Politics and the English Language', 'essay', $orwell],
            ['Why I Write', 'essay', $orwell],
            ['A Nice Cup of Tea', 'essay', null],
        ] as [$title, $genre, $author]) {
            $em->persist(new Book($title, $genre, $author));
        }

        $em->flush();
        $em->clear();
    }
}
