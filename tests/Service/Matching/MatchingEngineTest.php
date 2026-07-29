<?php

namespace App\Tests\Service\Matching;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Enum\Direction;
use App\Enum\MatchConfidence;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use App\Service\Matching\MatchingEngine;
use App\Service\Normalization\LabelNormalizer;
use PHPUnit\Framework\TestCase;

class MatchingEngineTest extends TestCase
{
    private MatchingEngine $engine;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->engine = new MatchingEngine(
            $this->createStub(CategorizationRuleRepository::class),
            $this->createStub(TransactionRepository::class),
        );
        $this->normalizer = new LabelNormalizer();
    }

    private function makeRule(string $name, Category $category, int $confirmations = 0, ?array $tokens = null): CategorizationRule
    {
        $rule = new CategorizationRule($name, $category, Direction::Debit);
        $rule->setTokens($tokens ?? explode(' ', $name));
        for ($i = 0; $i < $confirmations; ++$i) {
            $rule->recordConfirmation();
        }

        return $rule;
    }

    public function testExactFingerprintMatch(): void
    {
        $courses = new Category('Courses');
        $rule = $this->makeRule('CARREFOUR', $courses, confirmations: 2);
        $label = $this->normalizer->normalize('CARTE 22/07 CARREFOUR HYPER LORMONT');
        $rule->addFingerprint($label->getFingerprint());

        $result = $this->engine->matchAgainstRules($label, -8343, [$rule]);

        self::assertSame(MatchConfidence::Exact, $result->confidence);
        self::assertSame($courses, $result->category);
    }

    public function testTokenMatchOnWholeWordOnly(): void
    {
        $courses = new Category('Courses');
        $chronoRule = $this->makeRule('CHRONO', $courses, confirmations: 2);

        $chronodrive = $this->normalizer->normalize('CARTE 09/04 CHRONO 1010 BOULIAC');
        $veterinaire = $this->normalizer->normalize('CARTE 01/11 CHRONOVET.FR 59290 WASQUEHA');

        $matchDrive = $this->engine->matchAgainstRules($chronodrive, -5000, [$chronoRule]);
        $matchVeto = $this->engine->matchAgainstRules($veterinaire, -5000, [$chronoRule]);

        self::assertSame(MatchConfidence::Token, $matchDrive->confidence);
        self::assertSame($courses, $matchDrive->category);

        self::assertFalse($matchVeto->isMatch(), 'CHRONOVET.FR ne doit jamais matcher la règle CHRONO (pas de sous-chaîne)');
    }

    public function testChronovetAndChronoCoexist(): void
    {
        $courses = new Category('Courses');
        $animaux = new Category('Animaux');
        $rules = [
            $this->makeRule('CHRONO', $courses, confirmations: 2),
            $this->makeRule('CHRONOVET.FR', $animaux, confirmations: 2),
        ];

        $veto = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('CARTE 01/11 CHRONOVET.FR 59290 WASQUEHA'),
            -4500,
            $rules,
        );

        self::assertSame($animaux, $veto->category);
    }

    public function testFuzzyMatchCatchesTypo(): void
    {
        $courses = new Category('Courses');
        $rule = $this->makeRule('CARREFOUR', $courses, confirmations: 2);

        $result = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('CARTE 22/07 CARREFOUS HYPER LORMONT'),
            -8343,
            [$rule],
        );

        self::assertSame(MatchConfidence::Fuzzy, $result->confidence);
        self::assertSame($courses, $result->category);
    }

    public function testFuzzyNeverMatchesNumericIdentifiers(): void
    {
        // Cas réel : trois prêts immobiliers prélevés le même jour, dont les
        // numéros ne diffèrent que d'un chiffre. Un chiffre d'écart = un
        // prêt différent, jamais une typo.
        $logement = new Category('Logement');
        $rule = $this->makeRule('0545773921701', $logement, confirmations: 2, tokens: ['0545773921701']);

        $result = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('ECH PRET 0545773921703'),
            -62367,
            [$rule],
        );

        self::assertFalse($result->isMatch(), 'Deux numéros de prêt distincts ne doivent jamais fuzzy-matcher');
    }

    public function testFuzzyDoesNotMatchShortTokens(): void
    {
        $telecom = new Category('Abonnements');
        $rule = $this->makeRule('SFR', $telecom, confirmations: 2);

        $result = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('PRLV SFA'),
            -2000,
            [$rule],
        );

        self::assertFalse($result->isMatch(), 'Pas de fuzzy sur les tokens courts (trop de faux positifs)');
    }

    public function testLabelDriftMatchedViaSharedToken(): void
    {
        // Dérive réelle : "PRLV SFR" puis "PRLV SFR-SOCIETE FRANCAISE DU RA".
        // Le tiret étant séparateur, le token SFR reste présent en mot entier.
        $telecom = new Category('Abonnements');
        $rule = $this->makeRule('SFR', $telecom, confirmations: 2);

        $result = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('PRLV SFR-SOCIETE FRANCAISE DU RA'),
            -2000,
            [$rule],
        );

        self::assertSame(MatchConfidence::Token, $result->confidence);
        self::assertSame($telecom, $result->category);
    }

    public function testPaypalAmountScopedRuleWinsOverGeneric(): void
    {
        $paypalATrier = new Category('PayPal à trier');
        $abonnements = new Category('Abonnements');

        $generic = $this->makeRule('PAYPAL', $paypalATrier, confirmations: 2);
        $spotify = $this->makeRule('PAYPAL', $abonnements, confirmations: 2);
        $spotify->setAmountCents(-2124);

        $label = $this->normalizer->normalize('PRLV PayPal Europe S.a.r.l. et C');

        $matchAbonnement = $this->engine->matchAgainstRules($label, -2124, [$generic, $spotify]);
        $matchAutre = $this->engine->matchAgainstRules($label, -8712, [$generic, $spotify]);

        self::assertSame($abonnements, $matchAbonnement->category, 'La sous-règle par montant l\'emporte');
        self::assertSame($paypalATrier, $matchAutre->category, 'Montant inconnu → règle générique');
    }

    public function testMoreSpecificRuleWins(): void
    {
        $courses = new Category('Courses');
        $carburant = new Category('Voiture');

        $rules = [
            $this->makeRule('AUCHAN', $courses, confirmations: 2),
            $this->makeRule('AUCHAN CARBU', $carburant, confirmations: 2),
        ];

        $result = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('CARTE 04/07 AUCHAN CARBU BORDEAUX'),
            -6000,
            $rules,
        );

        self::assertSame($carburant, $result->category, 'La règle aux tokens les plus nombreux gagne');
    }

    public function testNoMatchGoesToReview(): void
    {
        $result = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('CARTE 23/07 TCHU BORDEAUX'),
            -1290,
            [],
        );

        self::assertFalse($result->isMatch());
        self::assertSame(MatchConfidence::None, $result->confidence);
        self::assertNull($result->category);
    }

    public function testEmptyTokenRuleNeverMatches(): void
    {
        $divers = new Category('Divers');
        $rule = $this->makeRule('vide', $divers, confirmations: 2, tokens: []);

        $result = $this->engine->matchAgainstRules(
            $this->normalizer->normalize('CARTE 23/07 TCHU BORDEAUX'),
            -1290,
            [$rule],
        );

        self::assertFalse($result->isMatch());
    }
}
