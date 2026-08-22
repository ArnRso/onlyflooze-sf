<?php

namespace App\Tests\Service\Matching;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\CategorySource;
use App\Enum\Direction;
use App\Enum\RuleChangeKind;
use App\Repository\CategorizationRuleRepository;
use App\Service\Matching\RuleConsolidator;
use App\Service\Normalization\LabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RuleConsolidatorTest extends KernelTestCase
{
    private RuleConsolidator $consolidator;
    private CategorizationRuleRepository $ruleRepository;
    private EntityManagerInterface $entityManager;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->consolidator = $container->get(RuleConsolidator::class);
        $this->ruleRepository = $container->get(CategorizationRuleRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->normalizer = $container->get(LabelNormalizer::class);
    }

    private function makeTransaction(string $label, int $amountCents = -1000): Transaction
    {
        $date = new \DateTimeImmutable('2026-07-20');
        $normalized = $this->normalizer->normalize($label, $date);

        $transaction = new Transaction($date, $date, $label, $amountCents, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    /**
     * Un stock où BEGLES est clairement une ville (queue de nombreux libellés).
     */
    private function seedBeglesCorpus(): void
    {
        foreach (['MOSTO BEGLES', 'MGP*JESTOCKE Begles', 'PHARMACIE CENTRALE BEGLES', 'BOUL PAUL BEGLES', 'LIDL BEGLES', 'MOSTO BEGLES'] as $merchant) {
            $this->makeTransaction('CARTE 01/07 '.$merchant);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $fingerprintLabels
     */
    private function makeRule(Category $category, array $tokens, array $fingerprintLabels = [], int $confirmations = 1, int $corrections = 0): CategorizationRule
    {
        $rule = new CategorizationRule(implode(' ', $tokens), $category, Direction::Debit);
        $rule->setTokens($tokens);
        foreach ($fingerprintLabels as $label) {
            $rule->addFingerprint($this->normalizer->normalize($label)->getFingerprint());
        }
        for ($i = 0; $i < $confirmations; ++$i) {
            $rule->recordConfirmation();
        }
        for ($i = 0; $i < $corrections; ++$i) {
            $rule->recordCorrection();
        }
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }

    public function testGenericTokensAreStrippedFromRules(): void
    {
        $this->seedBeglesCorpus();
        $restos = new Category('Restos');
        $this->entityManager->persist($restos);
        $rule = $this->makeRule($restos, ['MOSTO', 'BEGLES']);

        $report = $this->consolidator->consolidate();

        self::assertSame(['MOSTO'], $rule->getTokens());
        self::assertSame('MOSTO', $rule->getName());
        self::assertSame(1, $report->count(RuleChangeKind::Cleaned));
    }

    public function testRuleRestingOnlyOnGenericTokensIsRebuiltFromItsFingerprints(): void
    {
        // Cas réel : la règle s'était resserrée sur [BEGLES] à partir de deux
        // validations MOSTO. Ses empreintes suffisent à la remettre d'aplomb.
        $this->seedBeglesCorpus();
        $restos = new Category('Restos');
        $this->entityManager->persist($restos);
        $rule = $this->makeRule($restos, ['BEGLES'], ['CARTE 01/07 MOSTO BEGLES', 'CARTE 15/07 MOSTO BEGLES']);

        $report = $this->consolidator->consolidate();

        self::assertSame(['MOSTO'], $rule->getTokens());
        self::assertSame(1, $report->count(RuleChangeKind::Rebuilt));
    }

    public function testRuleWhoseFingerprintsShareNothingIsDemotedToExactOnly(): void
    {
        $this->seedBeglesCorpus();
        $shopping = new Category('Shopping');
        $this->entityManager->persist($shopping);
        $rule = $this->makeRule($shopping, ['BEGLES'], ['CARTE 01/07 MOSTO BEGLES', 'CARTE 02/07 MGP*JESTOCKE Begles']);

        $report = $this->consolidator->consolidate();

        self::assertSame([], $rule->getTokens(), 'Plus de token : ne matche qu\'à l\'empreinte exacte');
        self::assertCount(2, $rule->getFingerprints(), 'Les empreintes validées sont conservées');
        self::assertSame(1, $report->count(RuleChangeKind::Demoted));
    }

    public function testRuleMoreCorrectedThanConfirmedIsDemoted(): void
    {
        $sante = new Category('Santé');
        $this->entityManager->persist($sante);
        $rule = $this->makeRule($sante, ['SURAVENIR', 'ASSURANCES'], [], confirmations: 1, corrections: 2);

        $report = $this->consolidator->consolidate();

        self::assertSame([], $rule->getTokens());
        self::assertSame(1, $report->count(RuleChangeKind::Demoted));
    }

    public function testExactOnlyRuleCoveredByARealRuleIsDropped(): void
    {
        $restos = new Category('Restos');
        $this->entityManager->persist($restos);
        $this->makeRule($restos, ['MOSTO'], ['CARTE 01/07 MOSTO BEGLES']);
        $orphan = $this->makeRule($restos, [], ['CARTE 01/07 MOSTO BEGLES', 'CARTE 15/07 MOSTO BEGLES']);
        $orphanId = $orphan->getId();

        $report = $this->consolidator->consolidate();

        self::assertSame(1, $report->count(RuleChangeKind::Dropped));
        self::assertNull($this->ruleRepository->find($orphanId));
    }

    public function testExactOnlyRuleNotCoveredIsKept(): void
    {
        $restos = new Category('Restos');
        $this->entityManager->persist($restos);
        $this->makeRule($restos, ['MOSTO'], ['CARTE 01/07 MOSTO BEGLES']);
        $orphan = $this->makeRule($restos, [], ['CARTE 01/07 MOSTO BEGLES', 'CARTE 02/07 MGP*JESTOCKE Begles']);

        $report = $this->consolidator->consolidate();

        self::assertSame(0, $report->count(RuleChangeKind::Dropped));
        self::assertNotNull($this->ruleRepository->find($orphan->getId()));
    }

    public function testHandEditedSpecificTokensAreNeverWidened(): void
    {
        // L'utilisateur a volontairement gardé [CHRONO, 1010] alors que les
        // empreintes auraient donné [CHRONO] : la consolidation n'élargit pas.
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $rule = $this->makeRule($courses, ['CHRONO', '1010'], ['CARTE 09/04 CHRONO 1010 BOULIAC', 'CARTE 19/01 CHRONO 1006 LE HAILLAN']);

        $this->consolidator->consolidate();

        self::assertSame(['CHRONO', '1010'], $rule->getTokens());
    }

    public function testPlanChangesNothing(): void
    {
        $this->seedBeglesCorpus();
        $restos = new Category('Restos');
        $this->entityManager->persist($restos);
        $rule = $this->makeRule($restos, ['MOSTO', 'BEGLES']);

        $report = $this->consolidator->plan();

        self::assertCount(1, $report->changes);
        self::assertSame(['MOSTO', 'BEGLES'], $rule->getTokens(), 'Simulation : rien n\'est modifié');
    }

    public function testConsolidationClearsSuggestionsBornFromAGenericRule(): void
    {
        $this->seedBeglesCorpus();
        $shopping = new Category('Shopping');
        $this->entityManager->persist($shopping);
        $rule = $this->makeRule($shopping, ['BEGLES'], ['CARTE 02/07 MGP*JESTOCKE Begles']);

        // Une pharmacie à Bègles avait reçu « Shopping » à cause de la ville.
        $pharmacie = $this->makeTransaction('CARTE 03/07 PHARMACIE CENTRALE BEGLES');
        $pharmacie->setSuggestedCategory($shopping);
        $pharmacie->setMatchedRule($rule);
        $pharmacie->setCategorySource(CategorySource::Unclassified);
        $this->entityManager->flush();

        $report = $this->consolidator->consolidate();

        self::assertNull($pharmacie->getSuggestedCategory(), 'La suggestion fautive est retirée');
        self::assertNull($pharmacie->getMatchedRule());
        self::assertGreaterThan(0, $report->suggestionsUpdated);
    }
}
