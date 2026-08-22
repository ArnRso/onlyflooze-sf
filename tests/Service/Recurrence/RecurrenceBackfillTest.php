<?php

namespace App\Tests\Service\Recurrence;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\Direction;
use App\Service\Normalization\LabelNormalizer;
use App\Service\Recurrence\RecurrenceBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RecurrenceBackfillTest extends KernelTestCase
{
    private RecurrenceBackfill $backfill;
    private EntityManagerInterface $entityManager;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->backfill = $container->get(RecurrenceBackfill::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->normalizer = $container->get(LabelNormalizer::class);
    }

    private function makeTransaction(string $label, int $amountCents, string $date): Transaction
    {
        $operationDate = new \DateTimeImmutable($date);
        $normalized = $this->normalizer->normalize($label, $operationDate);

        $transaction = new Transaction($operationDate, $operationDate, $label, $amountCents, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    private function makeEdfRecurrence(): Recurrence
    {
        $logement = new Category('Logement');
        $rule = new CategorizationRule('EDF', $logement, Direction::Debit);
        $rule->setTokens(['EDF']);
        $this->entityManager->persist($logement);
        $this->entityManager->persist($rule);

        $recurrence = new Recurrence('EDF', Direction::Debit, 21, -8600);
        $recurrence->setCategory($logement);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);

        return $recurrence;
    }

    public function testFindCandidatesScansWholeHistory(): void
    {
        $recurrence = $this->makeEdfRecurrence();

        // Tout l'historique EDF non rattaché matche (via la règle), même les
        // vieux mois ; une transaction hors fenêtre ET hors règle, non.
        // (NB : même sans matcher la règle, une ligne dans la fenêtre de date
        // avec un montant proche serait proposée — c'est le repli qui rattrape
        // les libellés qui dérivent, et c'est l'utilisateur qui tranche.)
        $this->makeTransaction('PRLV EDF clients particuliers', -7633, '2023-09-21');
        $this->makeTransaction('PRLV EDF clients particuliers', -8800, '2026-06-21');
        $this->makeTransaction('CARTE 09/06 LIDL CENON', -3000, '2026-06-10');
        $attached = $this->makeTransaction('PRLV EDF clients particuliers', -8409, '2026-07-21');
        $attached->setRecurrence($recurrence);
        $this->entityManager->flush();

        $candidates = $this->backfill->findCandidates($recurrence);

        $labels = array_map(static fn (Transaction $t): string => $t->getOperationDate()->format('Y-m-d'), $candidates);
        sort($labels);
        self::assertSame(['2023-09-21', '2026-06-21'], $labels, 'Historique complet, sans les déjà-rattachées ni le hors-sujet');
    }

    public function testAttachDoesNotFlagHistoricalAmounts(): void
    {
        // Une mensualité historique (99 € de gaz l'hiver) ne doit pas être
        // signalée « hors tolérance » face à l'attendu ACTUEL : l'utilisateur
        // valide en voyant l'écart, le flag n'a de sens qu'au fil de l'eau.
        $recurrence = $this->makeEdfRecurrence();
        $spike = $this->makeTransaction('PRLV EDF clients particuliers', -20000, '2026-03-21');
        $this->entityManager->flush();

        $this->backfill->attach($recurrence, $spike);

        self::assertSame($recurrence->getId(), $spike->getRecurrence()?->getId());
        self::assertFalse($spike->isAmountOutOfTolerance());
    }

    public function testExcludedTransactionIsNeverProposedAgain(): void
    {
        $recurrence = $this->makeEdfRecurrence();
        $transaction = $this->makeTransaction('PRLV EDF clients particuliers', -8800, '2026-06-21');
        $this->entityManager->flush();

        self::assertCount(1, $this->backfill->findCandidates($recurrence));

        $this->backfill->exclude($recurrence, $transaction);

        self::assertSame([], $this->backfill->findCandidates($recurrence));
        self::assertNull($transaction->getRecurrence());
    }

    public function testDetachRemovesAndNeverReproposes(): void
    {
        $recurrence = $this->makeEdfRecurrence();
        $transaction = $this->makeTransaction('PRLV EDF clients particuliers', -20000, '2026-03-21');
        $this->entityManager->flush();

        $this->backfill->attach($recurrence, $transaction);
        $transaction->setAmountOutOfTolerance(true);

        $this->backfill->detach($recurrence, $transaction);

        self::assertNull($transaction->getRecurrence());
        self::assertFalse($transaction->isAmountOutOfTolerance(), 'Le signalement d\'écart tombe avec le rattachement');
        self::assertSame([], $this->backfill->findCandidates($recurrence), 'Une transaction détachée n\'est jamais reproposée');
    }

    public function testToleranceFollowsTheNearestAttachedOccurrence(): void
    {
        // Cas réel : Bouygues à 39,99 € pendant deux ans, puis 24,99 €.
        // L'attendu courant (24,99) refuserait tout l'historique ; comparé à
        // l'occurrence rattachée la plus proche dans le temps, il passe.
        $recurrence = new Recurrence('Loyer', Direction::Debit, 5, -2499);
        $this->entityManager->persist($recurrence);

        $oldAttached = $this->makeTransaction('VIR vers BAILLEUR', -3999, '2024-06-05');
        $oldAttached->setRecurrence($recurrence);
        $recentAttached = $this->makeTransaction('VIR vers BAILLEUR', -2499, '2026-06-05');
        $recentAttached->setRecurrence($recurrence);

        $oldCandidate = $this->makeTransaction('VIR vers BAILLEUR', -3999, '2024-07-05');
        $this->makeTransaction('VIR vers BAILLEUR', -3999, '2026-07-05');
        $this->entityManager->flush();

        $candidates = $this->backfill->findCandidates($recurrence);

        self::assertCount(1, $candidates);
        self::assertSame($oldCandidate->getId(), $candidates[0]->getId(), 'Le montant de 2024 est jugé face à l\'occurrence de 2024, pas face à l\'attendu de 2026');
    }

    public function testRecurrenceTokensMatchWholeHistoryRegardlessOfAmount(): void
    {
        $recurrence = new Recurrence('REGIE EAU', Direction::Debit, 10, -3400);
        $recurrence->setTokens(['REGIE', 'EAU']);
        $this->entityManager->persist($recurrence);

        $this->makeTransaction('PRLV REGIE DE L EAU DE BORDEAUX', -12637, '2023-09-10');
        $this->makeTransaction('PRLV REGIE EAU BORDEAUX METROPOLE', -3100, '2025-03-10');
        $this->makeTransaction('PRLV EDF clients particuliers', -8400, '2025-03-10');
        $this->entityManager->flush();

        $dates = array_map(static fn (Transaction $t): string => $t->getOperationDate()->format('Y-m-d'), $this->backfill->findCandidates($recurrence));
        sort($dates);
        self::assertSame(['2023-09-10', '2025-03-10'], $dates, 'Les deux graphies, même la facture annuelle hors tolérance ; pas EDF');
    }

    public function testDateAndAmountFallbackNeverCrossesTransactionTypes(): void
    {
        // Cotisation bancaire (F) à ~10 € le 8 : les achats carte à 10 € le
        // 8 ne sont pas des occurrences, quel que soit le montant.
        $recurrence = new Recurrence('Cotisation', Direction::Debit, 8, -1000);
        $this->entityManager->persist($recurrence);
        $attached = $this->makeTransaction('F COTISATION EUROCOMPTE 06/26', -1000, '2026-06-08');
        $attached->setRecurrence($recurrence);

        $fee = $this->makeTransaction('F COTISATION EUROCOMPTE 05/26', -1000, '2026-05-08');
        $this->makeTransaction('CARTE 07/05 BOUL LA CASSAGNE CENON', -1000, '2026-05-08');
        $this->entityManager->flush();

        $candidates = $this->backfill->findCandidates($recurrence);

        self::assertCount(1, $candidates);
        self::assertSame($fee->getId(), $candidates[0]->getId());
    }

    public function testDateWindowFallbackWithoutRule(): void
    {
        // Récurrence créée à la main, sans règle : fenêtre de date + tolérance.
        $recurrence = new Recurrence('Loyer', Direction::Debit, 5, -65000);
        $this->entityManager->persist($recurrence);

        $inWindow = $this->makeTransaction('VIR vers AGENCE IMMO XYZ', -65500, '2026-06-04');
        $this->makeTransaction('VIR vers AGENCE IMMO XYZ', -65500, '2026-06-15');
        $this->makeTransaction('CARTE 04/06 GROS ACHAT', -64000, '2026-06-20');
        $this->entityManager->flush();

        $candidates = $this->backfill->findCandidates($recurrence);

        self::assertCount(1, $candidates);
        self::assertSame($inWindow->getId(), $candidates[0]->getId());
    }
}
