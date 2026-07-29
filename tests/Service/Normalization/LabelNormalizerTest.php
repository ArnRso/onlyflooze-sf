<?php

namespace App\Tests\Service\Normalization;

use App\Enum\TransactionType;
use App\Service\Normalization\LabelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LabelNormalizerTest extends TestCase
{
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new LabelNormalizer();
    }

    /**
     * @param list<string> $expectedTokens
     */
    #[DataProvider('provideLabels')]
    public function testNormalize(string $label, TransactionType $expectedType, array $expectedTokens): void
    {
        $normalized = $this->normalizer->normalize($label);

        self::assertSame($expectedType, $normalized->type);
        self::assertSame($expectedTokens, $normalized->tokens);
    }

    /**
     * @return iterable<string, array{string, TransactionType, list<string>}>
     */
    public static function provideLabels(): iterable
    {
        yield 'achat carte simple' => [
            'CARTE 22/07 CARREFOUR HYPER LORMONT',
            TransactionType::Carte,
            ['CARREFOUR', 'HYPER', 'LORMONT'],
        ];

        yield 'carte avec référence PAYLI' => [
            'CARTE 03/09 AMAZON PAYMENTS PAYLI2441535/',
            TransactionType::Carte,
            ['AMAZON', 'PAYMENTS'],
        ];

        yield 'carte avec montant embarqué' => [
            'CARTE 02/12 CLAUDE.AI SAN FRANCI 21,60 EUR',
            TransactionType::Carte,
            ['CLAUDE.AI', 'SAN', 'FRANCI'],
        ];

        yield 'chronodrive : numéro de magasin conservé' => [
            'CARTE 09/04 CHRONO 1010 BOULIAC',
            TransactionType::Carte,
            ['CHRONO', '1010', 'BOULIAC'],
        ];

        yield 'chronovet : token entier distinct de CHRONO' => [
            'CARTE 01/11 CHRONOVET.FR 59290 WASQUEHA',
            TransactionType::Carte,
            ['CHRONOVET.FR', '59290', 'WASQUEHA'],
        ];

        yield 'carte avec astérisque' => [
            'CARTE 05/10 MGP*JESTOCKE Begles',
            TransactionType::Carte,
            ['MGP', 'JESTOCKE', 'BEGLES'],
        ];

        yield 'prélèvement' => [
            'PRLV RADIANCE MUTUELLE',
            TransactionType::Prelevement,
            ['RADIANCE', 'MUTUELLE'],
        ];

        yield 'prélèvement libellé dérivé : tiret séparateur' => [
            'PRLV SFR-SOCIETE FRANCAISE DU RA',
            TransactionType::Prelevement,
            ['SFR', 'SOCIETE', 'FRANCAISE', 'DU', 'RA'],
        ];

        yield 'virement simple' => [
            'VIR HPG',
            TransactionType::Virement,
            ['HPG'],
        ];

        yield 'virement instantané vers' => [
            'VIR INST vers MARIE DELAIRE',
            TransactionType::Virement,
            ['MARIE', 'DELAIRE'],
        ];

        yield 'virement de (transfert interne, graphie 1)' => [
            'VIR de LIVRET DEV. DURABLE ET S',
            TransactionType::Virement,
            ['LIVRET', 'DEV', 'DURABLE', 'ET'],
        ];

        yield 'virement vers (transfert interne, graphie 2)' => [
            'VIR vers LIVRET DEV DURABLE ET S',
            TransactionType::Virement,
            ['LIVRET', 'DEV', 'DURABLE', 'ET'],
        ];

        yield 'échéance de prêt : numéro conservé' => [
            'ECH PRET 0545773921701',
            TransactionType::EcheancePret,
            ['0545773921701'],
        ];

        yield 'frais : date MM/AA retirée' => [
            'F COTISATION EUROCOMPTE 01/26',
            TransactionType::Frais,
            ['COTISATION', 'EUROCOMPTE'],
        ];

        yield 'annulation carte' => [
            'ANN CARTE AMAZON PAYMENTS PAYLI2441535/',
            TransactionType::AnnulationCarte,
            ['AMAZON', 'PAYMENTS'],
        ];

        yield 'intérêts débiteurs' => [
            'INT DEB FORFAIT R313-4 TRIM 01',
            TransactionType::InteretsDebiteurs,
            ['FORFAIT', 'R313', 'TRIM', '01'],
        ];

        yield 'retrait DAB : date JJ/MM/AA retirée' => [
            'RET DAB 03/02/24 ARTIGUES PRES B',
            TransactionType::RetraitDab,
            ['ARTIGUES', 'PRES'],
        ];

        yield 'paypal graphie 1' => [
            'PRLV PayPal (Europe) S.a r.l. et',
            TransactionType::Prelevement,
            ['PAYPAL', 'EUROPE', 'S.A', 'R.L', 'ET'],
        ];

        yield 'paypal graphie 2' => [
            'PRLV PayPal Europe S.a.r.l. et C',
            TransactionType::Prelevement,
            ['PAYPAL', 'EUROPE', 'S.A.R.L', 'ET'],
        ];

        yield 'libellé inconnu' => [
            'VRST 20671835 ADRIEN RUSSO',
            TransactionType::Autre,
            ['VRST', '20671835', 'ADRIEN', 'RUSSO'],
        ];
    }

    public function testPurchaseDateSameYear(): void
    {
        $normalized = $this->normalizer->normalize(
            'CARTE 23/07 TCHU BORDEAUX',
            new \DateTimeImmutable('2026-07-24'),
        );

        self::assertSame('2026-07-23', $normalized->purchaseDate?->format('Y-m-d'));
    }

    public function testPurchaseDateYearRollover(): void
    {
        $normalized = $this->normalizer->normalize(
            'CARTE 28/12 LIDL CENON',
            new \DateTimeImmutable('2026-01-02'),
        );

        self::assertSame('2025-12-28', $normalized->purchaseDate?->format('Y-m-d'));
    }

    public function testPurchaseDateAbsentWithoutOperationDate(): void
    {
        $normalized = $this->normalizer->normalize('CARTE 23/07 TCHU BORDEAUX');

        self::assertNull($normalized->purchaseDate);
    }

    public function testFingerprintIsStableAcrossWhitespace(): void
    {
        $first = $this->normalizer->normalize('CARTE 22/07 CARREFOUR  HYPER LORMONT');
        $second = $this->normalizer->normalize('CARTE 19/03 CARREFOUR HYPER LORMONT');

        self::assertSame($first->getFingerprint(), $second->getFingerprint());
    }

    public function testFingerprintDiffersByType(): void
    {
        $carte = $this->normalizer->normalize('CARTE 22/07 EDF');
        $prlv = $this->normalizer->normalize('PRLV EDF');

        self::assertNotSame($carte->getFingerprint(), $prlv->getFingerprint());
    }
}
