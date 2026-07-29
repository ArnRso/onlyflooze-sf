<?php

namespace App\Tests\Service\Matching;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionNature;
use App\Service\Matching\RuleLearner;
use App\Service\Normalization\LabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RuleQualityTest extends KernelTestCase
{
    private RuleLearner $learner;
    private EntityManagerInterface $entityManager;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->learner = $container->get(RuleLearner::class);
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

    public function testRuleNeverDegeneratesToStopwords(): void
    {
        // Cas réel : deux restos dont les libellés ne partagent que « DU » —
        // la règle ne doit pas se resserrer sur un mot-outil qui matcherait
        // presque tout le corpus.
        $restos = new Category('Restos & sorties');
        $this->entityManager->persist($restos);

        $first = $this->makeTransaction('CARTE 01/07 RESTO DU COIN', -3500);
        $second = $this->makeTransaction('CARTE 02/07 PIZZA DU PORT', -2800);

        $rule = $this->learner->learnFromCategorization($first, $restos);
        $this->learner->learnFromCategorization($second, $restos);

        self::assertSame(['RESTO', 'DU', 'COIN'], $rule->getTokens(), 'Le narrowing vers [DU] est refusé');
    }

    public function testRuleNeverDegeneratesToUbiquitousToken(): void
    {
        // Un token présent dans une grosse part du corpus (nom de ville) ne
        // discrimine rien : le narrowing est refusé.
        $restos = new Category('Restos & sorties');
        $this->entityManager->persist($restos);

        for ($i = 0; $i < 40; ++$i) {
            $this->makeTransaction(sprintf('CARTE 01/07 COMMERCE%d BORDEAUX', $i), -1000);
        }
        $this->entityManager->flush();

        $first = $this->makeTransaction('CARTE 03/07 TCHU BORDEAUX', -1290);
        $second = $this->makeTransaction('CARTE 04/07 EURASIE BORDEAUX', -4500);

        $rule = $this->learner->learnFromCategorization($first, $restos);
        $this->learner->learnFromCategorization($second, $restos);

        self::assertSame(['TCHU', 'BORDEAUX'], $rule->getTokens(), 'Le narrowing vers [BORDEAUX] est refusé');
    }

    public function testNarrowingToDiscriminantTokenStillWorks(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);

        $first = $this->makeTransaction('CARTE 09/04 CHRONO 1010 BOULIAC', -5000);
        $second = $this->makeTransaction('CARTE 19/01 CHRONO 1006 LE HAILLAN', -6000);

        $rule = $this->learner->learnFromCategorization($first, $courses);
        $this->learner->learnFromCategorization($second, $courses);

        self::assertSame(['CHRONO'], $rule->getTokens(), 'CHRONO reste un discriminant valide');
    }

    public function testRuleLearnsNonDefaultNature(): void
    {
        $transferts = new Category('Transferts internes');
        $this->entityManager->persist($transferts);

        $transaction = $this->makeTransaction('VIR vers LIVRET DEV. DURABLE ET S', -20000);
        $transaction->setNature(TransactionNature::InternalTransfer);

        $rule = $this->learner->learnFromCategorization($transaction, $transferts);

        self::assertSame(TransactionNature::InternalTransfer, $rule->getNature(), 'La règle mémorise la nature pour les prochaines suggestions');
    }

    public function testRuleDoesNotStoreDefaultNature(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);

        $transaction = $this->makeTransaction('CARTE 20/08 LIDL CENON', -3000);

        $rule = $this->learner->learnFromCategorization($transaction, $courses);

        self::assertNull($rule->getNature(), 'Nature par défaut du sens : rien à mémoriser');
    }
}
