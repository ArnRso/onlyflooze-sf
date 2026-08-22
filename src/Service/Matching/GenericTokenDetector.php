<?php

namespace App\Service\Matching;

use App\Dto\CorpusEntry;

/**
 * Détecte, dans le corpus, les tokens qui ne discriminent rien : mots-outils,
 * noms de ville, suffixes juridiques… Une règle ne doit jamais reposer sur
 * eux (cas réel : la règle [BEGLES] suggérait « Shopping » à tout achat fait
 * à Bègles).
 *
 * Trois signaux, tous tirés des données — la liste s'affine donc à chaque
 * import et à chaque tri :
 *
 * 1. mots-outils français (liste fixe) ;
 * 2. dispersion : un token que l'utilisateur a rangé dans 3 catégories
 *    différentes sans qu'aucune domine ne prédit aucune catégorie ;
 * 3. position : un token présent dans plusieurs libellés distincts, presque
 *    toujours en queue de libellé, est un lieu ou un suffixe (« LIDL CENON »,
 *    « SPOTIFY STOCKHOLM », « ORANGE SA »). Le calcul est itéré : une fois
 *    « CEDEX » démasqué, « VEDENE » se retrouve en queue et tombe à son tour.
 *    La tête du libellé n'est jamais concernée : c'est le marchand.
 *
 * La fréquence brute n'est volontairement PAS un signal : le marchand du
 * quotidien pèse 10 % du corpus (cas réel : CHRONO) et n'en est pas moins le
 * meilleur discriminant qui soit.
 */
class GenericTokenDetector
{
    private const array STOPWORDS = ['DE', 'LE', 'LA', 'LES', 'DU', 'DES', 'ET', 'EN', 'AU', 'AUX', 'SUR', 'CHEZ'];

    private const int MIN_CATEGORY_SPREAD = 3;
    private const float MAX_DOMINANT_CATEGORY_SHARE = 0.7;
    private const int MIN_DISTINCT_LABELS = 4;
    private const float MIN_TRAILING_RATIO = 0.5;
    private const int MAX_POSITIONAL_PASSES = 5;

    /**
     * @param list<CorpusEntry> $entries
     *
     * @return list<string>
     */
    public function detect(array $entries): array
    {
        $occurrences = [];
        $distinctLabels = [];
        $categories = [];

        foreach ($entries as $entry) {
            $fingerprint = implode(' ', $entry->tokens);
            foreach (array_unique($entry->tokens) as $token) {
                $occurrences[$token] = ($occurrences[$token] ?? 0) + 1;
                $distinctLabels[$token][$fingerprint] = true;
                if ($entry->categoryKey !== null) {
                    $categories[$token][$entry->categoryKey] = ($categories[$token][$entry->categoryKey] ?? 0) + 1;
                }
            }
        }

        $generic = array_fill_keys(self::STOPWORDS, true);

        foreach ($categories as $token => $perCategory) {
            if (\count($perCategory) >= self::MIN_CATEGORY_SPREAD
                && max($perCategory) / array_sum($perCategory) < self::MAX_DOMINANT_CATEGORY_SHARE) {
                $generic[$token] = true;
            }
        }

        for ($pass = 0; $pass < self::MAX_POSITIONAL_PASSES; ++$pass) {
            $trailing = [];
            foreach ($entries as $entry) {
                $last = $this->trailingToken($entry->tokens, $generic);
                if ($last !== null) {
                    $trailing[$last] = ($trailing[$last] ?? 0) + 1;
                }
            }

            $found = false;
            foreach ($trailing as $token => $count) {
                if (isset($generic[$token])) {
                    continue;
                }
                if (\count($distinctLabels[$token]) >= self::MIN_DISTINCT_LABELS
                    && $count / $occurrences[$token] >= self::MIN_TRAILING_RATIO) {
                    $generic[$token] = true;
                    $found = true;
                }
            }

            if (!$found) {
                break;
            }
        }

        $result = array_keys($generic);
        sort($result);

        return $result;
    }

    /**
     * Dernier token du libellé une fois la queue générique retirée — ou null
     * s'il ne reste que la tête (le marchand), qu'on ne remet jamais en cause.
     *
     * @param list<string>        $tokens
     * @param array<string, true> $generic
     */
    private function trailingToken(array $tokens, array $generic): ?string
    {
        while (\count($tokens) > 1 && isset($generic[$tokens[\count($tokens) - 1]])) {
            array_pop($tokens);
        }

        $head = 0;
        while ($head < \count($tokens) - 1 && isset($generic[$tokens[$head]])) {
            ++$head;
        }

        if (\count($tokens) - $head < 2) {
            return null;
        }

        return $tokens[\count($tokens) - 1];
    }
}
