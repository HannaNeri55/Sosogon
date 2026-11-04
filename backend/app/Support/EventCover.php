<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Designed cover card for events that have no photo: the event name, where it is
 * held and its yearly schedule on the SOSOGON ocean palette with fiesta bunting.
 * It is clearly a title card (never a stand-in photo) and can be replaced any time
 * by uploading a real image in Admin → Events.
 */
class EventCover
{
    public const DIRECTORY = 'events/covers';

    /** Store the card on the public disk and return its path (overwrites the same event's card). */
    public static function store(string $title, string $eyebrow, string $place, string $schedule): string
    {
        $path = self::DIRECTORY.'/'.Str::slug($title).'.svg';
        Storage::disk('public')->put($path, self::svg($title, $eyebrow, $place, $schedule));

        return $path;
    }

    /** True for paths this class (or the old placeholder generator) produced, i.e. safe to replace. */
    public static function isGenerated(?string $path): bool
    {
        return $path === null
            || $path === ''
            || str_starts_with($path, self::DIRECTORY.'/')
            || (bool) preg_match('#^events/[a-z0-9-]+\.svg$#', $path);
    }

    public static function svg(string $title, string $eyebrow, string $place, string $schedule): string
    {
        $lines = self::wrap($title, 26);
        if (count($lines) > 3) {
            $lines = array_slice(self::wrap($title, 34), 0, 3);
        }
        $fontSize = count($lines) >= 3 ? 50 : (count($lines) === 2 ? 58 : 64);
        $lineHeight = (int) round($fontSize * 1.18);
        $blockHeight = $lineHeight * count($lines);
        $firstBaseline = (int) round(338 - $blockHeight / 2 + $fontSize * 0.82);

        $titleTspans = '';
        foreach ($lines as $i => $line) {
            $y = $firstBaseline + $i * $lineHeight;
            $titleTspans .= sprintf('<tspan x="600" y="%d">%s</tspan>', $y, self::e($line));
        }
        $eyebrowY = $firstBaseline - $fontSize - 26;
        $placeY = $firstBaseline + ($lines ? (count($lines) - 1) * $lineHeight : 0) + 58;
        $pillY = $placeY + 34;

        // Bunting: alternating sand, green, white pennants across the top.
        $flags = '';
        $colors = ['#e3a955', '#4f9370', '#ffffff', '#3b9db5'];
        for ($i = 0, $x = -10; $x < 1210; $i++, $x += 60) {
            $sag = (int) round(18 * sin(($x / 1200) * M_PI));
            $flags .= sprintf(
                '<path d="M%d %d L%d %d L%d %d Z" fill="%s" opacity="%s"/>',
                $x, 26 + $sag, $x + 50, 26 + $sag, $x + 25, 74 + $sag, $colors[$i % 4], $i % 4 === 2 ? '0.85' : '0.95'
            );
        }

        $font = "'Plus Jakarta Sans','Inter','Segoe UI',Arial,sans-serif";
        $scheduleText = self::e($schedule);
        $pillWidth = max(260, (int) (mb_strlen($schedule) * 13.5) + 64);
        $pillX = (int) round(600 - $pillWidth / 2);
        $pillText = $pillY + 32;
        $this_eyebrow = self::e(mb_strtoupper($eyebrow));
        $place_text = self::e($place);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 675" role="img" aria-label="{$scheduleText}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0b6680"/>
      <stop offset="1" stop-color="#0c3946"/>
    </linearGradient>
  </defs>
  <rect width="1200" height="675" fill="url(#bg)"/>
  <circle cx="1080" cy="600" r="260" fill="#ffffff" opacity="0.05"/>
  <circle cx="110" cy="640" r="200" fill="#e3a955" opacity="0.07"/>
  <path d="M-10 26 Q600 62 1210 26" stroke="#ffffff" stroke-opacity="0.5" stroke-width="2" fill="none"/>
  {$flags}
  <text x="600" y="{$eyebrowY}" font-family="{$font}" font-size="22" font-weight="700" letter-spacing="5" fill="#e3a955" text-anchor="middle">{$this_eyebrow}</text>
  <text font-family="{$font}" font-size="{$fontSize}" font-weight="800" fill="#ffffff" text-anchor="middle">{$titleTspans}</text>
  <text x="600" y="{$placeY}" font-family="{$font}" font-size="26" font-weight="500" fill="#d5eef3" text-anchor="middle">{$place_text}</text>
  <rect x="{$pillX}" y="{$pillY}" width="{$pillWidth}" height="48" rx="24" fill="#ffffff" opacity="0.14"/>
  <text x="600" y="{$pillText}" font-family="{$font}" font-size="22" font-weight="600" fill="#ffffff" text-anchor="middle">{$scheduleText}</text>
</svg>
SVG;
    }

    /** @return string[] */
    public static function wrap(string $text, int $max): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";
            if (mb_strlen($candidate) > $max && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
