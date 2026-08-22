<?php

namespace App\Tests\Service\Review;

use App\Entity\Category;
use App\Enum\CategorySource;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use App\Service\Import\TransactionImporter;
use App\Service\Review\RuleReapplier;
use App\Service\Review\TransactionCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RuleReapplierTest extends KernelTestCase
{
    private TransactionImporter $importer;
    private TransactionCategorizer $categorizer;
    private RuleReapplier $reapplier;
    private TransactionRepository $transactionRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->importer = $container->get(TransactionImporter::class);
        $this->categorizer = $container->get(TransactionCategorizer::class);
        $this->reapplier = $container->get(RuleReapplier::class);
        $this->transactionRepository = $container->get(TransactionRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    private function importBacklog(): void
    {
        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"20/07/2026";"20/07/2026";"CARTE 19/07 CHRONO 1010 BOULIAC";"52,00";""',
            '"12/06/2026";"12/06/2026";"CARTE 11/06 CHRONO 1006 LE HAILLAN";"61,30";""',
            '"05/05/2026";"05/05/2026";"CARTE 04/05 CHRONO 1010 BOULIAC";"48,90";""',
            '"18/07/2026";"18/07/2026";"CARTE 17/07 TCHU BORDEAUX";"12,90";""',
        ]), 'export.csv');
    }

    public function testCategorizingOneSuggestsToTheRestOfTheBacklog(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        $this->importBacklog();

        // L'utilisateur trie UNE occurrence CHRONO…
        $chrono = null;
        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            if (str_contains($transaction->getLabel(), 'CHRONO 1010 BOULIAC')) {
                $chrono = $transaction;
                break;
            }
        }
        self::assertNotNull($chrono);
        $this->categorizer->categorize($chrono, $courses);

        // …et son jumeau exact du stock reçoit la suggestion, sans jamais
        // être catégorisé tout seul. (Après une seule catégorisation, les
        // tokens de la règle sont encore [CHRONO, 1010, BOULIAC] : le
        // magasin du Haillan n'est pas concerné.)
        self::assertSame(
            ['CARTE 04/05 CHRONO 1010 BOULIAC'],
            $this->suggestedLabels(),
        );

        // 2e catégorisation (l'autre magasin) : l'intersection resserre les
        // tokens à [CHRONO] — plus rien à suggérer, TCHU reste intouché.
        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            self::assertNull($transaction->getCategory(), 'Jamais de catégorisation automatique');
            self::assertSame(CategorySource::Unclassified, $transaction->getCategorySource());
        }
    }

    /**
     * @return list<string>
     */
    private function suggestedLabels(): array
    {
        $labels = [];
        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            if ($transaction->getSuggestedCategory() !== null) {
                $labels[] = $transaction->getLabel();
            }
        }
        sort($labels);

        return $labels;
    }

    public function testSecondCategorizationNarrowsRuleAndSuggestsAllVariants(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        // 4 CHRONO (2 magasins) + 1 TCHU dans le stock.
        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"20/07/2026";"20/07/2026";"CARTE 19/07 CHRONO 1010 BOULIAC";"52,00";""',
            '"12/06/2026";"12/06/2026";"CARTE 11/06 CHRONO 1006 LE HAILLAN";"61,30";""',
            '"08/06/2026";"08/06/2026";"CARTE 07/06 CHRONO 1006 LE HAILLAN";"33,10";""',
            '"05/05/2026";"05/05/2026";"CARTE 04/05 CHRONO 1010 BOULIAC";"48,90";""',
            '"18/07/2026";"18/07/2026";"CARTE 17/07 TCHU BORDEAUX";"12,90";""',
        ]), 'export.csv');

        $byLabelPart = function (string $part) {
            foreach ($this->transactionRepository->findAllToReview() as $transaction) {
                if (str_contains($transaction->getLabel(), $part)) {
                    return $transaction;
                }
            }

            return null;
        };

        $this->categorizer->categorize($byLabelPart('19/07 CHRONO 1010'), $courses);
        $this->categorizer->categorize($byLabelPart('11/06 CHRONO 1006'), $courses);

        // Tokens resserrés à [CHRONO] : TOUTES les variantes restantes sont
        // suggérées, quel que soit le magasin. TCHU reste sans suggestion.
        self::assertSame([
            'CARTE 04/05 CHRONO 1010 BOULIAC',
            'CARTE 07/06 CHRONO 1006 LE HAILLAN',
        ], $this->suggestedLabels());
    }

    public function testSuggestionFromARuleThatNoLongerMatchesIsCleared(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        $this->importBacklog();

        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            if (str_contains($transaction->getLabel(), '19/07 CHRONO 1010')) {
                $this->categorizer->categorize($transaction, $courses);
                break;
            }
        }
        self::assertSame(['CARTE 04/05 CHRONO 1010 BOULIAC'], $this->suggestedLabels());

        // La règle est élargie à [CHRONO] : le magasin du Haillan est suggéré.
        $rule = static::getContainer()->get(CategorizationRuleRepository::class)->findAll()[0];
        $rule->setTokens(['CHRONO']);
        $this->entityManager->flush();
        self::assertSame(1, $this->reapplier->reapply());
        self::assertSame(['CARTE 04/05 CHRONO 1010 BOULIAC', 'CARTE 11/06 CHRONO 1006 LE HAILLAN'], $this->suggestedLabels());

        // Puis modifiée (à la main ou par consolidation) vers un token qui ne
        // couvre plus le Haillan : sa suggestion est retirée. Le jumeau exact
        // reste suggéré par son empreinte.
        $rule->setTokens(['AUTRECHOSE']);
        $this->entityManager->flush();
        self::assertSame(1, $this->reapplier->reapply());
        self::assertSame(['CARTE 04/05 CHRONO 1010 BOULIAC'], $this->suggestedLabels());
    }

    public function testSuggestionWithoutRuleIsLeftAlone(): void
    {
        // Périodicité / remboursement : pas de règle derrière, la
        // réapplication n'a pas à les remettre en cause.
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        $this->importBacklog();
        $transaction = $this->transactionRepository->findAllToReview()[0];
        $transaction->setSuggestedCategory($courses);
        $this->entityManager->flush();

        self::assertSame(0, $this->reapplier->reapply());
        self::assertSame($courses, $transaction->getSuggestedCategory());
    }

    public function testReapplyIsIdempotent(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        $this->importBacklog();

        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            if (str_contains($transaction->getLabel(), 'CHRONO 1010 BOULIAC')) {
                $this->categorizer->categorize($transaction, $courses);
                break;
            }
        }

        self::assertSame(0, $this->reapplier->reapply(), 'Rien à mettre à jour au second passage');
    }
}
