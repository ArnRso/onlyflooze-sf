<?php

namespace App\Tests\Service\Matching;

use App\Dto\CorpusEntry;
use App\Service\Matching\GenericTokenDetector;
use PHPUnit\Framework\TestCase;

class GenericTokenDetectorTest extends TestCase
{
    private GenericTokenDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new GenericTokenDetector();
    }

    /**
     * @param list<string> $labels
     *
     * @return list<CorpusEntry>
     */
    private function corpus(array $labels, ?string $categoryKey = null): array
    {
        return array_map(
            static fn (string $label): CorpusEntry => new CorpusEntry(explode(' ', $label), $categoryKey),
            $labels,
        );
    }

    public function testStopwordsAreAlwaysGeneric(): void
    {
        $generic = $this->detector->detect([]);

        self::assertContains('DE', $generic);
        self::assertContains('DU', $generic);
        self::assertNotContains('CHRONO', $generic);
    }

    public function testCityAtTheEndOfManyDistinctLabelsIsGeneric(): void
    {
        // Cas réel : BEGLES apparaît dans 18 libellés différents, toujours en
        // queue — la règle [BEGLES] suggérait « Shopping » à tout Bègles.
        $generic = $this->detector->detect($this->corpus([
            'MOSTO BEGLES', 'MOSTO BEGLES', 'MGP JESTOCKE BEGLES', 'MGP JESTOCKE BEGLES',
            'PHARMACIE CENTRALE BEGLES', 'BOUL PAUL BEGLES', 'LIDL BEGLES',
        ]));

        self::assertContains('BEGLES', $generic);
        self::assertNotContains('MOSTO', $generic);
        self::assertNotContains('JESTOCKE', $generic);
        self::assertNotContains('LIDL', $generic);
    }

    public function testMerchantAtTheHeadIsNeverGenericEvenWithManyStores(): void
    {
        // ASF (péages) : un libellé différent à chaque gare, mais toujours en
        // tête — c'est le marchand, pas un lieu.
        $generic = $this->detector->detect($this->corpus([
            'ASF BEAULIEU', 'ASF VIRSAC', 'ASF SAINT SELVE', 'ASF BIARRITZ', 'ASF TOULOUSE', 'ASF VIRSAC',
            'TABAC LE FONTAINE', 'TABAC PRESSE', 'TABAC DU CENTRE', 'TABAC DE LA GARE',
        ]));

        self::assertNotContains('ASF', $generic);
        self::assertNotContains('TABAC', $generic);
    }

    public function testTrailingDetectionIteratesPastAlreadyGenericSuffixes(): void
    {
        // CEDEX est d'abord démasqué (queue de 4 libellés distincts) ; une
        // fois retiré, VEDENE se retrouve en queue et tombe à son tour.
        $generic = $this->detector->detect($this->corpus([
            'ASF BEAULIEU SUR VEDENE CEDEX', 'ASF VIRSAC VEDENE CEDEX', 'ASF SELVE VEDENE CEDEX', 'ASF NARBONNE VEDENE CEDEX',
            'ORANGE SA CEDEX', 'EDF CLIENTS CEDEX', 'ENGIE CEDEX', 'DGFIP IMPOTS CEDEX',
        ]));

        self::assertContains('CEDEX', $generic);
        self::assertContains('VEDENE', $generic);
        self::assertNotContains('ASF', $generic);
    }

    public function testTokenSpreadAcrossThreeCategoriesIsGeneric(): void
    {
        $entries = [
            ...$this->corpus(['LIDL CENON', 'LIDL CENON'], 'courses'),
            ...$this->corpus(['PHARMACIE CENON'], 'sante'),
            ...$this->corpus(['TABAC CENON'], 'tabac'),
        ];

        $generic = $this->detector->detect($entries);

        self::assertContains('CENON', $generic, 'Rangé dans 3 catégories : ne prédit rien');
        self::assertNotContains('LIDL', $generic);
    }

    public function testDominantMerchantSpreadOverThreeCategoriesStaysDiscriminant(): void
    {
        // Chronodrive : 10 % du corpus, presque toujours « Courses », avec
        // quelques exceptions. Ni la fréquence ni ces exceptions n'en font un
        // token générique.
        $entries = [
            ...$this->corpus(array_fill(0, 20, 'CHRONO 1010 BOULIAC'), 'courses'),
            ...$this->corpus(['CHRONO 1010 BOULIAC'], 'alcool'),
            ...$this->corpus(['CHRONO 1010 BOULIAC'], 'maison'),
            ...$this->corpus(['LIDL CENON', 'TABAC CENON', 'PHARMACIE CENON', 'BOUL CENON']),
        ];

        $generic = $this->detector->detect($entries);

        self::assertNotContains('CHRONO', $generic);
        self::assertContains('CENON', $generic);
    }

    public function testUbiquitousCityIsGeneric(): void
    {
        $labels = [];
        for ($i = 0; $i < 40; ++$i) {
            $labels[] = sprintf('COMMERCE%d BORDEAUX', $i);
        }

        $generic = $this->detector->detect($this->corpus($labels));

        self::assertContains('BORDEAUX', $generic);
        self::assertNotContains('COMMERCE1', $generic);
    }

    public function testSingleTokenLabelsNeverMakeTheirTokenGeneric(): void
    {
        // « VIR HPG » : le seul token est le marchand, pas une queue.
        $generic = $this->detector->detect($this->corpus([
            'HPG', 'HPG', 'HPG', 'HPG', 'HPG', 'HPG SARL', 'HPG SARL',
        ]));

        self::assertNotContains('HPG', $generic);
    }

    public function testFewDistinctLabelsAreNotEnoughToJudge(): void
    {
        // Une ville vue dans 2 libellés seulement : rien ne permet encore de
        // la distinguer d'un marchand. Elle sera démasquée plus tard, quand
        // le corpus aura grossi — c'est l'amélioration dans le temps.
        $generic = $this->detector->detect($this->corpus([
            'COR LINEA AJACCIO', 'COR LINEA AJACCIO', 'U EXPRESS AJACCIO',
        ]));

        self::assertNotContains('AJACCIO', $generic);
    }
}
