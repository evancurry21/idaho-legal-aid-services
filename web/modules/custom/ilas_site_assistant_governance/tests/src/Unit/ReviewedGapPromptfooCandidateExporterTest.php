<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Unit;

use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Service\ReviewedGapPromptfooCandidateExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for reviewed-gap Promptfoo candidate guardrails.
 */
#[Group('ilas_site_assistant_governance')]
final class ReviewedGapPromptfooCandidateExporterTest extends TestCase {

  /**
   * Obvious PII residue must block candidate export.
   */
  #[DataProvider('piiResidueProvider')]
  public function testContainsPiiResidueDetectsObviousPrivateData(string $text): void {
    $this->assertTrue(ReviewedGapPromptfooCandidateExporter::containsPiiResidue($text));
  }

  /**
   * Safe legal-help text should not be treated as PII.
   */
  public function testContainsPiiResidueAllowsSafeLegalQuestion(): void {
    $this->assertFalse(ReviewedGapPromptfooCandidateExporter::containsPiiResidue(
      'How do I contest a three-day eviction notice in Ada County?'
    ));
  }

  /**
   * Redaction placeholders are safe but should be flagged for human review.
   */
  public function testContainsRedactionTokenDetectsPlaceholders(): void {
    $this->assertTrue(ReviewedGapPromptfooCandidateExporter::containsRedactionToken(
      'My landlord at [REDACTED-ADDRESS] gave me papers.'
    ));
    $this->assertFalse(ReviewedGapPromptfooCandidateExporter::containsRedactionToken(
      'Can I appeal an eviction judgment?'
    ));
  }

  /**
   * Failure modes combine reviewer disposition, language, and flow context.
   */
  public function testInferFailureModesCombinesSafeMetadata(): void {
    $modes = ReviewedGapPromptfooCandidateExporter::inferFailureModes(
      'es',
      'selection',
      'family_custody',
      AssistantGapItem::RESOLUTION_CONTENT_UPDATED,
      [
        AssistantGapItem::FLAG_POTENTIAL_SEARCH_TUNING,
        AssistantGapItem::FLAG_POSSIBLE_TAXONOMY_GAP,
        AssistantGapItem::FLAG_POLICY_REVIEW,
      ],
    );

    foreach (['no_answer', 'spanish', 'retrieval', 'content_gap', 'routing', 'safety', 'conversation'] as $mode) {
      $this->assertContains($mode, $modes);
    }
  }

  /**
   * OOS dispositions create an OOS regression tag.
   */
  public function testInferFailureModesIncludesOosDisposition(): void {
    $modes = ReviewedGapPromptfooCandidateExporter::inferFailureModes(
      'en',
      'router',
      '',
      AssistantGapItem::RESOLUTION_EXPECTED_OOS,
      [],
    );

    $this->assertContains('no_answer', $modes);
    $this->assertContains('oos', $modes);
  }

  /**
   * Provides PII residue examples.
   */
  public static function piiResidueProvider(): array {
    return [
      'email' => ['Please email me at user@example.com'],
      'phone' => ['Call me at 208-555-1212'],
      'ssn' => ['My SSN is 123-45-6789'],
      'case number' => ['My case number is CV-24-123456'],
      'address' => ['I live at 123 Main Street'],
      'contextual name' => ['My name is Jane Doe'],
    ];
  }

}
