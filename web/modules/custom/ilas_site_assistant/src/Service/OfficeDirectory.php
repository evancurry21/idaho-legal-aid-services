<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Canonical, first-party office directory backed by office_information nodes.
 *
 * Replaces the historical hardcoded OfficeLocationResolver::OFFICES and
 * ResponseGrounder::OFFICIAL_CONTACTS['offices'] constants. All office address
 * and phone facts originate from published office_information entities — no
 * LLM, no vector retrieval, no third-party scraping.
 */
class OfficeDirectory {

  public const CACHE_BIN_KEY = 'ilas_site_assistant:office_directory:v1';
  public const CACHE_TAG = 'node_list:office_information';
  public const ADMIN_TITLE_NEEDLE = 'administrative';

  /**
   * Tokens that must never appear in any returned office record.
   *
   * Defense-in-depth deny-list. Stale historical addresses/phones that
   * predate the current canonical office data. If the entity layer ever
   * returns a node carrying any of these tokens, the directory either fails
   * loud (dev/test) or omits the record's details and surfaces a safe
   * /contact/offices link (prod).
   */
  public const STALE_OFFICE_TOKENS = [
    '310 N 5th',
    '310 N. 5th',
    '208-345-0106',
    '208.345.0106',
    '(208) 345-0106',
    '208-336-8980',
    '(208) 336-8980',
    '208-331-9031',
    '(208) 331-9031',
    '1424 Main',
  ];

  /**
   * Combined Boise-context regexes that flag stale Boise ZIP usage.
   *
   * 83702 is only stale when paired with Boise office context — using it as a
   * generic ZIP token would false-positive on user-supplied input. The directory
   * checks these against returned office payloads only.
   */
  public const STALE_BOISE_ZIP_PATTERNS = [
    '/\bBoise\b[^\n]{0,40}\b83702\b/i',
    '/\b83702\b[^\n]{0,40}\bBoise\b/i',
  ];

