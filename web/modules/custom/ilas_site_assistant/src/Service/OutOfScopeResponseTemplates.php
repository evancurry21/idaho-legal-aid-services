<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Response templates for out-of-scope queries.
 *
 * Provides standardized, helpful responses that:
 * - Briefly explain why ILAS cannot help (not legal advice)
 * - Suggest appropriate next steps when possible
 * - Politely decline when necessary
 * - Direct to emergency services when appropriate.
 */
class OutOfScopeResponseTemplates {

  use StringTranslationTrait;

  /**
   * Gets the canonical URLs for links.
   *
   * @return array
   *   Array of canonical URLs.
   */
  protected function getCanonicalUrls(): array {
    if (function_exists('ilas_site_assistant_get_canonical_urls')) {
      return ilas_site_assistant_get_canonical_urls();
    }
    return [
      'apply' => '/apply-for-help',
      'hotline' => '/Legal-Advice-Line',
      'offices' => '/contact/offices',
      'feedback' => '/get-involved/feedback',
      'forms' => '/forms',
      'guides' => '/guides',
      'resources' => '/what-we-do/resources',
      'services' => '/services',
    ];
  }

  /**
   * Gets the appropriate response for an out-of-scope classification.
   *
   * @param array $classification
   *   The classification result from OutOfScopeClassifier.
   *
   * @return array
   *   Response array with keys:
   *   - 'type' (string): Response type (out_of_scope).
   *   - 'response_mode' (string): out_of_scope.
   *   - 'message' (string): Response message.
   *   - 'links' (array): Action links.
   *   - 'suggestions' (array): External resource suggestions.
   *   - 'reason_code' (string): Reason code for logging.
   *   - 'disclaimer' (string|null): Any required disclaimer.
   *   - 'can_still_help' (bool): Whether ILAS might help with related issues.
   */
  public function getResponse(array $classification): array {
    $category = $classification['category'];
    $reason_code = $classification['reason_code'];
    $suggestions = $classification['suggestions'] ?? [];
    $urls = $this->getCanonicalUrls();

    switch ($category) {
      case OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE:
        return $this->getCriminalDefenseResponse($urls, $reason_code, $suggestions);

      case OutOfScopeClassifier::CATEGORY_IMMIGRATION:
        return $this->getImmigrationResponse($urls, $reason_code, $suggestions);

      case OutOfScopeClassifier::CATEGORY_NON_IDAHO:
        return $this->getNonIdahoResponse($urls, $reason_code, $suggestions);

      case OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES:
        return $this->getEmergencyServicesResponse($urls, $reason_code, $suggestions);

      case OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL:
        return $this->getBusinessCommercialResponse($urls, $reason_code, $suggestions);

      case OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS:
        return $this->getFederalMattersResponse($urls, $reason_code, $suggestions);

      case OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL:
        return $this->getHighValueCivilResponse($urls, $reason_code, $suggestions);

      default:
        return $this->getInScopeResponse($urls, $reason_code);
    }
  }

