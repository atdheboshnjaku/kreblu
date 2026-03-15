<?php declare(strict_types=1);

/**
 * Kreblu Formatting Helpers
 *
 * Text manipulation, slug generation, excerpt creation.
 */

/**
 * Generate a URL-safe slug from a string.
 */
function os_slugify(string $text): string
{
    // Transliterate non-ASCII characters
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: $text;
    // Replace non-alphanumeric characters with hyphens
    $text = preg_replace('/[^a-z0-9]+/i', '-', strtolower($text)) ?? '';
    // Remove leading/trailing hyphens and collapse multiple hyphens
    $text = trim(preg_replace('/-+/', '-', $text) ?? '', '-');
    return $text;
}

/**
 * Generate an excerpt from HTML content.
 */
function os_excerpt(string $html, int $wordCount = 55, string $more = '&hellip;'): string
{
    $text = strip_tags($html);
    $words = preg_split('/\s+/', trim($text), $wordCount + 1);
    if ($words === false) {
        return '';
    }
    if (count($words) > $wordCount) {
        array_pop($words);
        return implode(' ', $words) . $more;
    }
    return implode(' ', $words);
}

/**
 * Truncate a string to a maximum length without breaking words.
 */
function os_truncate(string $text, int $maxLength, string $suffix = '...'): string
{
    if (mb_strlen($text) <= $maxLength) {
        return $text;
    }
    $truncated = mb_substr($text, 0, $maxLength);
    $lastSpace = mb_strrpos($truncated, ' ');
    if ($lastSpace !== false) {
        $truncated = mb_substr($truncated, 0, $lastSpace);
    }
    return $truncated . $suffix;
}

/**
 * Convert line breaks to <br> tags.
 */
function os_nl2br(string $text): string
{
    return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
}

/**
 * Escape a string for safe HTML output.
 */
function os_esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Format a date using the site's configured format.
 */
function os_format_date(string|\DateTimeInterface $date, string $format = 'F j, Y'): string
{
    if (is_string($date)) {
        $date = new \DateTimeImmutable($date);
    }
    return $date->format($format);
}

/**
 * Format a file size in bytes to human-readable format.
 */
function os_format_bytes(int $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}
