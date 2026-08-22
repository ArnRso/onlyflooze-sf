<?php

namespace App\Tests\Service\Recurrence;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\CategorySource;
use App\Enum\Direction;
use App\Enum\RecurrenceState;
use App\Repository\RecurrenceRepository;
use App\Repository\TransactionRepository;
use App\Service\Import\TransactionImporter;
use App\Service\Normalization\LabelNormalizer;
use App\Service\Recurrence\RecurrenceDetector;
use App\Service\Recurrence\RecurrenceStatusProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

class RecurrenceFlowTest extends KernelTestCase
{
    private RecurrenceDetector $detector;
    private TransactionImporter $importer;
    private TransactionRepository $transactionRepository;
    private RecurrenceRepository $recurrenceRepository;
    private EntityManagerInterface $entityManager;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->detector = $container->get(RecurrenceDetector::class);
        $this->importer = $container->get(TransactionImporter::class);
        $this->transactionRepository = $container->get(TransactionRepository::class);
        $this->recurrenceRepository = $container->get(RecurrenceRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->normalizer = $container->get(LabelNormalizer::class);
    }

    private function makeCategorizedTransaction(
        string $label,
        int $amountCents,
        string $date,
        Category $category,
        CategorizationRule $rule,
    ): Transaction {
        $operationDate = new \DateTimeImmutable($date);
        $normalized = $this->normalizer->normalize($label, $operationDate);

        $transaction = new Transaction($operationDate, $operationDate, $label, $amountCents, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $transaction->setCategory($category);
        $transaction->setCategorySource(CategorySource::Manual);
        $transaction->setMatchedRule($rule);
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    /**
     * @return array{Category, CategorizationRule}
     */
    private function makeEdfRule(): array
    {
        $logement = new Category('Logement');
        $rule = new CategorizationRule('EDF', $logement, Direction::Debit);
        $rule->setTokens(['EDF']);
        $rule->recordConfirmation();
        $rule->recordConfirmation();
        $this->entityManager->persist($logement);
        $this->entityManager->persist($rule);

        return [$logement, $rule];
    }

    public function testMonthlyOccurrencesProduceSuggestion(): void
    {
        [$logement, $rule] = $this->makeEdfRule();
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8400, '2026-05-21', $logement, $rule);
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8800, '2026-06-21', $logement, $rule);
        $this->entityManager->flush();

        $suggestions = $this->detector->suggest();

        self::assertCount(1, $suggestions);
        $suggestion = $suggestions[0];
        self::assertSame($rule->getId(), $suggestion->rule->getId());
        self::assertSame(21, $suggestion->expectedDayOfMonth);
        self::assertSame(-8600, $suggestion->expectedAmountCents, 'Montant attendu = moyenne des dernières occurrences');
        self::assertSame(2, $suggestion->getOccurrenceCount());
        self::assertSame(
            ['2026-05-21', '2026-06-21'],
            array_map(static fn ($t) => $t->getOperationDate()->format('Y-m-d'), $suggestion->transactions),
            'Les occurrences observées sont exposées pour vérification',
        );
    }

