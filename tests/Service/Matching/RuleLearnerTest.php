<?php

namespace App\Tests\Service\Matching;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\CategorySource;
use App\Repository\CategorizationRuleRepository;
use App\Service\Matching\RuleLearner;
use App\Service\Normalization\LabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RuleLearnerTest extends KernelTestCase
{
    private RuleLearner $learner;
    private CategorizationRuleRepository $ruleRepository;
    private EntityManagerInterface $entityManager;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->learner = $container->get(RuleLearner::class);
        $this->ruleRepository = $container->get(CategorizationRuleRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->normalizer = $container->get(LabelNormalizer::class);
    }

    private function makeTransaction(string $label, int $amountCents, string $date = '2026-07-20'): Transaction
    {
        $operationDate = new \DateTimeImmutable($date);
        $normalized = $this->normalizer->normalize($label, $operationDate);

        $transaction = new Transaction($operationDate, $operationDate, $label, $amountCents, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    public function testFirstCategorizationCreatesSuggestingRule(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);

        $transaction = $this->makeTransaction('CARTE 09/04 CHRONO 1010 BOULIAC', -5000);

        $rule = $this->learner->learnFromCategorization($transaction, $courses);

        self::assertSame(['CHRONO', '1010', 'BOULIAC'], $rule->getTokens());
        self::assertSame(1, $rule->getConfirmations());
    }

    public function testSecondCategorizationNarrowsTokensToIntersection(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);

        $first = $this->makeTransaction('CARTE 09/04 CHRONO 1010 BOULIAC', -5000);
        $second = $this->makeTransaction('CARTE 19/01 CHRONO 1006 LE HAILLAN', -6000);

        $this->learner->learnFromCategorization($first, $courses);
        $rule = $this->learner->learnFromCategorization($second, $courses);

        self::assertSame(['CHRONO'], $rule->getTokens(), 'Le token discriminant est l\'intersection des tokens');
        self::assertSame(2, $rule->getConfirmations());
        self::assertCount(1, $this->ruleRepository->findAll(), 'Une seule règle, renforcée');
    }

    public function testCorrectionDegradesWrongRule(): void
    {
        $courses = new Category('Courses');
        $animaux = new Category('Animaux');
        $this->entityManager->persist($courses);
        $this->entityManager->persist($animaux);

        $first = $this->makeTransaction('CARTE 09/04 CHRONO 1010 BOULIAC', -5000);
        $wrongRule = $this->learner->learnFromCategorization($first, $courses);
        $confirmationsBefore = $wrongRule->getConfirmations();

        // Une transaction classée sur la foi de cette règle est corrigée par
        // l'utilisateur : la règle fautive est dégradée.
        $second = $this->makeTransaction('CARTE 01/11 CHRONOVET.FR 59290 WASQUEHA', -4500);
        $second->setCategory($courses);
        $second->setCategorySource(CategorySource::Manual);
        $second->setMatchedRule($wrongRule);

        $this->learner->learnFromCategorization($second, $animaux);

        self::assertSame(1, $wrongRule->getCorrections());
        self::assertLessThan($confirmationsBefore, $wrongRule->getConfirmations());
    }

    public function testAggregatorConflictCreatesAmountScopedRule(): void
    {
        // PayPal : même libellé, marchands sous-jacents multiples. La règle
        // générique existe ; classer un prélèvement d'un montant récurrent
        // dans une autre catégorie crée une sous-règle scopée par montant.
        $paypalATrier = new Category('PayPal à trier');
        $abonnements = new Category('Abonnements');
        $this->entityManager->persist($paypalATrier);
        $this->entityManager->persist($abonnements);

        $first = $this->makeTransaction('PRLV PayPal Europe S.a.r.l. et C', -8712);
        $this->learner->learnFromCategorization($first, $paypalATrier);

        $second = $this->makeTransaction('PRLV PayPal Europe S.a.r.l. et C', -2124);
        $spotifyRule = $this->learner->learnFromCategorization($second, $abonnements);

        self::assertSame(-2124, $spotifyRule->getAmountCents(), 'Sous-règle scopée par montant');
        self::assertCount(2, $this->ruleRepository->findAll());
    }

    public function testDifferentGraphiesWithoutCommonTokensCreateSeparateRules(): void
    {
        // Salaire : HPG → HPG SARL → APPRO AUTOMOBILES. Aucun token commun
        // entre HPG et APPRO AUTOMOBILES → deux règles pour la même catégorie.
        $revenus = new Category('Revenus');
        $this->entityManager->persist($revenus);

        $first = $this->makeTransaction('VIR HPG SARL', 250000);
        $second = $this->makeTransaction('VIR APPRO AUTOMOBILES', 255000);

        $ruleHpg = $this->learner->learnFromCategorization($first, $revenus);
        $ruleAppro = $this->learner->learnFromCategorization($second, $revenus);

        self::assertNotSame($ruleHpg, $ruleAppro);
        self::assertSame(['HPG', 'SARL'], $ruleHpg->getTokens());
        self::assertSame(['APPRO', 'AUTOMOBILES'], $ruleAppro->getTokens());
    }

    public function testConfirmSuggestionWithoutCommonTokensLearnsSeparately(): void
    {
        // La suggestion venait d'un match trop optimiste (fuzzy/périodicité)
        // sur une contrepartie différente : la valider ne doit pas polluer la
        // règle d'origine, mais créer une règle séparée.
        $logement = new Category('Logement');
        $this->entityManager->persist($logement);

        $first = $this->makeTransaction('ECH PRET 0545773921701', -27810);
        $ruleFirst = $this->learner->learnFromCategorization($first, $logement);

        $other = $this->makeTransaction('ECH PRET 0545773921703', -62367);
        $other->setSuggestedCategory($logement);
        $other->setMatchedRule($ruleFirst);

        $ruleOther = $this->learner->confirmSuggestion($other);

        self::assertNotNull($ruleOther);
        self::assertNotSame($ruleFirst, $ruleOther, 'Une règle séparée est créée');
        self::assertSame(['0545773921703'], $ruleOther->getTokens());
        self::assertSame(['0545773921701'], $ruleFirst->getTokens(), 'La règle d\'origine n\'est pas polluée');
        self::assertCount(1, $ruleFirst->getFingerprints());
    }

    public function testConfirmSuggestionReinforcesRule(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);

        $first = $this->makeTransaction('CARTE 09/04 CHRONO 1010 BOULIAC', -5000);
        $rule = $this->learner->learnFromCategorization($first, $courses);

        $suggested = $this->makeTransaction('CARTE 02/05 CHRONO 1010 BOULIAC', -4200);
        $suggested->setSuggestedCategory($courses);
        $suggested->setMatchedRule($rule);

        $this->learner->confirmSuggestion($suggested);

        self::assertSame(2, $rule->getConfirmations());
    }
}