  /**
   * Per-request memo of the directory keyed by slug.
   */
  private ?array $memo = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly EnvironmentDetector $environmentDetector,
  ) {}

  /**
   * Returns all public offices keyed by slug, sorted alphabetically.
   *
   * @return array<string, array{
   *   slug: string,
   *   name: string,
   *   street: string,
   *   city: string,
   *   postal_code: string,
   *   address: string,
   *   phone: string,
   *   phone_secondary: string,
   *   hours: string,
   *   url: string,
   *   counties: string,
   *   source_nid: int,
   *   }>
   */
  public function all(): array {
    if ($this->memo !== NULL) {
      return $this->memo;
    }

    $cached = $this->cache->get(self::CACHE_BIN_KEY);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $this->memo = $cached->data;
    }

    $offices = $this->load();

    $this->cache->set(
      self::CACHE_BIN_KEY,
      $offices,
      Cache::PERMANENT,
      [self::CACHE_TAG]
    );

    return $this->memo = $offices;
  }

  /**
   * Returns the office record for a given slug, or NULL if not present.
   */
  public function get(string $slug): ?array {
    $offices = $this->all();
    return $offices[$slug] ?? NULL;
  }

  /**
   * Indicates whether the directory currently has any public offices.
   */
  public function isAvailable(): bool {
    return $this->all() !== [];
  }

  /**
   * Returns TRUE when the supplied free text contains an address that matches
   * a current office record's street, postal code, or full address.
   */
  public function isOfficialAddress(string $text): bool {
    $needle = trim(strip_tags($text));
    if ($needle === '') {
      return FALSE;
    }
    $needle_lc = mb_strtolower($needle);
    foreach ($this->all() as $office) {
      if ($office['address'] !== '' && str_contains($needle_lc, mb_strtolower($office['address']))) {
        return TRUE;
      }
      if ($office['street'] !== '' && str_contains($needle_lc, mb_strtolower($office['street']))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when the supplied free text contains a phone matching any
   * current office record's primary or secondary number.
   */
  public function isOfficialPhone(string $text): bool {
    $digits_only = preg_replace('/\D+/', '', $text) ?? '';
    if (strlen($digits_only) < 10) {
      return FALSE;
    }
    foreach ($this->all() as $office) {
      foreach (['phone', 'phone_secondary'] as $key) {
        if ($office[$key] === '') {
          continue;
        }
        $office_digits = preg_replace('/\D+/', '', $office[$key]) ?? '';
        if ($office_digits !== '' && str_contains($digits_only, $office_digits)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Asserts that a string does not contain any deny-list token.
   *
   * Used by response builders as a final scrubbing seam. Throws in dev/test
   * (CI fails loud); logs + returns FALSE in production so the caller can
   * substitute a safe /contact/offices fallback.
   *
   * @return bool
   *   TRUE when the message is clean, FALSE when stale tokens were detected
   *   (in production only — dev/test throws).
   *
   * @throws \LogicException
   *   In dev/test environments when stale tokens are detected.
   */
  public function assertNoStaleTokens(string $message, string $context = 'response'): bool {
    $hits = $this->detectStaleTokens($message);
    if ($hits === []) {
      return TRUE;
    }

    $log_context = [
      '@context' => $context,
      '@tokens' => implode(', ', $hits),
    ];
    $this->logger->error('Stale office token detected in @context: @tokens', $log_context);

    if ($this->environmentDetector->isDevOrTestEnvironment()) {
      throw new \LogicException(sprintf(
        'Stale office tokens %s detected in %s. This is a dev/test failure; investigate the data source immediately.',
        implode(', ', $hits),
        $context
      ));
    }

    return FALSE;
  }

  /**
   * Returns deny-list tokens detected in a message (case-insensitive).
   *
   * @return string[]
   */
  public function detectStaleTokens(string $message): array {
    $hits = [];
    foreach (self::STALE_OFFICE_TOKENS as $token) {
      if (stripos($message, $token) !== FALSE) {
        $hits[] = $token;
      }
    }
    foreach (self::STALE_BOISE_ZIP_PATTERNS as $pattern) {
      if (preg_match($pattern, $message) === 1) {
        $hits[] = 'boise:83702';
        break;
      }
    }
    return array_values(array_unique($hits));
  }

  /**
   * Invalidates cached directory state. Called from hook_node_*.
   */
  public function invalidate(): void {
    $this->memo = NULL;
    Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Loads canonical office data from published office_information nodes.
   *
   * @return array<string, array<string, mixed>>
   */
  private function load(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'office_information')
      ->condition('status', NodeInterface::PUBLISHED)
      ->execute();

    if ($nids === []) {
      $this->logger->warning('OfficeDirectory: no published office_information nodes found.');
      return [];
    }

    $nodes = $storage->loadMultiple($nids);
    $by_slug = [];
    $is_strict_environment = $this->environmentDetector->isDevOrTestEnvironment();

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      // Filter administrative/internal offices out of the public directory.
      $title = (string) $node->label();
      if (str_contains(mb_strtolower($title), self::ADMIN_TITLE_NEEDLE)) {
        continue;
      }

      $record = $this->buildRecordFromNode($node);
      if ($record === NULL) {
        continue;
      }

      // Defense-in-depth: ensure the canonical record itself is not poisoned.
      $serialized = implode(' | ', [
        $record['name'],
        $record['address'],
        $record['phone'],
        $record['phone_secondary'],
        $record['street'],
      ]);
      $stale_hits = $this->detectStaleTokens($serialized);
      if ($stale_hits !== []) {
        $log_ctx = [
          '@nid' => $record['source_nid'],
          '@title' => $title,
          '@tokens' => implode(', ', $stale_hits),
        ];
        $this->logger->error(
          'OfficeDirectory: poisoned node @nid (@title) carries stale tokens: @tokens',
          $log_ctx
        );
        if ($is_strict_environment) {
          throw new \LogicException(sprintf(
            'OfficeDirectory: published office_information node %d (%s) contains stale token(s): %s',
            $record['source_nid'],
            $title,
            implode(', ', $stale_hits)
          ));
        }
        // Production fallback: drop street/phone/address details, keep
        // canonical URL so the caller can render a safe /contact/offices link.
        $record['street'] = '';
        $record['address'] = '';
        $record['phone'] = '';
        $record['phone_secondary'] = '';
        $record['poisoned'] = TRUE;
      }

      $slug = $record['slug'];

      // Duplicate-slug deterministic resolution: prefer the most recently
      // updated node, log both nids.
      if (isset($by_slug[$slug])) {
        $existing = $by_slug[$slug];
        $existing_changed = (int) ($existing['changed'] ?? 0);
        $current_changed = (int) ($record['changed'] ?? 0);
        $this->logger->error(
          'OfficeDirectory: duplicate office_information slug @slug (nids @a, @b). Choosing more recently changed.',
          ['@slug' => $slug, '@a' => $existing['source_nid'], '@b' => $record['source_nid']]
        );
        if ($current_changed <= $existing_changed) {
          continue;
        }
      }

      unset($record['changed']);
      $by_slug[$slug] = $record;
    }

    ksort($by_slug);
    return $by_slug;
  }

  /**
   * Translates a single office_information node into a canonical record.
   */
  private function buildRecordFromNode(NodeInterface $node): ?array {
    $title = trim((string) $node->label());
    if ($title === '') {
      return NULL;
    }

    $street = $this->fieldValue($node, 'field_street_address');
    $city = $this->fieldValue($node, 'field_address_city');
    $postal = $this->fieldValue($node, 'field_postal_code');
    $hours = $this->fieldValue($node, 'field_office_hours');
    $counties = $this->fieldValue($node, 'field_county');
    $phone_raw = $this->fieldValue($node, 'field_phone_number');

    [$phone_primary, $phone_secondary] = $this->extractPhones($phone_raw);

    $url = '/contact/offices';
    try {
      $url = $node->toUrl('canonical', ['absolute' => FALSE])->toString();
    }
    catch (\Throwable $e) {
      // Path alias not yet available (e.g., during install). Keep fallback.
    }

    $slug = $this->slugFromTitle($title);

    $address = trim(implode(', ', array_filter([$street, trim($city . ' ' . $postal)])));

    return [
      'slug' => $slug,
      'name' => $this->cleanOfficeName($title),
      'street' => $street,
      'city' => $city,
      'postal_code' => $postal,
      'address' => $address,
      'phone' => $phone_primary,
      'phone_secondary' => $phone_secondary,
      'hours' => $hours,
      'url' => $url,
      'counties' => $counties,
      'source_nid' => (int) $node->id(),
      'changed' => (int) $node->getChangedTime(),
      'poisoned' => FALSE,
    ];
  }

  /**
   *
   */
  private function fieldValue(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    $raw = (string) $node->get($field_name)->value;
    return trim(html_entity_decode(strip_tags($raw)));
  }

  /**
   * Extracts up to two readable US-style phone numbers from a node field.
   *
   * Office phone fields contain markup like:
   *   Direct: <a href="tel:+12089040620">(208)&nbsp;904&#8209;0620</a><br>
   *
   * @return array{0: string, 1: string}
   *   [primary, secondary]; either may be empty.
   */
  private function extractPhones(string $raw): array {
    if ($raw === '') {
      return ['', ''];
    }

    $decoded = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5));
    // Replace non-breaking and zero-width hyphens.
    $decoded = strtr($decoded, [
      "\xC2\xA0" => ' ',
      "\xE2\x80\x91" => '-',
      "\xE2\x80\x90" => '-',
    ]);

    $matches = [];
    preg_match_all('/\(?2\d{2}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/', $decoded, $matches);
    $found = array_values(array_unique($matches[0] ?? []));

    $normalized = [];
    foreach ($found as $candidate) {
      $digits = preg_replace('/\D+/', '', $candidate) ?? '';
      if (strlen($digits) === 10) {
        $normalized[] = sprintf('%s-%s-%s',
          substr($digits, 0, 3),
          substr($digits, 3, 3),
          substr($digits, 6, 4)
        );
      }
    }

    return [
      $normalized[0] ?? '',
      $normalized[1] ?? '',
    ];
  }

  /**
   * Strips the trailing "Office" suffix from a node title for display.
   */
  private function cleanOfficeName(string $title): string {
    return trim((string) preg_replace('/\s+Office$/i', '', $title));
  }

  /**
   * Derives a stable slug from a node title.
   *
   * "Boise Office"          -> "boise"
   * "Coeur d'Alene Office"  -> "coeur_dalene"
   * "Twin Falls Office"     -> "twin_falls"
   * "Idaho Falls Office"    -> "idaho_falls"
   */
  private function slugFromTitle(string $title): string {
    $name = $this->cleanOfficeName($title);
    $lower = mb_strtolower($name);
    $lower = strtr($lower, ["'" => '']);
    $slug = preg_replace('/[^a-z0-9]+/u', '_', $lower) ?? '';
    return trim($slug, '_');
  }

}
