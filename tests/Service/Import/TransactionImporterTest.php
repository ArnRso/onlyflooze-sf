<?php

namespace App\Tests\Service\Import;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Enum\CategorySource;
use App\Enum\Direction;
use App\Repository\TransactionRepository;
use App\Service\Import\TransactionImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransactionImporterTest extends KernelTestCase
{
    private TransactionImporter $importer;
    private TransactionRepository $transactionRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->importer = $container->get(TransactionImporter::class);
        $this->transactionRepository = $container->get(TransactionRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    private const string HEADER = '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"';

    private function csv(string ...$lines): string
    {
        return implode("\n", [self::HEADER, ...$lines]);
    }

    public function testImportNominal(): void
    {
        $batch = $this->importer->import($this->csv(
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"12,90";""',
            '"23/07/2026";"23/07/2026";"CARTE 22/07 CARREFOUR HYPER LORMONT";"83,43";""',
        ), 'export.csv');

        self::assertSame(2, $batch->getNewCount());
        self::assertSame(0, $batch->getDuplicateCount());
        self::assertSame(2, $batch->getToReviewCount());
        self::assertSame(2, $this->transactionRepository->countToReview());
    }

    public function testOverlappingImportsInsertOnlyDelta(): void
    {
        $this->importer->import($this->csv(
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"12,90";""',
            '"23/07/2026";"23/07/2026";"CARTE 22/07 CARREFOUR HYPER LORMONT";"83,43";""',
        ), 'export1.csv');

        // Le second export chevauche le premier : une ligne connue, une nouvelle.
        $batch = $this->importer->import($this->csv(
            '"25/07/2026";"25/07/2026";"CARTE 24/07 LIDL CENON";"25,10";""',
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"12,90";""',
        ), 'export2.csv');

        self::assertSame(1, $batch->getNewCount());
        self::assertSame(1, $batch->getDuplicateCount());
        self::assertCount(3, $this->transactionRepository->findAll());
    }

    public function testLegitimateDuplicatesAreCounted(): void
    {
        // Deux achats identiques le même jour : cas réel, PAS un doublon.
        $batch = $this->importer->import($this->csv(
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TABAC LE CELTIQUE CENON";"10,50";""',
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TABAC LE CELTIQUE CENON";"10,50";""',
        ), 'export.csv');

        self::assertSame(2, $batch->getNewCount());
        self::assertSame(0, $batch->getDuplicateCount());
    }

    public function testLegitimateDuplicatesWithOverlap(): void
    {
        // La base contient déjà 1 occurrence ; l'import en contient 2 → on
        // insère 2−1 = 1 (dédoublonnage par comptage, jamais par hash unique).
        $this->importer->import($this->csv(
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TABAC LE CELTIQUE CENON";"10,50";""',
        ), 'export1.csv');

        $batch = $this->importer->import($this->csv(
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TABAC LE CELTIQUE CENON";"10,50";""',
            '"24/07/2026";"24/07/2026";"CARTE 23/07 TABAC LE CELTIQUE CENON";"10,50";""',
        ), 'export2.csv');

        self::assertSame(1, $batch->getNewCount());
        self::assertSame(1, $batch->getDuplicateCount());
        self::assertCount(2, $this->transactionRepository->findAll());
    }

    public function testConfirmedRuleOnlySuggests(): void
    {
        // Même très confirmée, une règle ne catégorise jamais toute seule :
        // elle pré-remplit une suggestion à valider en un clic.
        $courses = new Category('Courses');
        $rule = new CategorizationRule('CARREFOUR', $courses, Direction::Debit);
        $rule->setTokens(['CARREFOUR']);
        $rule->recordConfirmation();
        $rule->recordConfirmation();
        $this->entityManager->persist($courses);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $batch = $this->importer->import($this->csv(
            '"23/07/2026";"23/07/2026";"CARTE 22/07 CARREFOUR HYPER LORMONT";"83,43";""',
        ), 'export.csv');

        self::assertSame(1, $batch->getSuggestedCount());
        self::assertSame(0, $batch->getToReviewCount());

        $transaction = $this->transactionRepository->findAll()[0];
        self::assertNull($transaction->getCategory());
        self::assertSame(CategorySource::Unclassified, $transaction->getCategorySource());
        self::assertSame($courses->getId(), $transaction->getSuggestedCategory()?->getId());
        self::assertNotNull($transaction->getMatchedRule());
    }

    public function testFreshRuleOnlySuggests(): void
    {
        $courses = new Category('Courses');
        $rule = new CategorizationRule('CARREFOUR', $courses, Direction::Debit);
        $rule->setTokens(['CARREFOUR']);
        $rule->recordConfirmation();
        $this->entityManager->persist($courses);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $batch = $this->importer->import($this->csv(
            '"23/07/2026";"23/07/2026";"CARTE 22/07 CARREFOUR HYPER LORMONT";"83,43";""',
        ), 'export.csv');

        self::assertSame(1, $batch->getSuggestedCount());

        $transaction = $this->transactionRepository->findAll()[0];
        self::assertNull($transaction->getCategory());
        self::assertSame($courses->getId(), $transaction->getSuggestedCategory()?->getId());
        self::assertTrue($transaction->isToReview());
    }

    public function testRuleScopedByDirection(): void
    {
        // Cas réel Radiance Mutuelle : cotisation en débit, remboursements en
        // crédit. Une règle débit ne matche pas un crédit.
        $sante = new Category('Santé');
        $rule = new CategorizationRule('RADIANCE MUTUELLE', $sante, Direction::Debit);
        $rule->setTokens(['RADIANCE', 'MUTUELLE']);
        $rule->recordConfirmation();
        $rule->recordConfirmation();
        $this->entityManager->persist($sante);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $batch = $this->importer->import($this->csv(
            '"24/07/2026";"24/07/2026";"VIR RADIANCE MUTUELLE";"";"29,98"',
        ), 'export.csv');

        self::assertSame(0, $batch->getSuggestedCount());
        self::assertSame(1, $batch->getToReviewCount());
    }

    public function testPeriodicityMatchSuggestsAcrossLabelDrift(): void
    {
        // Dérive réelle DGFIP : deux graphies sans token commun. Le niveau 4
        // (montant + périodicité) doit raccrocher et suggérer, jamais
        // auto-appliquer.
        $impots = new Category('Impôts');
        $this->entityManager->persist($impots);
        $this->entityManager->flush();

        $this->importer->import($this->csv(
            '"27/05/2026";"27/05/2026";"PRLV DIRECTION GENERALE DES FINA";"242,00";""',
        ), 'export1.csv');

        foreach ($this->transactionRepository->findAll() as $transaction) {
            $transaction->setCategory($impots);
            $transaction->setCategorySource(CategorySource::Manual);
        }
        $this->entityManager->flush();

        $batch = $this->importer->import($this->csv(
            '"27/06/2026";"27/06/2026";"PRLV DGFIP FINANCES PUBLIQUES";"242,00";""',
        ), 'export2.csv');

        self::assertSame(1, $batch->getSuggestedCount());

        $new = $this->transactionRepository->findToReview(10)[0];
        self::assertSame($impots->getId(), $new->getSuggestedCategory()?->getId());
        self::assertNull($new->getCategory());
    }

    public function testImportSummaryCounts(): void
    {
        $courses = new Category('Courses');
        $rule = new CategorizationRule('LIDL', $courses, Direction::Debit);
        $rule->setTokens(['LIDL']);
        $rule->recordConfirmation();
        $rule->recordConfirmation();
        $this->entityManager->persist($courses);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $this->importer->import($this->csv(
            '"20/07/2026";"20/07/2026";"CARTE 19/07 EURASIE BORDEAUX";"45,00";""',
        ), 'export1.csv');

        $batch = $this->importer->import($this->csv(
            '"21/07/2026";"21/07/2026";"CARTE 20/07 LIDL CENON";"30,00";""',
            '"20/07/2026";"20/07/2026";"CARTE 19/07 EURASIE BORDEAUX";"45,00";""',
            '"19/07/2026";"19/07/2026";"CARTE 18/07 TCHU BORDEAUX";"12,90";""',
        ), 'export2.csv');

        self::assertSame(2, $batch->getNewCount());
        self::assertSame(1, $batch->getDuplicateCount());
        self::assertSame(1, $batch->getSuggestedCount());
        self::assertSame(1, $batch->getToReviewCount());
    }
}