  /**
   * Response for criminal defense queries.
   */
  protected function getCriminalDefenseResponse(array $urls, string $reason_code, array $suggestions): array {
    return [
      'type' => 'escalation',
      'response_mode' => 'out_of_scope',
      'escalation_type' => 'criminal_defense',
      'message' => (string) $this->t("Idaho Legal Aid Services provides civil legal help only. We cannot assist with criminal matters including arrests, charges, or criminal defense.

For criminal cases:
- If you cannot afford an attorney, you may qualify for a public defender
- The Idaho State Bar can help you find a criminal defense attorney

If you also have civil legal issues (housing, family law, benefits), we may be able to help with those separately."),
      'links' => [
        ['label' => $this->t('Idaho State Bar Lawyer Referral'), 'url' => 'https://isb.idaho.gov/ilrs/', 'type' => 'external'],
        ['label' => $this->t('Our Services (Civil)'), 'url' => $urls['services'], 'type' => 'services'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'suggestions' => $suggestions,
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('This is general information, not legal advice. ILAS provides civil legal services only.'),
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Response for immigration queries.
   */
  protected function getImmigrationResponse(array $urls, string $reason_code, array $suggestions): array {
    return [
      'type' => 'escalation',
      'response_mode' => 'out_of_scope',
      'escalation_type' => 'immigration',
      'message' => (string) $this->t("Idaho Legal Aid Services does not handle immigration matters. For help with visas, green cards, citizenship, deportation, or other immigration issues, please contact:

- An immigration attorney
- Catholic Charities of Idaho (offers immigration legal services)
- Idaho Commission on Hispanic Affairs

If you have civil legal issues in Idaho (housing, family, benefits), we may be able to help with those."),
      'links' => [
        ['label' => $this->t('Catholic Charities Idaho'), 'url' => 'https://www.ccidaho.org/', 'type' => 'external'],
        ['label' => $this->t('Idaho Commission on Hispanic Affairs'), 'url' => 'https://icha.idaho.gov/', 'type' => 'external'],
        ['label' => $this->t('Our Services'), 'url' => $urls['services'], 'type' => 'services'],
      ],
      'suggestions' => $suggestions,
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('This is general information, not legal advice. ILAS does not provide immigration legal services.'),
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Response for non-Idaho jurisdiction queries.
   */
  protected function getNonIdahoResponse(array $urls, string $reason_code, array $suggestions): array {
    return [
      'type' => 'escalation',
      'response_mode' => 'out_of_scope',
      'escalation_type' => 'non_idaho',
      'message' => (string) $this->t("Idaho Legal Aid Services can only help with legal matters in Idaho. If you're in another state, LawHelp.org can connect you with legal aid in your area.

Each state has its own legal aid organization that can help with:
- Housing issues
- Family law
- Public benefits
- Consumer problems

Search for \"[Your State] Legal Aid\" to find help near you."),
      'links' => [
        ['label' => $this->t('LawHelp.org (Find Legal Aid)'), 'url' => 'https://www.lawhelp.org/', 'type' => 'external'],
        ['label' => $this->t('ILAS Service Area'), 'url' => $urls['offices'], 'type' => 'offices'],
      ],
      'suggestions' => $suggestions,
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('ILAS serves Idaho residents with Idaho legal matters only.'),
      'can_still_help' => FALSE,
    ];
  }

  /**
   * Response for emergency services queries.
   */
  protected function getEmergencyServicesResponse(array $urls, string $reason_code, array $suggestions): array {
    return [
      'type' => 'escalation',
      'response_mode' => 'out_of_scope',
      'escalation_type' => 'emergency_services',
      'message' => (string) $this->t("If this is an emergency, please call 911 immediately.

I cannot dispatch emergency services or contact police, fire, or ambulance. For immediate help with:
- Medical emergencies - Call 911
- Fire - Call 911
- Crime in progress - Call 911
- Overdose - Call 911 (Good Samaritan Law protects callers)

After the emergency is resolved, ILAS can help with related legal issues such as protection orders, housing problems, or benefits."),
      'links' => [
        ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
        ['label' => $this->t('Poison Control: 1-800-222-1222'), 'url' => 'tel:1-800-222-1222', 'type' => 'emergency'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
      ],
      'suggestions' => $suggestions,
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Response for business/commercial queries.
   */
  protected function getBusinessCommercialResponse(array $urls, string $reason_code, array $suggestions): array {
    return [
      'type' => 'escalation',
      'response_mode' => 'out_of_scope',
      'escalation_type' => 'business_commercial',
      'message' => (string) $this->t("Idaho Legal Aid Services helps individuals with personal civil legal matters. We cannot assist with business formation, commercial contracts, or intellectual property.

For business legal help:
- Idaho State Bar Lawyer Referral Service
- Idaho Small Business Development Center (free business counseling)
- Secretary of State for business registration

If you have a personal legal issue (tenant rights, family law, consumer complaint), we may be able to help."),
      'links' => [
        ['label' => $this->t('Idaho State Bar Lawyer Referral'), 'url' => 'https://isb.idaho.gov/ilrs/', 'type' => 'external'],
        ['label' => $this->t('Idaho SBDC'), 'url' => 'https://idahosbdc.org/', 'type' => 'external'],
        ['label' => $this->t('Our Services'), 'url' => $urls['services'], 'type' => 'services'],
      ],
      'suggestions' => $suggestions,
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('ILAS provides civil legal services to low-income individuals, not businesses.'),
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Response for federal matters queries.
   */
  protected function getFederalMattersResponse(array $urls, string $reason_code, array $suggestions): array {
    // Customize message based on specific federal matter.
    $is_bankruptcy = str_contains($reason_code, 'bankruptcy');
    $is_tax = str_contains($reason_code, 'irs') || str_contains($reason_code, 'tax');
    $is_va = str_contains($reason_code, 'va');
    $is_ss = str_contains($reason_code, 'ss');

    if ($is_bankruptcy) {
      $message = (string) $this->t("Idaho Legal Aid Services does not handle bankruptcy cases. Bankruptcy requires specialized knowledge of federal law.

For bankruptcy help:
- Contact a bankruptcy attorney (many offer free consultations)
- Idaho Bankruptcy Court for self-help resources
- Idaho State Bar Lawyer Referral Service

If you have related civil issues (debt collection harassment, foreclosure), we may be able to help with those.");
    }
    elseif ($is_tax) {
      $message = (string) $this->t("Idaho Legal Aid Services does not handle IRS or tax matters. These require specialized tax attorneys or enrolled agents.

For tax help:
- IRS Taxpayer Advocate Service (free help with IRS problems)
- VITA (Volunteer Income Tax Assistance) for free tax preparation
- Idaho State Bar Lawyer Referral for tax attorneys

If you have other civil legal issues, we may be able to help.");
    }
    elseif ($is_va) {
      $message = (string) $this->t("For VA benefits issues, specialized veterans organizations may be able to help:
- Idaho Division of Veterans Services
- Disabled American Veterans (DAV)
- Veterans of Foreign Wars (VFW) - offers free claims help

ILAS can help with other civil legal issues like housing or family law.");
    }
    elseif ($is_ss) {
      $message = (string) $this->t("Social Security disability appeals are complex and often require specialized representation.

For SSI/SSDI appeals:
- Many disability attorneys work on contingency (no upfront cost)
- Idaho State Bar Lawyer Referral Service
- Disability Rights Idaho

ILAS may help with initial applications in some cases. Please call our Legal Advice Line to discuss.");
    }
    else {
      $message = (string) $this->t("This appears to involve federal law, which often requires specialized legal help. The Idaho State Bar Lawyer Referral Service can help you find an appropriate attorney.

If you also have state-level civil legal issues (housing, family, benefits), ILAS may be able to help with those.");
    }

    return [
      'type' => 'escalation',
      'response_mode' => 'out_of_scope',
      'escalation_type' => 'federal_matters',
      'message' => $message,
      'links' => [
        ['label' => $this->t('Idaho State Bar Lawyer Referral'), 'url' => 'https://isb.idaho.gov/ilrs/', 'type' => 'external'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'suggestions' => $suggestions,
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('This is general information, not legal advice.'),
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Response for high-value civil matter queries.
   */
  protected function getHighValueCivilResponse(array $urls, string $reason_code, array $suggestions): array {
    $is_personal_injury = str_contains($reason_code, 'personal_injury') ||
                          str_contains($reason_code, 'auto_accident') ||
                          str_contains($reason_code, 'slip_fall') ||
                          str_contains($reason_code, 'med_mal') ||
                          str_contains($reason_code, 'wrongful_death');

    if ($is_personal_injury) {
      $message = (string) $this->t("Personal injury cases typically involve potential monetary damages that are outside ILAS's scope. However, most personal injury attorneys work on contingency, meaning you pay nothing unless you win.

For personal injury help:
- Idaho Trial Lawyers Association maintains a directory
- Idaho State Bar Lawyer Referral Service
- Most injury attorneys offer free consultations

If you have other civil legal issues, ILAS may be able to help.");
    }
    else {
      $message = (string) $this->t("Cases involving large monetary claims or specialized areas like workers' compensation typically require private attorneys who specialize in these areas.

For help finding an attorney:
- Idaho State Bar Lawyer Referral Service
- Many attorneys offer free initial consultations
- Some work on contingency (no upfront payment)

If you have other civil legal issues (housing, family, benefits), ILAS may be able to help with those.");
    }

    return [
      'type' => 'escalation',
      'response_mode' => 'out_of_scope',
      'escalation_type' => 'high_value_civil',
      'message' => $message,
      'links' => [
        ['label' => $this->t('Idaho State Bar Lawyer Referral'), 'url' => 'https://isb.idaho.gov/ilrs/', 'type' => 'external'],
        ['label' => $this->t('Idaho Trial Lawyers Association'), 'url' => 'https://www.itla.org/', 'type' => 'external'],
        ['label' => $this->t('Our Services'), 'url' => $urls['services'], 'type' => 'services'],
      ],
      'suggestions' => $suggestions,
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('This is general information, not legal advice.'),
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Response for in-scope queries (passthrough).
   */
  protected function getInScopeResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'in_scope',
      'response_mode' => 'in_scope',
      'escalation_type' => NULL,
      'message' => NULL,
      'links' => [],
      'suggestions' => [],
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Gets a brief explanation for an out-of-scope category.
   *
   * For use in more compact response formats.
   *
   * @param string $category
   *   The out-of-scope category.
   *
   * @return string
   *   Brief explanation.
   */
  public function getBriefExplanation(string $category): string {
    $explanations = [
      OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE => (string) $this->t('ILAS handles civil legal matters only, not criminal cases.'),
      OutOfScopeClassifier::CATEGORY_IMMIGRATION => (string) $this->t('ILAS does not handle immigration cases.'),
      OutOfScopeClassifier::CATEGORY_NON_IDAHO => (string) $this->t('ILAS can only help with legal matters in Idaho.'),
      OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES => (string) $this->t('For emergencies, please call 911.'),
      OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL => (string) $this->t('ILAS helps individuals, not businesses.'),
      OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS => (string) $this->t('This federal matter requires specialized legal help.'),
      OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL => (string) $this->t('This type of case is typically handled by private attorneys.'),
    ];

    return $explanations[$category] ?? (string) $this->t('This matter is outside our service area.');
  }

  /**
   * Gets Spanish translation of a brief explanation.
   *
   * @param string $category
   *   The out-of-scope category.
   *
   * @return string
   *   Brief explanation in Spanish.
   */
  public function getBriefExplanationSpanish(string $category): string {
    $explanations = [
      OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE => 'ILAS solo maneja asuntos legales civiles, no casos criminales.',
      OutOfScopeClassifier::CATEGORY_IMMIGRATION => 'ILAS no maneja casos de inmigracion.',
      OutOfScopeClassifier::CATEGORY_NON_IDAHO => 'ILAS solo puede ayudar con asuntos legales en Idaho.',
      OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES => 'Para emergencias, llame al 911.',
      OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL => 'ILAS ayuda a individuos, no a empresas.',
      OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS => 'Este asunto federal requiere ayuda legal especializada.',
      OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL => 'Este tipo de caso generalmente es manejado por abogados privados.',
    ];

    return $explanations[$category] ?? 'Este asunto esta fuera de nuestra area de servicio.';
  }

}
