<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Designed title card for a restaurant or destination that has no photos yet:
 * a simple motif for the kind of place (cup, bowl, wave, falls, …), its name,
 * its town and a "photos coming soon" pill. Like EventCover it is clearly a
 * card, never a stand-in photo, and owners/admins replace it by uploading.
 */
class PlaceCover
{
    /** Background gradient per kind: food places in warm tones, nature in green, sea in ocean blue. */
    private const THEMES = [
        'restaurant' => ['#a8582a', '#4a2412'],
        'grill' => ['#9c3d22', '#3f1a10'],
        'ramen' => ['#8f2f2f', '#35121a'],
        'cafe' => ['#7a5233', '#2e1d12'],
        'beach' => ['#0b6680', '#0c3946'],
        'resort' => ['#0f7a94', '#123f4a'],
        'waterfall' => ['#16708a', '#0f3d3a'],
        'mountain' => ['#2e6b4e', '#163626'],
        'camp' => ['#355f3a', '#18301d'],
        'farm' => ['#4f7a35', '#223a17'],
        'church' => ['#5b4b7a', '#262036'],
    ];

    /** Store the card on the public disk under $directory and return its path (same name → same file). */
    public static function store(string $directory, string $name, string $kind, string $eyebrow, string $place): string
    {
        $path = trim($directory, '/').'/'.Str::slug($name).'.svg';
        Storage::disk('public')->put($path, self::svg($name, $kind, $eyebrow, $place));

        return $path;
    }

    public static function svg(string $name, string $kind, string $eyebrow, string $place): string
    {
        [$from, $to] = self::THEMES[$kind] ?? self::THEMES['restaurant'];

        $lines = EventCover::wrap($name, 24);
        if (count($lines) > 3) {
            $lines = array_slice(EventCover::wrap($name, 32), 0, 3);
        }
        $fontSize = count($lines) >= 3 ? 50 : (count($lines) === 2 ? 58 : 66);
        $lineHeight = (int) round($fontSize * 1.18);
        $firstBaseline = 350;

        $titleTspans = '';
        foreach ($lines as $i => $line) {
            $titleTspans .= sprintf('<tspan x="600" y="%d">%s</tspan>', $firstBaseline + $i * $lineHeight, EventCover::e($line));
        }
        $placeY = $firstBaseline + (count($lines) - 1) * $lineHeight + 56;
        $pillY = $placeY + 32;
        $pillTextY = $pillY + 31;

        $font = "'Plus Jakarta Sans','Inter','Segoe UI',Arial,sans-serif";
        $motif = self::motif($kind);
        $eyebrowText = EventCover::e(mb_strtoupper($eyebrow));
        $placeText = EventCover::e($place);
        $label = EventCover::e("{$name}, {$place}");

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 675" role="img" aria-label="{$label}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="{$from}"/>
      <stop offset="1" stop-color="{$to}"/>
    </linearGradient>
  </defs>
  <rect width="1200" height="675" fill="url(#bg)"/>
  <circle cx="1090" cy="590" r="260" fill="#ffffff" opacity="0.05"/>
  <circle cx="100" cy="80" r="190" fill="#e3a955" opacity="0.08"/>
  <circle cx="600" cy="150" r="66" fill="#ffffff" opacity="0.12"/>
  <g transform="translate(600 150)" fill="none" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">{$motif}</g>
  <text x="600" y="262" font-family="{$font}" font-size="22" font-weight="700" letter-spacing="5" fill="#e3a955" text-anchor="middle">{$eyebrowText}</text>
  <text font-family="{$font}" font-size="{$fontSize}" font-weight="800" fill="#ffffff" text-anchor="middle">{$titleTspans}</text>
  <text x="600" y="{$placeY}" font-family="{$font}" font-size="26" font-weight="500" fill="#f3e9df" text-anchor="middle">{$placeText}</text>
  <rect x="455" y="{$pillY}" width="290" height="46" rx="23" fill="#ffffff" opacity="0.14"/>
  <text x="600" y="{$pillTextY}" font-family="{$font}" font-size="20" font-weight="600" fill="#ffffff" text-anchor="middle">Photos coming soon</text>
</svg>
SVG;
    }

    /** Line-art icon centred on (0,0), about 70px across. */
    private static function motif(string $kind): string
    {
        return match ($kind) {
            'cafe' => '<path d="M-28 -12 H20 V14 A22 22 0 0 1 -2 36 H-6 A22 22 0 0 1 -28 14 Z"/><path d="M20 -2 H28 A10 10 0 0 1 28 18 H20"/><path d="M-14 -36 Q-8 -28 -14 -20 M0 -36 Q6 -28 0 -20"/>',
            'ramen' => '<path d="M-36 -4 H36 A36 36 0 0 1 -36 -4 Z"/><path d="M-24 -4 Q-12 -20 0 -4 Q12 -20 24 -4"/><path d="M10 -40 L30 -10 M22 -42 L38 -14"/>',
            'grill' => '<path d="M-34 -6 H34 A34 30 0 0 1 -34 -6 Z"/><path d="M-20 24 L-28 40 M20 24 L28 40"/><path d="M-14 -20 Q-8 -30 -14 -40 M0 -20 Q6 -30 0 -40 M14 -20 Q20 -30 14 -40"/>',
            'beach', 'resort' => '<circle cx="18" cy="-18" r="12"/><path d="M-38 16 Q-28 6 -18 16 T2 16 T22 16 T42 16"/><path d="M-38 32 Q-28 22 -18 32 T2 32 T22 32 T42 32"/>',
            'waterfall' => '<path d="M-36 -34 H36"/><path d="M-16 -34 V20 M0 -34 V26 M16 -34 V20"/><path d="M-38 34 Q-26 24 -14 34 T10 34 T34 34"/>',
            'mountain' => '<path d="M-40 32 L-10 -22 L8 8 L20 -8 L40 32 Z"/><path d="M-20 -4 L-10 -22 L0 -4"/>',
            'camp' => '<path d="M-36 30 L0 -34 L36 30 Z"/><path d="M0 -34 V30 M-12 30 L0 8 L12 30"/>',
            'farm' => '<path d="M0 36 V-6"/><path d="M0 -6 Q-30 -10 -30 -36 Q-4 -34 0 -6 Z"/><path d="M0 10 Q30 6 30 -20 Q4 -18 0 10 Z"/>',
            'church' => '<path d="M0 -42 V-22 M-8 -34 H8"/><path d="M-28 36 V-4 L0 -22 L28 -4 V36 Z"/><path d="M-8 36 V16 A8 8 0 0 1 8 16 V36"/>',
            default => '<path d="M-20 -36 V-6 A8 8 0 0 0 -4 -6 V-36 M-12 -36 V38"/><path d="M18 38 V-36 Q32 -24 32 0 H18"/>',
        };
    }
}
