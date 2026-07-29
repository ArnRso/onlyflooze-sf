<?php

namespace App\Tests\Service\Recurrence;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\Direction;
use App\Enum\RecurrenceState;
use App\Repository\RecurrenceRepository;
use App\Repository\TransactionRepository;
use App\Service\Import\TransactionImporter;
use App\Service\Normalization\LabelNormalizer;
use App\Service\Recurrence\RecurrenceBackfill;
use App\Service\Recurrence\RecurrenceStatusProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

class RecurrenceLifecycleTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $transactionRepository;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->transactionRepository = $container->get(TransactionRepository::class);
        $this->normalizer = $container->get(LabelNormalizer::class);
    }

    private function statusProvider(string $today): RecurrenceStatusProvider
    {
        return new RecurrenceStatusProvider(
            static::getContainer()->get(RecurrenceRepository::class),
            $this->transactionRepository,
            new MockClock($today),
        );
    }

    private function makeAttachedOccurrence(Recurrence $recurrence, string $label, int $amountCents, string $date): Transaction
    {
        $operationDate = new \DateTimeImmutable($date);
        $normalized = $this->normalizer->normalize($label, $operationDate);

        $transaction = new Transaction($operationDate, $operationDate, $label, $amountCents, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $transaction->setRecurrence($recurrence);
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    /**
     * @return array{Recurrence, CategorizationRule}
     */
    private function makeSfrRecurrence(): array
    {
        $telecom = new Category('Abonnements & télécom');
        $rule = new CategorizationRule('SFR', $telecom, Direction::Debit);
        $rule->setTokens(['SFR']);
        $this->entityManager->persist($telecom);
        $this->entityManager->persist($rule);

        $recurrence = new Recurrence('SFR', Direction::Debit, 8, -499);
        $recurrence->setCategory($telecom);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);

        return [$recurrence, $rule];
    }

    public function testEndedRecurrenceIsNotExpectedAfterItsEnd(): void
    {
        [$recurrence] = $this->makeSfrRecurrence();
        $this->makeAttachedOccurrence($recurrence, 'PRLV SFR', -499, '2026-05-08');
        $recurrence->setEndedAt(new \DateTimeImmutable('2026-05-08'));
        $this->entityManager->flush();

        $provider = $this->statusProvider('2026-07-15');

        // Le mois de la fin reste couvert, les suivants non.
        self::assertSame(RecurrenceState::Passed, $provider->statusFor($recurrence, new \DateTimeImmutable('2026-05-01'))->state);
        self::assertSame(RecurrenceState::Ended, $provider->statusFor($recurrence, new \DateTimeImmutable('2026-07-01'))->state);

        self::assertSame([], $provider->forMonth(new \DateTimeImmutable('2026-07-01')), 'Plus attendue au dashboard');
        self::assertSame(0, $provider->remainingAmountCentsForMonth(new \DateTimeImmutable('2026-07-01')));
    }

    public function testRecurrenceIsNotExpectedBeforeItsFirstOccurrence(): void
    {
        // Cas réel : abonnement Bouygues souscrit en juin 2026 — il ne doit
        // pas apparaître « en retard » sur le dashboard de janvier.
        [$recurrence] = $this->makeSfrRecurrence();
        $this->makeAttachedOccurrence($recurrence, 'PRLV SFR', -499, '2026-06-08');
        $this->entityManager->flush();

        $provider = $this->statusProvider('2026-07-15');

        self::assertSame(RecurrenceState::NotStarted, $provider->statusFor($recurrence, new \DateTimeImmutable('2026-01-01'))->state);
        self::assertSame([], $provider->forMonth(new \DateTimeImmutable('2026-01-01')));
        self::assertSame(RecurrenceState::Passed, $provider->statusFor($recurrence, new \DateTimeImmutable('2026-06-01'))->state);
    }

    public function testLateForTwoMonthsSuggestsEnding(): void
    {
        [$recurrence] = $this->makeSfrRecurrence();
        $this->makeAttachedOccurrence($recurrence, 'PRLV SFR', -499, '2026-05-08');
        $this->entityManager->flush();

        $provider = $this->statusProvider('2026-07-15');

        // Manquante en juin ET en juillet → « semble arrêtée ».
        $julyStatus = $provider->statusFor($recurrence, new \DateTimeImmutable('2026-07-01'));
        self::assertSame(RecurrenceState::Late, $julyStatus->state);
        self::assertTrue($julyStatus->probablyEnded);

        // En retard d'un seul mois : pas encore de conclusion.
        $juneStatus = $provider->statusFor($recurrence, new \DateTimeImmutable('2026-06-01'));
        self::assertSame(RecurrenceState::Late, $juneStatus->state);
        self::assertFalse($juneStatus->probablyEnded);
    }

    public function testAmountScopedRecurrenceDoesNotStealSlotFromOtherAmounts(): void
    {
        // Deux abonnements PayPal distincts = deux récurrences dont la règle
        // est scopée par montant. Un prélèvement PayPal d'un autre montant ne
        // doit voler le slot mensuel d'aucune des deux.
        $abonnements = new Category('Abonnements & télécom');
        $rule = new CategorizationRule('PAYPAL', $abonnements, Direction::Debit);
        $rule->setTokens(['PAYPAL', 'EUROPE']);
        $rule->setAmountCents(-2124);
        $this->entityManager->persist($abonnements);
        $this->entityManager->persist($rule);

        $recurrence = new Recurrence('PayPal 21,24', Direction::Debit, 17, -2124);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();

        static::getContainer()->get(TransactionImporter::class)->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"02/07/2026";"02/07/2026";"PRLV PayPal Europe S.a.r.l. et C";"57,20";""',
            '"17/07/2026";"17/07/2026";"PRLV PayPal Europe S.a.r.l. et C";"21,24";""',
        ]), 'export.csv');

        $attached = [];
        foreach ($this->transactionRepository->findAll() as $transaction) {
            if ($transaction->getRecurrence() !== null) {
                $attached[] = $transaction->getAmountCents();
            }
        }

        self::assertSame([-2124], $attached, 'Seul le montant de l\'abonnement est rattaché');
    }

    public function testImportDoesNotAttachAfterEnd(): void
    {
        [$recurrence] = $this->makeSfrRecurrence();
        $this->makeAttachedOccurrence($recurrence, 'PRLV SFR', -499, '2026-05-08');
        $recurrence->setEndedAt(new \DateTimeImmutable('2026-05-08'));
        $this->entityManager->flush();

        static::getContainer()->get(TransactionImporter::class)->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"08/07/2026";"08/07/2026";"PRLV SFR";"4,99";""',
        ]), 'export.csv');

        $imported = $this->transactionRepository->findAllToReview()[0];
        self::assertNull($imported->getRecurrence(), 'Une récurrence terminée ne rattache plus rien');
    }

    public function testBackfillOnlyProposesBeforeEnd(): void
    {
        [$recurrence] = $this->makeSfrRecurrence();
        $recurrence->setEndedAt(new \DateTimeImmutable('2026-05-08'));

        $before = new \DateTimeImmutable('2026-04-08');
        $after = new \DateTimeImmutable('2026-07-08');
        foreach ([$before, $after] as $date) {
            $normalized = $this->normalizer->normalize('PRLV SFR', $date);
            $transaction = new Transaction($date, $date, 'PRLV SFR', -499, $normalized->type);
            $transaction->setTokens($normalized->tokens);
            $this->entityManager->persist($transaction);
        }
        $this->entityManager->flush();

        $candidates = static::getContainer()->get(RecurrenceBackfill::class)->findCandidates($recurrence);

        self::assertCount(1, $candidates);
        self::assertSame('2026-04-08', $candidates[0]->getOperationDate()->format('Y-m-d'), 'Seul l\'historique antérieur à la fin est proposé');
    }
}
