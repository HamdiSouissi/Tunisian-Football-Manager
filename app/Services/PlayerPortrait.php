<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Génère des portraits vectoriels stables pour les joueurs fictifs.
 * Aucune image distante, donnée biométrique ou asset à stocker n'est nécessaire.
 */
final class PlayerPortrait
{
    private const SKIN_TONES = [
        ['#f2c6a5', '#d99a78'], ['#e8b58e', '#c98663'], ['#d99a70', '#b8734f'],
        ['#c98357', '#9f5f3e'], ['#ad6d46', '#82492f'], ['#8b5235', '#623722'],
        ['#f0cfb4', '#d7a184'],
    ];

    private const HAIR_COLORS = ['#171412', '#241a16', '#39251b', '#513326', '#6b4934'];
    private const EYE_COLORS = ['#30251f', '#4b3324', '#37505a', '#496048'];
    private const JERSEYS = [
        ['#d43f55', '#f5f7f6'], ['#2974b8', '#eef5fb'], ['#15966f', '#f3faf7'],
        ['#d98b24', '#18211f'], ['#6954b8', '#f4f0ff'], ['#202a35', '#e6edf2'],
        ['#b83986', '#fff0fa'], ['#168a9e', '#edfafd'],
    ];

    private const FACE_SHAPES = [
        'M45 50 C47 29 61 20 80 20 C100 20 113 31 115 51 L112 84 C110 106 96 123 80 126 C63 123 50 107 48 85 Z',
        'M43 52 C44 29 60 19 80 19 C101 19 116 31 117 53 L114 82 C112 108 98 122 80 125 C61 122 47 106 46 83 Z',
        'M47 47 C49 27 63 18 80 18 C98 18 112 29 113 49 L111 88 C108 111 96 127 80 129 C63 126 51 109 49 87 Z',
    ];

    /** @param array<string,mixed> $player */
    public function render(array $player, string $size = 'md'): string
    {
        $size = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
        $name = trim((string)($player['first_name'] ?? '') . ' ' . (string)($player['last_name'] ?? ''));
        $name = $name !== '' ? $name : 'Joueur';
        $identity = implode('|', [
            (int)($player['id'] ?? 0), $name,
            (string)($player['birth_date'] ?? ''), (string)($player['primary_position'] ?? ''),
        ]);
        $hash = hash('sha256', $identity);
        $id = 'portrait-' . substr($hash, 0, 16);

        $skin = self::SKIN_TONES[$this->byte($hash, 0) % count(self::SKIN_TONES)];
        $hair = self::HAIR_COLORS[$this->byte($hash, 1) % count(self::HAIR_COLORS)];
        $eyes = self::EYE_COLORS[$this->byte($hash, 2) % count(self::EYE_COLORS)];
        $jersey = self::JERSEYS[$this->byte($hash, 3) % count(self::JERSEYS)];
        $primary = $this->color($player['club_color'] ?? null, $jersey[0]);
        $secondary = $this->color($player['club_secondary_color'] ?? null, $jersey[1]);
        $face = self::FACE_SHAPES[$this->byte($hash, 4) % count(self::FACE_SHAPES)];
        $hairStyle = $this->byte($hash, 5) % 5;
        $beardStyle = $this->byte($hash, 6) % 5;
        $mouth = [
            'M69 101 Q80 108 91 101 Q80 114 69 101 Z',
            'M69 103 Q80 99 91 103 Q80 109 69 103 Z',
            'M70 102 Q80 106 90 102',
        ][$this->byte($hash, 7) % 3];
        $mouthFill = $this->byte($hash, 7) % 3 === 2 ? 'none' : '#7d3f3c';
        $mouthStroke = $this->byte($hash, 7) % 3 === 2 ? '#7d3f3c' : 'none';

        $hairMarkup = $this->hair($hairStyle, $hair);
        $beardMarkup = $this->beard($beardStyle, $hair);
        $label = htmlspecialchars('Portrait généré de ' . $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<SVG
<svg class="player-portrait player-portrait--{$size}" viewBox="0 0 160 160" role="img" aria-label="{$label}" focusable="false" xmlns="http://www.w3.org/2000/svg">
<title>{$label}</title>
<defs>
  <linearGradient id="{$id}-bg" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#203832"/><stop offset="1" stop-color="#091411"/></linearGradient>
  <linearGradient id="{$id}-shirt" x1="0" y1="0" x2="0" y2="1"><stop stop-color="{$primary}"/><stop offset="1" stop-color="#101d1a" stop-opacity=".35"/></linearGradient>
  <clipPath id="{$id}-clip"><rect width="160" height="160" rx="18"/></clipPath>
</defs>
<g clip-path="url(#{$id}-clip)">
  <rect width="160" height="160" fill="url(#{$id}-bg)"/>
  <circle cx="24" cy="24" r="48" fill="{$primary}" opacity=".13"/><circle cx="145" cy="82" r="58" fill="{$secondary}" opacity=".05"/>
  <path d="M12 168 Q15 126 54 115 L68 110 H92 L106 115 Q145 126 148 168 Z" fill="url(#{$id}-shirt)"/>
  <path d="M56 116 L80 143 L104 116 L96 112 L80 129 L64 112 Z" fill="{$secondary}" opacity=".9"/>
  <path d="M25 143 L51 132 M135 143 L109 132" stroke="{$secondary}" stroke-width="5" opacity=".65"/>
  <path d="M66 95 H94 V119 Q80 133 66 119 Z" fill="{$skin[1]}"/>
  <ellipse cx="46" cy="75" rx="10" ry="15" fill="{$skin[1]}"/><ellipse cx="114" cy="75" rx="10" ry="15" fill="{$skin[1]}"/>
  <path d="M43 73 Q49 67 51 77 M117 73 Q111 67 109 77" fill="none" stroke="{$skin[0]}" stroke-width="2" opacity=".7"/>
  <path d="{$face}" fill="{$skin[0]}"/>
  <path d="M80 21 C101 22 113 33 115 52 L112 85 C109 107 96 123 80 126 Z" fill="{$skin[1]}" opacity=".22"/>
  <ellipse cx="60" cy="87" rx="10" ry="6" fill="#ef8d85" opacity=".09"/><ellipse cx="100" cy="87" rx="10" ry="6" fill="#ef8d85" opacity=".09"/>
  {$hairMarkup}
  <path d="M54 59 Q63 53 72 59 M88 59 Q98 53 106 59" fill="none" stroke="{$hair}" stroke-width="4" stroke-linecap="round"/>
  <ellipse cx="64" cy="69" rx="7" ry="4.5" fill="#f7f4ed"/><ellipse cx="96" cy="69" rx="7" ry="4.5" fill="#f7f4ed"/>
  <circle cx="64" cy="69" r="3.2" fill="{$eyes}"/><circle cx="96" cy="69" r="3.2" fill="{$eyes}"/>
  <circle cx="64" cy="69" r="1.45" fill="#101312"/><circle cx="96" cy="69" r="1.45" fill="#101312"/>
  <circle cx="63" cy="68" r=".65" fill="#fff"/><circle cx="95" cy="68" r=".65" fill="#fff"/>
  <path d="M80 70 L76 86 Q80 90 85 86" fill="none" stroke="{$skin[1]}" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/>
  {$beardMarkup}
  <path d="{$mouth}" fill="{$mouthFill}" stroke="{$mouthStroke}" stroke-width="2" stroke-linecap="round"/>
  <path d="M55 116 Q80 132 105 116" fill="none" stroke="#fff" stroke-width="1" opacity=".12"/>
</g>
</svg>
SVG;
    }

    private function byte(string $hash, int $offset): int
    {
        return (int)hexdec(substr($hash, $offset * 2, 2));
    }

    private function color(mixed $candidate, string $fallback): string
    {
        $candidate = (string)$candidate;
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $candidate) === 1 ? $candidate : $fallback;
    }

