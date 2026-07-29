<?php

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CategoryRepositoryTest extends KernelTestCase
{
    public function testFindAllOrderedIsAlphabeticalWithChildrenUnderTheirParent(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(CategoryRepository::class);

        $logement = new Category('Zz Logement');
        $courses = new Category('Zz Courses');
        $edf = new Category('EDF', $logement);
        // Sous-catégorie qui trie avant son parent : elle doit rester dessous.
        $assurance = new Category('Assurance', $logement);
        $drive = new Category('Drive', $courses);
        foreach ([$logement, $courses, $edf, $assurance, $drive] as $category) {
            $entityManager->persist($category);
        }
        $entityManager->flush();

        $names = array_map(
            static fn (Category $category): string => $category->getFullName(),
            $repository->findAllOrdered(),
        );

        // La base de test contient aussi le seed : on ne garde que nos catégories.
        $names = array_values(array_filter(
            $names,
            static fn (string $name): bool => str_starts_with($name, 'Zz '),
        ));

        self::assertSame([
            'Zz Courses',
            'Zz Courses > Drive',
            'Zz Logement',
            'Zz Logement > Assurance',
            'Zz Logement > EDF',
        ], $names);
    }
}
