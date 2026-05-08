<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional regression coverage for SEO-03 schema property emissions.
 *
 * Locks Phase 2's six deployed JSON-LD properties (foundingDate, areaServed,
 * articleSection per bundle, office areaServed) against silent regression
 * from future config changes, alter-hook edits, or contrib-module updates.
 *
 * Asserts against the rendered <script type="application/ld+json"> block on
 * representative pages — the same surface that scripts/seo/verify-schema.sh
 * checks at deploy time, but pre-merge in CI.
 *
 * Hook-order assumption: ilas_seo (alphabetically before schema_metatag) runs
 * its hook_page_attachments_alter FIRST, adding schema_metatag => TRUE flagged
 * html_head items; then schema_metatag's alter hook collects them via
 * parseJsonld and emits a single <script> block. If a future weight or rename
 * inverts the order, every assertion below fails — that IS the regression
 * detector.
 *
 * @see web/modules/custom/ilas_seo/ilas_seo.module
 * @see web/modules/contrib/schema_metatag/schema_metatag.module
 * @see scripts/seo/verify-schema.sh
 */
#[Group('ilas_seo')]
final class SchemaPropertiesTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * Note: schema_metatag MUST be in this list — it is the aggregator that
   * collects ilas_seo's flagged html_head items and emits the
   * <script type="application/ld+json"> block. Without it, parseJsonld never
   * fires and assertions against rendered JSON-LD return NULL trivially.
   * See RESEARCH §2.1 / Pitfall 1.
   */
  protected static $modules = [
    // ilas_site_assistant_action_compat MUST come first: it provides legacy
    // node action plugin IDs (node_make_sticky_action et al.) that core's
    // node module install pass references via system.action.* config but
    // whose PHP-attribute-discovered classes are not yet registered with
    // the ActionManager at install time. See deviation D-INFRA-01.
    'ilas_site_assistant_action_compat',
    'eca',
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'metatag',
    'schema_metatag',
    'schema_organization',
    'schema_article',
    'ilas_seo',
  ];

  /**
   * About-page fixture (standard_page).
   */
  private NodeInterface $aboutNode;

  /**
   * Press-entry fixture.
   */
  private NodeInterface $pressNode;

  /**
   * Resource fixture (single Housing term assigned).
   */
  private NodeInterface $resourceNode;

  /**
   * Legal-content fixture (single Housing term assigned).
   */
  private NodeInterface $legalNode;

  /**
   * Office fixture with a multi-county field_county string.
   */
  private NodeInterface $officeNode;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // 1. Bundles — guarded create per the seedBoiseOfficeNode idiom.
    $bundles = [
      'standard_page',
      'press_entry',
      'resource',
      'legal_content',
      'office_information',
    ];
    foreach ($bundles as $type) {
      if (!NodeType::load($type)) {
        NodeType::create([
          'type' => $type,
          'name' => ucwords(str_replace('_', ' ', $type)),
        ])->save();
      }
    }

    // 2. service_areas vocabulary + a single Housing term.
    if (!Vocabulary::load('service_areas')) {
      Vocabulary::create([
        'vid' => 'service_areas',
        'name' => 'Service Areas',
      ])->save();
    }
    $housing = Term::create([
      'vid' => 'service_areas',
      'name' => 'Housing',
    ]);
    $housing->save();

    // 3. Field storage — entity_reference fields on resource & legal_content.
    $reference_fields = [
      'field_service_area' => 'legal_content',
      'field_service_areas' => 'resource',
    ];
    foreach ($reference_fields as $field_name => $bundle) {
      if (!FieldStorageConfig::loadByName('node', $field_name)) {
        FieldStorageConfig::create([
          'field_name' => $field_name,
          'entity_type' => 'node',
          'type' => 'entity_reference',
          'cardinality' => 1,
          'settings' => [
            'target_type' => 'taxonomy_term',
          ],
        ])->save();
      }
      if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
        FieldConfig::create([
          'field_name' => $field_name,
          'entity_type' => 'node',
          'bundle' => $bundle,
          'label' => ucwords(str_replace('_', ' ', $field_name)),
          'settings' => [
            'handler' => 'default:taxonomy_term',
            'handler_settings' => [
              'target_bundles' => [
                'service_areas' => 'service_areas',
              ],
            ],
          ],
        ])->save();
      }
    }

    // 4. field_county on office_information — Pitfall 9: storage type is
    // string_long (NOT string). Production storage was migrated long-string;
    // matching it here keeps the alter-hook branch under realistic conditions.
    if (!FieldStorageConfig::loadByName('node', 'field_county')) {
      FieldStorageConfig::create([
        'field_name' => 'field_county',
        'entity_type' => 'node',
        'type' => 'string_long',
        'cardinality' => 1,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'office_information', 'field_county')) {
      FieldConfig::create([
        'field_name' => 'field_county',
        'entity_type' => 'node',
        'bundle' => 'office_information',
        'label' => 'County',
      ])->save();
    }

    // 5. Five published nodes.
    $this->aboutNode = Node::create([
      'type' => 'standard_page',
      'title' => 'About',
      'status' => 1,
    ]);
    $this->aboutNode->save();

    $this->pressNode = Node::create([
      'type' => 'press_entry',
      'title' => 'Test Press Entry',
      'status' => 1,
    ]);
    $this->pressNode->save();

    $this->resourceNode = Node::create([
      'type' => 'resource',
      'title' => 'Test Resource',
      'status' => 1,
      // D-COV-03: Single-value branch only. Multi-value structurally unreachable today (storage cardinality=1 despite plural field name). See .planning/todos/pending/2026-05-08-resource-field-service-areas-naming-vs-cardinality-review.md.
      'field_service_areas' => [['target_id' => $housing->id()]],
    ]);
    $this->resourceNode->save();

    $this->legalNode = Node::create([
      'type' => 'legal_content',
      'title' => 'Test Legal Content',
      'status' => 1,
      'field_service_area' => [['target_id' => $housing->id()]],
    ]);
    $this->legalNode->save();

    $this->officeNode = Node::create([
      'type' => 'office_information',
      'title' => 'Boise Office',
      'status' => 1,
      'field_county' => 'Ada, Boise, Elmore, and Valley',
    ]);
    $this->officeNode->save();
  }

  /**
   * Returns the JSON-LD @graph entry whose @type matches $type, or NULL.
   *
   * Iterates every <script type="application/ld+json"> block on the current
   * rendered page, decodes via Json::decode, unwraps the schema_metatag
   * @graph array, and returns the first entry with a matching @type.
   */
  protected function getJsonLdGraphEntry(string $type): ?array {
    foreach ($this->iterateJsonLdGraphEntries() as $entry) {
      if (($entry['@type'] ?? NULL) === $type) {
        return $entry;
      }
    }
    return NULL;
  }

  /**
   * Returns the first @graph entry that has the given property key, or NULL.
   *
   * Useful when the @type is set by a separate schema_metatag tag plugin
   * whose default config is not seeded in this minimal test environment —
   * the property survives the install pass even when @type does not.
   * See D-INFRA-02 in 03-01-SUMMARY.md.
   */
  protected function getJsonLdGraphEntryWithKey(string $key): ?array {
    foreach ($this->iterateJsonLdGraphEntries() as $entry) {
      if (array_key_exists($key, $entry)) {
        return $entry;
      }
    }
    return NULL;
  }

  /**
   * Yields every @graph entry from every JSON-LD block on the current page.
   *
   * @return iterable<array<string, mixed>>
   */
  protected function iterateJsonLdGraphEntries(): iterable {
    $html = $this->getSession()->getPage()->getContent();

    // Use [\s\S] (not .) so blocks can span newlines.
    if (!preg_match_all(
      '#<script[^>]*type="application/ld\+json"[^>]*>([\s\S]*?)</script>#',
      $html,
      $matches
    )) {
      return;
    }

    foreach ($matches[1] as $payload) {
      $decoded = Json::decode(trim((string) $payload));
      if (!is_array($decoded)) {
        continue;
      }
      // schema_metatag wraps multi-entry emissions in {"@context":..., "@graph":[...]}.
      $graph = $decoded['@graph'] ?? [$decoded];
      foreach ($graph as $entry) {
        if (is_array($entry)) {
          yield $entry;
        }
      }
    }
  }

  /**
   * Organization @graph entry must carry foundingDate and areaServed.
   */
  public function testOrganizationFoundingDateAndAreaServed(): void {
    $this->drupalGet($this->aboutNode->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // D-INFRA-02: Without seeding metatag default config in this test, the
    // schema_metatag aggregator still emits the alter-hook-injected
    // foundingDate / areaServed / articleSection properties — but no @type
    // (the @type is set by schema_organization_type tag plugin which reads
    // config). Empirical discovery: match by property presence, not @type,
    // to keep this test focused on the alter-hook surface (RESEARCH Open
    // Q1 + Q2 resolved empirically; documented in 03-01-SUMMARY.md).
    $org = $this->getJsonLdGraphEntryWithKey('foundingDate');
    $this->assertNotNull($org, 'Organization @graph entry carrying foundingDate must be present.');

    $this->assertSame('1967', (string) ($org['foundingDate'] ?? ''), 'Organization.foundingDate must equal "1967" (D-COV-01).');

    $this->assertIsArray($org['areaServed'] ?? NULL, 'Organization.areaServed must be present and structured.');
    $this->assertSame('State', $org['areaServed']['@type'] ?? NULL);
    $this->assertSame('Idaho', $org['areaServed']['name'] ?? NULL);
  }

  /**
   * press_entry articleSection must equal the literal "Press" (Phase 2 D-01).
   */
  public function testPressEntryArticleSection(): void {
    $this->drupalGet($this->pressNode->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // D-INFRA-02: match by property key (articleSection) instead of @type.
    $article = $this->getJsonLdGraphEntryWithKey('articleSection');
    $this->assertNotNull($article, 'Article @graph entry carrying articleSection must be present on press_entry.');
    $this->assertSame('Press', $article['articleSection'] ?? NULL, 'press_entry articleSection must equal "Press" (Phase 2 D-01).');
  }

  /**
   * resource articleSection must reflect the single referenced term label.
   */
  public function testResourceArticleSectionSingleValue(): void {
    $this->drupalGet($this->resourceNode->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // D-INFRA-02: match by property key.
    $article = $this->getJsonLdGraphEntryWithKey('articleSection');
    $this->assertNotNull($article, 'Article @graph entry carrying articleSection must be present on resource.');
    // D-COV-03: Single-value branch only. See setUp() comment.
    $this->assertSame('Housing', $article['articleSection'] ?? NULL);
  }

  /**
   * legal_content articleSection must equal the field_service_area term label.
   */
  public function testLegalContentArticleSection(): void {
    $this->drupalGet($this->legalNode->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // D-INFRA-02: match by property key.
    $article = $this->getJsonLdGraphEntryWithKey('articleSection');
    $this->assertNotNull($article, 'Article @graph entry carrying articleSection must be present on legal_content.');
    $this->assertSame('Housing', $article['articleSection'] ?? NULL, 'legal_content articleSection must equal the field_service_area term label.');
  }

  /**
   * office_information areaServed must carry AdministrativeArea + " County, Idaho".
   */
  public function testOfficeInformationAreaServed(): void {
    $this->drupalGet($this->officeNode->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // D-INFRA-02: The office's areaServed is emitted as an array of
    // AdministrativeArea entries (D-03 + D-04 in Phase 2). Find the @graph
    // entry whose areaServed value is a list (numeric-indexed) rather than
    // the Organization's structured State object. We can't reliably match
    // by @type without seeded metatag config.
    $officeEntry = NULL;
    foreach ($this->iterateJsonLdGraphEntries() as $entry) {
      if (!array_key_exists('areaServed', $entry)) {
        continue;
      }
      $area = $entry['areaServed'];
      if (is_array($area) && array_is_list($area) && $area !== []) {
        $officeEntry = $entry;
        break;
      }
    }
    $this->assertNotNull($officeEntry, 'Office @graph entry with list-shaped areaServed must be present.');

    /** @var array<int, array<string, mixed>> $area */
    $area = $officeEntry['areaServed'];
    $first = $area[0];
    $this->assertSame('AdministrativeArea', $first['@type'] ?? NULL);
    $this->assertStringEndsWith(' County, Idaho', (string) ($first['name'] ?? ''));
  }

}