    private function hair(int $style, string $color): string
    {
        if ($style === 2) {
            $curls = '';
            foreach ([[49,40,12],[58,27,13],[73,21,13],[89,21,13],[104,29,13],[112,43,11],[66,38,14],[88,36,15]] as [$x,$y,$radius]) {
                $curls .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $radius . '" fill="' . $color . '"/>';
            }
            return $curls . '<path d="M45 54 Q49 35 61 37 Q80 47 99 36 Q111 39 115 56 L111 45 Q80 55 49 45 Z" fill="' . $color . '"/>';
        }

        $paths = [
            'M44 58 C40 34 55 16 80 15 C106 15 121 34 116 59 L109 45 Q88 45 68 35 Q58 45 49 48 L48 61 Z',
            'M46 53 C45 31 60 18 80 18 C102 18 116 32 114 54 L108 43 Q80 37 52 45 L49 57 Z',
            '',
            'M45 55 C43 34 58 19 80 19 C103 19 117 34 115 56 L108 45 Q78 42 53 47 L49 59 Z',
            'M44 58 C42 32 59 15 82 16 C105 17 120 34 116 58 L109 44 Q93 41 80 28 Q69 42 49 47 L48 61 Z',
        ];
        return '<path d="' . $paths[$style] . '" fill="' . $color . '"/>';
    }

    private function beard(int $style, string $color): string
    {
        return match ($style) {
            1 => '<path d="M51 87 Q55 116 80 124 Q105 116 110 87 Q105 108 80 113 Q56 108 51 87 Z" fill="' . $color . '" opacity=".78"/>',
            2 => '<path d="M67 94 Q80 88 93 94 Q86 101 80 98 Q74 101 67 94 Z" fill="' . $color . '" opacity=".9"/>',
            3 => '<path d="M68 111 Q80 124 92 111 Q90 126 80 129 Q70 126 68 111 Z" fill="' . $color . '" opacity=".85"/>',
            default => '',
        };
    }
}
