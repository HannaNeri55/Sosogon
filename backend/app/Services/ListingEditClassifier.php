<?php

namespace App\Services;

/**
 * Decides whether an owner's edit to an already-approved listing, room, menu
 * item or product is a genuinely minor correction that may go live without
 * review. Conservative by design: anything uncertain is major (reviewed).
 *
 * A change is minor only when ALL of these hold:
 *  - the owner explicitly declared it a spelling/formatting correction;
 *  - only name/description changed (every other field is material);
 *  - name: capitalization, spacing or punctuation only (identity unchanged);
 *  - description: either formatting only, or a few in-place spelling fixes,
 *    each a long word changed by at most two letters;
 *  - no number, price, phone, email or URL changed, no word added or removed,
 *    and no meaning-changing word (no/not/never/without, open/closed, …) touched.
 */
class ListingEditClassifier
{
    public const MINOR_FIELDS = ['name', 'description'];

    private const MAX_SPELLING_FIXES = 3;

    /** Words whose change alters meaning even when spelled alike. */
    private const MEANING_WORDS = [
        'no', 'not', 'non', 'never', 'none', 'without', 'only', 'all', 'free', 'paid', 'open', 'closed', 'daily',
        'weekdays', 'weekends', 'available', 'unavailable', 'included', 'excluded', 'private', 'public', 'yes',
    ];

    /**
     * @param  array  $current  live values, keyed by field
     * @param  array  $proposed  submitted values, keyed by field (only fields the owner sent)
     */
    public function isMinor(array $current, array $proposed, bool $declaredMinor): bool
    {
        if (! $declaredMinor) {
            return false;
        }

        $changed = $this->changedFields($current, $proposed);
        if ($changed === []) {
            return false;
        }

        foreach ($changed as $field) {
            if (! in_array($field, self::MINOR_FIELDS, true)) {
                return false;
            }
            $old = (string) ($current[$field] ?? '');
            $new = (string) ($proposed[$field] ?? '');
            $ok = $field === 'name' ? $this->formattingOnly($old, $new) : $this->minorText($old, $new);
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** Fields whose submitted value differs from the live value. */
    public function changedFields(array $current, array $proposed): array
    {
        $changed = [];
        foreach ($proposed as $field => $value) {
            if ($this->normalizeValue($current[$field] ?? null) !== $this->normalizeValue($value)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    public function formattingOnly(string $old, string $new): bool
    {
        return $this->protectedTokens($old) === $this->protectedTokens($new)
            && $this->words($old) === $this->words($new);
    }

    public function minorText(string $old, string $new): bool
    {
        if ($this->protectedTokens($old) !== $this->protectedTokens($new)) {
            return false;
        }

        $a = $this->words($old);
        $b = $this->words($new);
        if (count($a) !== count($b)) {
            return false; // words added or removed
        }

        $fixes = 0;
        foreach ($a as $i => $word) {
            if ($word === $b[$i]) {
                continue;
            }
            if (++$fixes > self::MAX_SPELLING_FIXES
                || in_array($word, self::MEANING_WORDS, true) || in_array($b[$i], self::MEANING_WORDS, true)
                || ! ctype_alpha($word) || ! ctype_alpha($b[$i])
                || min(strlen($word), strlen($b[$i])) < 4
                || levenshtein($word, $b[$i]) > 2) {
                return false;
            }
        }

        return true;
    }

    /** Lower-cased words with punctuation stripped. */
    private function words(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Numbers (prices, phones, hours, counts), emails and URLs must stay identical. */
    private function protectedTokens(string $text): array
    {
        preg_match_all('/\d+(?:[.,:]\d+)*|[\w.+-]+@[\w.-]+|(?:https?:\/\/|www\.)\S+/iu', mb_strtolower($text), $m);

        return $m[0];
    }

    private function normalizeValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode(array_values($value));
        }
        if (is_numeric($value)) {
            return (string) (0 + $value);
        }

        return (string) ($value ?? '');
    }
}
