<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Static utility for normalizing user input before safety classification.
 *
 * Strips evasion techniques (interstitial punctuation, Unicode tricks,
 * spaced-out letters) while preserving legitimate text. Designed to run
 * BEFORE all classifier checks so that obfuscated text like "l.e.g.a.l"
 * or "s-h-o-u-l-d" is normalized to "legal" / "should".
 *
 * Key properties:
 * - Idempotent: normalize(normalize(x)) === normalize(x)
 * - Preserves legitimate hyphens: "self-help", "3-day", "U.S."
 * - No Drupal dependencies (static methods only, no DI)
 */
class InputNormalizer {

  /**
   * Applies the full normalization pipeline.
   *
 * Pipeline order:
 * 1. Unicode NFKC normalization
 * 2. Strip invisible formatting (zero-width / soft hyphen)
 * 3. Strip interstitial punctuation (l.e.g.a.l → legal)
 * 4. Collapse evasion spacing (l e g a l → legal)
 * 5. Normalize whitespace (collapse + trim)
   *
   * @param string $input
   *   Raw user input (already HTML-sanitized).
   *
   * @return string
   *   Normalized input safe for classifier pattern matching.
   */
  public static function normalize(string $input): string {
    $input = self::unicodeNfkc($input);
    $input = self::stripInvisibleFormatting($input);
    $input = self::stripInterstitialPunctuation($input);
    $input = self::collapseEvasionSpacing($input);
    $input = self::normalizeWhitespace($input);

    return $input;
  }

  /**
   * Applies Unicode NFKC normalization.
   *
   * Converts compatibility characters to their canonical equivalents.
   * Falls back gracefully if the intl extension is not available.
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   NFKC-normalized string.
   */
  public static function unicodeNfkc(string $input): string {
    if (class_exists('Normalizer')) {
      $normalized = \Normalizer::normalize($input, \Normalizer::FORM_KC);
      // Normalizer::normalize returns false on failure.
      return $normalized !== FALSE ? $normalized : $input;
    }
    return $input;
  }

  /**
   * Removes invisible formatting characters used for obfuscation.
   *
   * Examples:
   *   - "i​g​n​o​r​e" → "ignore"
   *   - "le­gal" → "legal"
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   String with zero-width and format characters removed.
   */
  public static function stripInvisibleFormatting(string $input): string {
    return preg_replace('/\p{Cf}+/u', '', $input);
  }

  /**
   * Strips interstitial punctuation used to obfuscate words.
   *
   * Detects chains of 4+ single letters separated by punctuation-like
   * delimiters and joins them into words. Optional spaces around the
   * punctuation are treated as part of the obfuscation.
   *
   * Examples:
   *   - "l.e.g.a.l" → "legal"
   *   - "l/e/g/a/l" → "legal"
   *   - "l , e , g , a , l" → "legal"
   *   - "s-h-o-u-l-d" → "should"
   *   - "a_d_v_i_c_e" → "advice"
   *
   * Preserves:
   *   - "U.S." / "A.M." (below threshold)
   *   - "self-help", "3-day" (multi-char segments)
   *   - "tenant/landlord" (multi-char segments)
   *   - contractions and possessives (not single-letter chains)
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   String with interstitial punctuation removed.
   */
  public static function stripInterstitialPunctuation(string $input): string {
    return preg_replace_callback(
      '/(?<!\p{L})(?:\p{L}(?:\s*[.\-_\/\\\\,\'":;|]\s*\p{L}){3,})(?!\p{L})/u',
      function ($matches) {
        return preg_replace('/[^\p{L}]+/u', '', $matches[0]);
      },
      $input
    );
  }

  /**
   * Collapses evasion spacing (single letters separated by spaces).
   *
   * Detects chains of 4+ single letters separated by spaces and joins
   * them. Does not affect normal single-letter words like "I" or "a"
   * in sentences (they don't form chains of 4+).
   *
   * Examples:
   *   - "l e g a l" → "legal"
   *   - "s h o u l d" → "should"
   *
   * Preserves:
   *   - "I need a form" (no 3+ chain of single letters)
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   String with evasion spacing collapsed.
   */
  public static function collapseEvasionSpacing(string $input): string {
    return preg_replace_callback(
      '/(?<!\p{L})(?:\p{L}(?:\s+\p{L}){3,})(?!\p{L})/u',
      function ($matches) {
        return preg_replace('/\s+/u', '', $matches[0]);
      },
      $input
    );
  }

  /**
   * Normalizes whitespace: collapse runs and trim.
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   Whitespace-normalized string.
   */
  public static function normalizeWhitespace(string $input): string {
    return trim(preg_replace('/\s+/', ' ', $input));
  }

}