    public function testMultipleLoansOnSameDayProduceSeparateSuggestions(): void
    {
        // Cas réel : trois échéances de prêt immobilier prélevées le même
        // jour. Chaque numéro de prêt doit produire sa propre proposition,
        // avec son propre montant — jamais une moyenne des trois.
        $logement = new Category('Logement');
        $this->entityManager->persist($logement);
        $this->entityManager->flush();

        $categorizer = static::getContainer()->get(\App\Service\Review\TransactionCategorizer::class);

        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"04/06/2026";"04/06/2026";"ECH PRET 0545773921701";"278,10";""',
            '"04/06/2026";"04/06/2026";"ECH PRET 0545773921702";"58,24";""',
            '"04/06/2026";"04/06/2026";"ECH PRET 0545773921703";"623,67";""',
            '"04/07/2026";"04/07/2026";"ECH PRET 0545773921701";"278,10";""',
            '"04/07/2026";"04/07/2026";"ECH PRET 0545773921702";"58,24";""',
            '"04/07/2026";"04/07/2026";"ECH PRET 0545773921703";"623,67";""',
        ]), 'export.csv');

        // L'utilisateur trie tout (validation de suggestion ou choix manuel).
        while (($toReview = $this->transactionRepository->findAllToReview()) !== []) {
            $categorizer->categorize($toReview[0], $logement);
        }

        $suggestions = $this->detector->suggest();

        $byName = [];
        foreach ($suggestions as $suggestion) {
            $byName[$suggestion->name] = $suggestion;
        }
        ksort($byName);

        self::assertSame(
            ['0545773921701', '0545773921702', '0545773921703'],
            array_keys($byName),
            'Une proposition par prêt, pas une proposition fourre-tout',
        );
        self::assertSame(-27810, $byName['0545773921701']->expectedAmountCents);
        self::assertSame(-5824, $byName['0545773921702']->expectedAmountCents);
        self::assertSame(-62367, $byName['0545773921703']->expectedAmountCents);

        foreach ($byName as $suggestion) {
            self::assertSame(4, $suggestion->expectedDayOfMonth);
            self::assertSame(2, $suggestion->getOccurrenceCount());
        }
    }

    public function testCloseOccurrencesAreNotSuggested(): void
    {
        [$logement, $rule] = $this->makeEdfRule();
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8400, '2026-06-18', $logement, $rule);
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8800, '2026-06-21', $logement, $rule);
        $this->entityManager->flush();

        self::assertSame([], $this->detector->suggest(), 'Deux occurrences à 3 jours d\'écart ne font pas une récurrence mensuelle');
    }

    public function testCardTransactionsAreNotWatched(): void
    {
        $courses = new Category('Courses');
        $rule = new CategorizationRule('CHRONO', $courses, Direction::Debit);
        $rule->setTokens(['CHRONO']);
        $this->entityManager->persist($courses);
        $this->entityManager->persist($rule);
        $this->makeCategorizedTransaction('CARTE 09/04 CHRONO 1010 BOULIAC', -5000, '2026-04-10', $courses, $rule);
        $this->makeCategorizedTransaction('CARTE 09/05 CHRONO 1010 BOULIAC', -5200, '2026-05-10', $courses, $rule);
        $this->entityManager->flush();

        self::assertSame([], $this->detector->suggest(), 'Seuls PRLV/ECH PRET/F et les crédits VIR sont surveillés');
    }

    public function testPromoteCreatesRecurrenceAndAttachesHistory(): void
    {
        [$logement, $rule] = $this->makeEdfRule();
        $first = $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8400, '2026-05-21', $logement, $rule);
        $second = $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8800, '2026-06-21', $logement, $rule);
        $this->entityManager->flush();

        $recurrence = $this->detector->promote($this->detector->suggest()[0]);

        self::assertSame($logement->getId(), $recurrence->getCategory()?->getId());
        self::assertSame($rule->getId(), $recurrence->getRule()?->getId());
        self::assertSame($recurrence->getId(), $first->getRecurrence()?->getId());
        self::assertSame($recurrence->getId(), $second->getRecurrence()?->getId());

        self::assertSame([], $this->detector->suggest(), 'Une règle promue n\'est plus proposée');
    }

    public function testUncategorizedMonthlyDebitsAreSuggestedAndGraphiesMerge(): void
    {
        // Cas réel : 33 prélèvements de la Régie de l'eau, jamais triés, sous
        // deux graphies. Ils doivent être proposés (sans attendre un tri) et
        // ne faire qu'UNE proposition : même tête de libellé.
        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"10/03/2026";"10/03/2026";"PRLV REGIE DE L EAU DE BORDEAUX";"31,00";""',
            '"10/04/2026";"10/04/2026";"PRLV REGIE DE L EAU DE BORDEAUX";"31,00";""',
            '"10/05/2026";"10/05/2026";"PRLV REGIE EAU BORDEAUX METROPOLE";"34,00";""',
            '"10/06/2026";"10/06/2026";"PRLV REGIE EAU BORDEAUX METROPOLE";"34,00";""',
        ]), 'export.csv');

        $suggestions = $this->detector->suggest();

        self::assertCount(1, $suggestions);
        $suggestion = $suggestions[0];
        self::assertNull($suggestion->rule, 'Aucune règle : rien n\'a été trié');
        self::assertNull($suggestion->category);
        self::assertSame(4, $suggestion->getOccurrenceCount());
        self::assertSame('REGIE', $suggestion->headToken);
        self::assertSame(['REGIE', 'EAU', 'BORDEAUX'], $suggestion->tokens, 'Tokens communs aux deux graphies');

        $recurrence = $this->detector->promote($suggestion);

        self::assertSame(['REGIE', 'EAU', 'BORDEAUX'], $recurrence->getTokens());
        self::assertCount(4, $this->transactionRepository->findBy(['recurrence' => $recurrence]), 'Tout l\'historique est rattaché');

        // Le mois suivant, le libellé est reconnu par les tokens de la
        // récurrence, sans règle.
        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"10/07/2026";"10/07/2026";"PRLV REGIE EAU BORDEAUX METROPOLE";"37,00";""',
        ]), 'export2.csv');
        self::assertCount(5, $this->transactionRepository->findBy(['recurrence' => $recurrence]));
    }

    public function testAggregatorAmountsProduceSeparateSuggestionsAndDismissIsPerAmount(): void
    {
        // PayPal : un seul libellé, deux abonnements (9,99 et 21,24). Deux
        // propositions ; en écarter une ne fait pas taire l'autre.
        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"02/05/2026";"02/05/2026";"PRLV PayPal Europe S.a.r.l. et C";"9,99";""',
            '"17/05/2026";"17/05/2026";"PRLV PayPal Europe S.a.r.l. et C";"21,24";""',
            '"02/06/2026";"02/06/2026";"PRLV PayPal Europe S.a.r.l. et C";"9,99";""',
            '"17/06/2026";"17/06/2026";"PRLV PayPal Europe S.a.r.l. et C";"21,24";""',
        ]), 'export.csv');

        $suggestions = $this->detector->suggest();
        $amounts = array_map(static fn ($s) => $s->expectedAmountCents, $suggestions);
        sort($amounts);
        self::assertSame([-2124, -999], $amounts, 'Une proposition par montant, jamais une moyenne fourre-tout');

        $this->detector->dismiss($suggestions[0]);

        $remaining = $this->detector->suggest();
        self::assertCount(1, $remaining);
        self::assertNotSame($suggestions[0]->expectedAmountCents, $remaining[0]->expectedAmountCents);
    }

    public function testHistoryOfATrackedRecurrenceIsNotProposedAgain(): void
    {
        // Des occurrences non rattachées d'une récurrence déjà suivie
        // relèvent de la recherche rétroactive, pas d'une nouvelle proposition.
        [$logement, $rule] = $this->makeEdfRule();
        $recurrence = new Recurrence('EDF', Direction::Debit, 21, -8600);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8400, '2025-05-21', $logement, $rule);
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8800, '2025-06-21', $logement, $rule);
        $this->entityManager->flush();

        self::assertSame([], $this->detector->suggest());
    }

    public function testDismissedSuggestionIsNotRepeated(): void
    {
        [$logement, $rule] = $this->makeEdfRule();
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8400, '2026-05-21', $logement, $rule);
        $this->makeCategorizedTransaction('PRLV EDF clients particuliers', -8800, '2026-06-21', $logement, $rule);
        $this->entityManager->flush();

        $this->detector->dismiss($this->detector->suggest()[0]);

        self::assertSame([], $this->detector->suggest());
    }

    public function testImportAttachesToRecurrenceAndRefreshesExpectedAmount(): void
    {
        [$logement, $rule] = $this->makeEdfRule();
        $recurrence = new Recurrence('EDF', Direction::Debit, 21, -8600);
        $recurrence->setCategory($logement);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();

        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"21/07/2026";"21/07/2026";"PRLV EDF clients particuliers";"84,09";""',
        ]), 'export.csv');

        $transaction = $this->transactionRepository->findAll()[0];
        self::assertSame($recurrence->getId(), $transaction->getRecurrence()?->getId());
        self::assertFalse($transaction->isAmountOutOfTolerance());
        self::assertSame(-8409, $recurrence->getExpectedAmountCents(), 'Montant attendu recalé sur les dernières occurrences');
    }

    public function testOutOfToleranceAmountIsAttachedButFlagged(): void
    {
        // Cas réel : DGFIP passé de 242 € mensuel à 2 278 €.
        $impots = new Category('Impôts');
        $rule = new CategorizationRule('DGFIP', $impots, Direction::Debit);
        $rule->setTokens(['DGFIP']);
        $rule->recordConfirmation();
        $rule->recordConfirmation();
        $this->entityManager->persist($impots);
        $this->entityManager->persist($rule);

        $recurrence = new Recurrence('DGFIP', Direction::Debit, 27, -24200);
        $recurrence->setCategory($impots);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();

        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"27/07/2026";"27/07/2026";"PRLV DGFIP FINANCES PUBLIQUES";"2 278,00";""',
        ]), 'export.csv');

        $transaction = $this->transactionRepository->findAll()[0];
        self::assertSame($recurrence->getId(), $transaction->getRecurrence()?->getId(), 'Rattachée malgré l\'écart');
        self::assertTrue($transaction->isAmountOutOfTolerance(), 'Mais signalée');
        self::assertSame(-24200, $recurrence->getExpectedAmountCents(), 'Une anomalie signalée ne recale pas le montant attendu');
    }

    public function testExpectedAmountRecoversAfterAnomalousMonth(): void
    {
        // Rattrapage un mois (signalé), puis retour au montant normal : la
        // moyenne glissante ignore l'anomalie et suit la vraie normale.
        $sante = new Category('Santé');
        $rule = new CategorizationRule('RADIANCE MUTUELLE', $sante, Direction::Debit);
        $rule->setTokens(['RADIANCE', 'MUTUELLE']);
        $this->entityManager->persist($sante);
        $this->entityManager->persist($rule);

        $recurrence = new Recurrence('Mutuelle', Direction::Debit, 5, -6722);
        $recurrence->setCategory($sante);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();

        $header = '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"';

        $this->importer->import(implode("\n", [
            $header,
            '"05/04/2026";"05/04/2026";"PRLV RADIANCE MUTUELLE PLEIADE";"201,66";""',
        ]), 'export1.csv');

        self::assertSame(-6722, $recurrence->getExpectedAmountCents(), 'Le rattrapage n\'a pas déréglé l\'attendu');

        $this->importer->import(implode("\n", [
            $header,
            '"05/05/2026";"05/05/2026";"PRLV RADIANCE MUTUELLE";"69,88";""',
        ]), 'export2.csv');

        $normal = null;
        foreach ($this->transactionRepository->findAll() as $transaction) {
            if ($transaction->getAmountCents() === -6988) {
                $normal = $transaction;
            }
        }

        self::assertNotNull($normal);
        self::assertSame($recurrence->getId(), $normal->getRecurrence()?->getId());
        self::assertFalse($normal->isAmountOutOfTolerance(), 'Le mois normal suivant n\'est pas signalé à tort');
        self::assertSame(-6988, $recurrence->getExpectedAmountCents(), 'L\'attendu suit la normale, pas l\'anomalie');
    }

    public function testTwoConsecutiveOccurrencesAtANewLevelAreAdoptedAsTheNewNormal(): void
    {
        // Cas réel : EDF 84,09 → 100,13 → 100,13. Le premier mois est signalé
        // (anomalie possible), le second confirme un palier : plus rien n'est
        // signalé et l'attendu suit — sinon chaque mois resterait « hors
        // tolérance » face à un attendu figé.
        [$logement, $rule] = $this->makeEdfRule();
        $recurrence = new Recurrence('EDF', Direction::Debit, 21, -8409);
        $recurrence->setCategory($logement);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();

        $header = '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"';
        $this->importer->import(implode("\n", [$header, '"21/01/2025";"21/01/2025";"PRLV EDF clients particuliers";"100,13";""']), 'jan.csv');

        $january = $this->transactionRepository->findAll()[0];
        self::assertTrue($january->isAmountOutOfTolerance(), 'Premier mois : signalé');
        self::assertSame(-8409, $recurrence->getExpectedAmountCents());

        $this->importer->import(implode("\n", [$header, '"21/02/2025";"21/02/2025";"PRLV EDF clients particuliers";"100,13";""']), 'feb.csv');

        $byDate = [];
        foreach ($this->transactionRepository->findAll() as $transaction) {
            $byDate[$transaction->getOperationDate()->format('Y-m')] = $transaction;
        }
        self::assertFalse($byDate['2025-02']->isAmountOutOfTolerance(), 'Second mois au même niveau : nouveau palier');
        self::assertFalse($byDate['2025-01']->isAmountOutOfTolerance(), 'Le signalement du premier mois s\'efface');
        self::assertSame(-10013, $recurrence->getExpectedAmountCents(), 'L\'attendu adopte le palier');
    }

    public function testRecurrenceLearnsAChangedLabel(): void
    {
        // Le libellé change complètement : le premier mois est rattaché par
        // date + montant ; dès lors la récurrence connaît ce libellé et le
        // reconnaît même si le montant bouge le mois suivant.
        $logement = new Category('Logement');
        $this->entityManager->persist($logement);
        $recurrence = new Recurrence('Eau', Direction::Debit, 10, -3100);
        $recurrence->setCategory($logement);
        $recurrence->setTokens(['REGIE', 'EAU']);
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();

        $header = '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"';
        $this->importer->import(implode("\n", [$header, '"10/06/2026";"10/06/2026";"PRLV SUEZ EAU FRANCE";"31,00";""']), 'jun.csv');
        self::assertContains('prelevement|SUEZ EAU FRANCE', $recurrence->getFingerprints(), 'Le nouveau libellé est appris');

        $this->importer->import(implode("\n", [$header, '"10/07/2026";"10/07/2026";"PRLV SUEZ EAU FRANCE";"45,00";""']), 'jul.csv');

        $attached = array_filter($this->transactionRepository->findAll(), static fn (Transaction $t): bool => $t->getRecurrence() !== null);
        self::assertCount(2, $attached, 'Reconnu par son libellé appris malgré le montant hors tolérance');
    }

    public function testRecurrenceWithoutRuleMatchesOnDateAndAmountWindow(): void
    {
        // Création manuelle a priori : pas de règle, fenêtre date + tolérance.
        $logement = new Category('Logement');
        $this->entityManager->persist($logement);
        $recurrence = new Recurrence('Loyer', Direction::Debit, 5, -65000);
        $recurrence->setCategory($logement);
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();

        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"04/07/2026";"04/07/2026";"VIR vers AGENCE IMMO XYZ";"655,00";""',
            '"15/07/2026";"15/07/2026";"CARTE 14/07 GRAND ACHAT MEUBLES";"650,00";""',
        ]), 'export.csv');

        $attached = array_filter(
            $this->transactionRepository->findAll(),
            static fn (Transaction $t): bool => $t->getRecurrence() !== null,
        );

        self::assertCount(1, $attached, 'Seule la transaction dans la fenêtre de date est rattachée');
        self::assertSame('VIR vers AGENCE IMMO XYZ', array_values($attached)[0]->getLabel());
    }

    public function testStatusProviderStates(): void
    {
        $logement = new Category('Logement');
        $this->entityManager->persist($logement);

        $passed = new Recurrence('EDF', Direction::Debit, 5, -8400);
        $upcoming = new Recurrence('Prêt', Direction::Debit, 25, -55000);
        $late = new Recurrence('Mutuelle', Direction::Debit, 8, -4200);
        foreach ([$passed, $upcoming, $late] as $recurrence) {
            $this->entityManager->persist($recurrence);
        }

        $operationDate = new \DateTimeImmutable('2026-07-05');
        $transaction = new Transaction($operationDate, $operationDate, 'PRLV EDF clients particuliers', -8400, \App\Enum\TransactionType::Prelevement);
        $transaction->setRecurrence($passed);
        $this->entityManager->persist($transaction);

        // Une récurrence n'est attendue qu'à partir de sa première occurrence
        // (ou de sa création, qui est « aujourd'hui » en test) : une
        // occurrence en juin rend Prêt et Mutuelle attendues en juillet, quel
        // que soit le jour où le test tourne.
        foreach ([[$upcoming, '2026-06-25', -55000], [$late, '2026-06-08', -4200]] as [$recurrence, $date, $amount]) {
            $previousDate = new \DateTimeImmutable($date);
            $previous = new Transaction($previousDate, $previousDate, 'PRLV '.$recurrence->getName(), $amount, \App\Enum\TransactionType::Prelevement);
            $previous->setRecurrence($recurrence);
            $this->entityManager->persist($previous);
        }
        $this->entityManager->flush();

        $provider = new RecurrenceStatusProvider(
            $this->recurrenceRepository,
            $this->transactionRepository,
            new MockClock('2026-07-15'),
        );

        $statuses = [];
        foreach ($provider->forMonth(new \DateTimeImmutable('2026-07-01')) as $status) {
            $statuses[$status->recurrence->getName()] = $status;
        }

        self::assertSame(RecurrenceState::Passed, $statuses['EDF']->state);
        self::assertNotNull($statuses['EDF']->transaction);
        self::assertSame(RecurrenceState::Upcoming, $statuses['Prêt']->state);
        self::assertSame(RecurrenceState::Late, $statuses['Mutuelle']->state);

        self::assertSame(
            -55000 + -4200,
            $provider->remainingAmountCentsForMonth(new \DateTimeImmutable('2026-07-01')),
            'Reste à passer = à venir + en retard',
        );
    }
}
