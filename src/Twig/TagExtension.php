<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TagExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('tag_text_color', [$this, 'getTextColor']),
        ];
    }

    /**
     * Calcule la couleur de texte optimale (blanc ou noir) en fonction de la couleur de fond
     */
    public function getTextColor(string $backgroundColor): string
    {
        // Supprimer le # si présent
        $hex = ltrim($backgroundColor, '#');

        // Convertir en RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Calculer la luminance selon la formule W3C
        // https://www.w3.org/WAI/WCAG21/Understanding/contrast-minimum.html
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        // Si la luminance est élevée (couleur claire), utiliser du texte noir
        // Sinon utiliser du texte blanc
        return $luminance > 0.5 ? '#000000' : '#ffffff';
    }
}
