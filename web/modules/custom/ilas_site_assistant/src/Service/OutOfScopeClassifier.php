<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Deterministic out-of-scope classifier for the ILAS Site Assistant.
 *
 * Identifies queries that fall outside Idaho Legal Aid Services' scope,
 * including criminal defense, immigration, non-Idaho jurisdiction,
 * and emergency services requests.
 *
 * Out-of-Scope Categories:
 * 1. Criminal Defense - Criminal charges, defense, public defender
 * 2. Immigration - Visa, deportation, citizenship, asylum
 * 3. Non-Idaho Jurisdiction - Other states, federal-only matters
 * 4. Emergency Services - 911, fire, ambulance, immediate danger
 * 5. Business/Commercial - LLC, incorporation, patents, trademarks
 * 6. Federal Matters - IRS disputes, federal benefits appeals, bankruptcy
 * 7. High-Value Civil - Large monetary disputes, personal injury
 *
 * This classifier is deterministic and runs without LLM calls.
 */
class OutOfScopeClassifier {

  /**
   * Out-of-scope category constants.
   */
  const CATEGORY_CRIMINAL_DEFENSE = 'criminal_defense';
  const CATEGORY_IMMIGRATION = 'immigration';
  const CATEGORY_NON_IDAHO = 'non_idaho';
  const CATEGORY_EMERGENCY_SERVICES = 'emergency_services';
  const CATEGORY_BUSINESS_COMMERCIAL = 'business_commercial';
  const CATEGORY_FEDERAL_MATTERS = 'federal_matters';
  const CATEGORY_HIGH_VALUE_CIVIL = 'high_value_civil';
  const CATEGORY_IN_SCOPE = 'in_scope';

  /**
   * Response type constants.
   */
  const RESPONSE_DECLINE_POLITELY = 'decline_politely';
  const RESPONSE_REDIRECT = 'redirect';
  const RESPONSE_SUGGEST_EMERGENCY = 'suggest_emergency';
  const RESPONSE_IN_SCOPE = 'in_scope';

  /**
   * The config factory.
   *
   * @var object
   */
  protected $configFactory;

  /**
   * Classification rules organized by category.
   *
   * @var array
   */
  protected array $rules;

  /**
   * Constructs an OutOfScopeClassifier object.
   *
   * @param object $config_factory
   *   The config factory (Drupal ConfigFactoryInterface or mock for testing).
   */
  public function __construct($config_factory = NULL) {
    $this->configFactory = $config_factory;
    $this->initializeRules();
  }

  /**
   * Initializes classification rules.
   */
  protected function initializeRules(): void {
    $this->rules = [
      // Priority 1: Emergency Services - Must redirect to 911/emergency.
      'emergency_services' => [
        'category' => self::CATEGORY_EMERGENCY_SERVICES,
        'response_type' => self::RESPONSE_SUGGEST_EMERGENCY,
        'patterns' => [
          // Explicit emergency requests.
          '/\b(call\s*(the\s*)?(police|cops?|911)|need\s*(the\s*)?(police|cops?|fire\s*department))\b/i' => 'oos_emergency_police',
          '/\b(need\s*(an?\s*)?ambulance|call\s*(an?\s*)?ambulance)\b/i' => 'oos_emergency_ambulance',
          '/\b(emergency|urgent)\s*(medical|health)\s*(help|assistance|care)\b/i' => 'oos_emergency_medical',
          '/\b(heart\s*attack|stroke|can\'?t\s*breathe|bleeding\s*(badly|heavily|out)|unconscious)\b/i' => 'oos_emergency_medical_crisis',
          '/\b(house\s*(is\s*)?(on\s*)?fire|building\s*(is\s*)?(on\s*)?fire|fire\s*department)\b/i' => 'oos_emergency_fire',
          '/\b(someone\s*(is\s*)?(breaking|broke)\s*in|home\s*invasion|intruder\s*(in\s*)?my)\b/i' => 'oos_emergency_intrusion',
          '/\b(active\s*shooter|someone\s*(has|with)\s*a\s*gun)\b/i' => 'oos_emergency_active_threat',
          '/\b(overdos(e|ing)|drug\s*overdose|od\'?ing)\b/i' => 'oos_emergency_overdose',
          // Spanish emergency.
          '/\b(llam(a|e|en)\s*(a\s*)?(la\s*)?(policia|ambulancia)|emergencia\s*medica)\b/i' => 'oos_emergency_spanish',
        ],
      ],

      // Priority 2: Criminal Defense - ILAS is civil only.
      'criminal_defense' => [
        'category' => self::CATEGORY_CRIMINAL_DEFENSE,
        'response_type' => self::RESPONSE_DECLINE_POLITELY,
        'patterns' => [
          // Arrest and charges.
          '/\b(i\s*(was|got|\'ve\s*been|have\s*been)\s*arrest(ed)?)\b/i' => 'oos_criminal_arrested',
          '/\b(charged\s*with|facing\s*charges?|criminal\s*charge)\b/i' => 'oos_criminal_charged',
          '/\b(accused\s*of\s*(a\s*)?(crime|felony|misdemeanor|theft|assault|battery|robbery|burglary))\b/i' => 'oos_criminal_accused',
          // Specific crimes.
          '/\b(dui|dwi|drunk\s*driv(ing|er)|driving\s*(under|while)\s*(the\s*)?(influence|intoxicated))\b/i' => 'oos_criminal_dui',
          '/\b(drug\s*(possession|charge|crime)|possessi(on|ng)\s*(of\s*)?(drugs?|controlled\s*substance|marijuana|meth|cocaine|heroin))\b/i' => 'oos_criminal_drugs',
          '/\b(assault\s*(charge|case)|battery\s*(charge|case)|violent\s*crime)\b/i' => 'oos_criminal_assault',
          '/\b(theft\s*(charge|case)|shoplifting|burglary|robbery|larceny)\b/i' => 'oos_criminal_theft',
          '/\b(sex(ual)?\s*(offense|crime|charge)|indecent\s*exposure)\b/i' => 'oos_criminal_sex_offense',
          '/\b(murder|manslaughter|homicide)\b/i' => 'oos_criminal_homicide',
          '/\b(domestic\s*violence\s*charge|dv\s*charge)\b/i' => 'oos_criminal_dv_charge',
          '/\b(stalking\s*charge|harassment\s*charge)\b/i' => 'oos_criminal_harassment_charge',
          '/\b(trespassing|trespass\s*charge|criminal\s*trespass)\b/i' => 'oos_criminal_trespass',
          '/\b(fraud\s*charge|embezzlement|white\s*collar\s*crime)\b/i' => 'oos_criminal_fraud',
          // Criminal proceedings.
          '/\b(plea\s*(deal|bargain|agreement)|taking\s*a\s*plea|plea\s*offer)\b/i' => 'oos_criminal_plea',
          '/\b(arraignment|preliminary\s*hearing|grand\s*jury|indictment)\b/i' => 'oos_criminal_proceeding',
          '/\b(bail|bond\s*hearing|bail\s*bond|get\s*out\s*of\s*jail)\b/i' => 'oos_criminal_bail',
          '/\b(criminal\s*trial|jury\s*trial\s*for\s*(criminal|my\s*charge))\b/i' => 'oos_criminal_trial',
          '/\b(sentencing\s*(hearing)?|facing\s*prison|facing\s*jail\s*time)\b/i' => 'oos_criminal_sentencing',
          // Incarceration.
          '/\b(i\'?m\s*(in|at)\s*(jail|prison)|currently\s*(in|incarcerated))\b/i' => 'oos_criminal_incarcerated',
          '/\b(locked\s*up|behind\s*bars|doing\s*time|serving\s*time)\b/i' => 'oos_criminal_incarcerated_slang',
          '/\b(inmate|prisoner|incarcerated\s*person)\b/i' => 'oos_criminal_inmate',
          // Probation/Parole.
          '/\b(probation\s*(officer|violation|hearing)|violated\s*(my\s*)?probation)\b/i' => 'oos_criminal_probation',
          '/\b(parole\s*(officer|violation|hearing|board)|violated\s*(my\s*)?parole)\b/i' => 'oos_criminal_parole',
          '/\b(on\s*probation|on\s*parole|conditional\s*release)\b/i' => 'oos_criminal_supervised',
          // Representation.
          '/\b(public\s*defender|criminal\s*defense\s*(attorney|lawyer))\b/i' => 'oos_criminal_representation',
          '/\b(need\s*a\s*(criminal\s*)?(defense\s*)?(attorney|lawyer)\s*for\s*(my\s*)?(arrest|charge|case))\b/i' => 'oos_criminal_need_lawyer',
          // Record.
          '/\b(criminal\s*record|rap\s*sheet|background\s*check\s*(shows|found))\b/i' => 'oos_criminal_record',
          '/\b(expung(e|ement|ing)|seal\s*(my\s*)?(record|conviction)|clear\s*my\s*record)\b/i' => 'oos_criminal_expungement',
          // Warrant.
          '/\b(warrant\s*(for\s*my\s*arrest|out\s*for\s*me)|there\'?s?\s*a\s*warrant)\b/i' => 'oos_criminal_warrant',
          // Spanish criminal.
          '/\b(me\s*arrest(aron|o)|cargos\s*criminales|defensa\s*criminal)\b/i' => 'oos_criminal_spanish',
        ],
      ],

      // Priority 3: Immigration - ILAS does not handle immigration.
      'immigration' => [
        'category' => self::CATEGORY_IMMIGRATION,
        'response_type' => self::RESPONSE_DECLINE_POLITELY,
        'patterns' => [
          // Visa matters.
          '/\b(visa\s*(application|status|denied|expired|renewal|extension))\b/i' => 'oos_immigration_visa',
          '/\b(work\s*visa|student\s*visa|tourist\s*visa|h1b|h-1b|f1|f-1|j1|j-1)\b/i' => 'oos_immigration_visa_type',
          '/\b(my\s*visa\s*(was\s*)?(denied|rejected|expired|revoked))\b/i' => 'oos_immigration_visa_denied',
          '/\b(appeal\s*(a\s*)?visa\s*(denial)?|visa\s*appeal)\b/i' => 'oos_immigration_visa_appeal',
          // Green card.
          '/\b(green\s*card|permanent\s*residen(t|ce|cy))\b/i' => 'oos_immigration_green_card',
          '/\b(apply\s*(for\s*)?(a\s*)?green\s*card|get\s*(a\s*)?green\s*card)\b/i' => 'oos_immigration_green_card_apply',
          '/\b(green\s*card\s*(through|via|by)\s*(marriage|work|family))\b/i' => 'oos_immigration_green_card_path',
          '/\b(adjustment\s*of\s*status|i-485)\b/i' => 'oos_immigration_aos',
          // Citizenship/Naturalization.
          '/\b(citizenship|naturalization|become\s*a\s*citizen)\b/i' => 'oos_immigration_citizenship',
          '/\b(citizenship\s*test|naturalization\s*(test|interview|ceremony))\b/i' => 'oos_immigration_naturalization',
          '/\b(n-400|citizenship\s*application)\b/i' => 'oos_immigration_n400',
          // Deportation/Removal.
          '/\b(deportation|deport(ed)?|removal\s*proceedings?|facing\s*removal)\b/i' => 'oos_immigration_deportation',
          '/\b(ice\s*(detention|came|raid|arrested|took|detained))\b/i' => 'oos_immigration_ice',
          '/\b(immigration\s*(court|judge|hearing|detention))\b/i' => 'oos_immigration_court',
          '/\b(notice\s*to\s*appear|nta)\b/i' => 'oos_immigration_nta',
          '/\b(voluntary\s*departure|self-?deport(ation)?)\b/i' => 'oos_immigration_voluntary',
          // Asylum/Refugee.
          '/\b(asylum|refugee\s*status|fleeing\s*persecution)\b/i' => 'oos_immigration_asylum',
          '/\b(i-589|asylum\s*(application|interview|hearing))\b/i' => 'oos_immigration_asylum_process',
          '/\b(withholding\s*of\s*removal|convention\s*against\s*torture)\b/i' => 'oos_immigration_protection',
          // Status issues.
          '/\b(undocumented|illegal\s*(immigrant|alien)?|without\s*papers)\b/i' => 'oos_immigration_undocumented',
          '/\b(here\s*illegally|overstay(ed)?|out\s*of\s*status)\b/i' => 'oos_immigration_overstay',
          '/\b(daca|dreamer|tps|temporary\s*protected\s*status)\b/i' => 'oos_immigration_daca_tps',
          // Family immigration.
          '/\b(sponsor\s*(a\s*)?(family\s*member|spouse|relative))\b/i' => 'oos_immigration_sponsor',
          '/\b(family\s*(visa|petition|immigration)|i-130)\b/i' => 'oos_immigration_family',
          '/\b(bring\s*(my\s*)?(spouse|family|children|parents)\s*(to|from))\b/i' => 'oos_immigration_bring_family',
          // General immigration.
          '/\b(immigration\s*(case|lawyer|attorney|help|question|issue|problem))\b/i' => 'oos_immigration_general',
          '/\b(uscis|immigration\s*services|ins)\b/i' => 'oos_immigration_uscis',
          // Spanish immigration.
          '/\b(inmigraci[oó]n|tarjeta\s*verde|deportaci[oó]n|ciudadan[ií]a)\b/i' => 'oos_immigration_spanish',
          '/\b(indocumentado|sin\s*papeles)\b/i' => 'oos_immigration_spanish_undocumented',
        ],
      ],

      // Priority 4: Non-Idaho Jurisdiction - Other states.
      'non_idaho' => [
        'category' => self::CATEGORY_NON_IDAHO,
        'response_type' => self::RESPONSE_REDIRECT,
        'patterns' => [
          // Western US states.
          '/\b(i\'?m?\s*(in|from|live\s*(in)?)\s*(oregon|washington|montana|nevada|utah|wyoming|california|arizona|colorado|new\s*mexico))\b/i' => 'oos_location_western',
          '/\b(i\s*(am|\'m)\s*(in|from)\s*(oregon|washington|montana|nevada|utah|wyoming|california|arizona|colorado|new\s*mexico))\b/i' => 'oos_location_western_explicit',
          // Other US states (common neighbors and large states).
          '/\b(i\'?m?\s*(in|from|live\s*(in)?)\s*(texas|florida|new\s*york|illinois|ohio|pennsylvania|georgia|michigan))\b/i' => 'oos_location_other',
          // Generic other state.
          '/\b(i\s*(live|am|\'m)\s*(in|from)\s*another\s*state)\b/i' => 'oos_location_another_state',
          '/\b(not\s*(in|from)\s*idaho|outside\s*(of\s*)?idaho)\b/i' => 'oos_location_not_idaho',
          // Specific state legal aid requests.
          '/\b((oregon|washington|montana|california)\s*legal\s*aid)\b/i' => 'oos_location_other_legal_aid',
          // State-specific laws.
          '/\b((oregon|washington|montana|california|nevada|utah|wyoming)\s*(law|code|statute|court))\b/i' => 'oos_location_other_state_law',
          // International.
          '/\b(i\'?m?\s*(in|from|live\s*(in)?)\s*(canada|mexico|uk|england|australia|germany|india|china|philippines))\b/i' => 'oos_location_international',
          '/\b(another\s*country|outside\s*(the\s*)?(us|usa|united\s*states))\b/i' => 'oos_location_international_generic',
        ],
      ],

      // Priority 5: Business/Commercial - Not low-income civil.
      'business_commercial' => [
        'category' => self::CATEGORY_BUSINESS_COMMERCIAL,
        'response_type' => self::RESPONSE_DECLINE_POLITELY,
        'patterns' => [
          // Business formation.
          '/\b(start(ing)?\s*(a|an|my)?\s*(llc|business|company|corporation))\b/i' => 'oos_business_start',
          '/\b(form(ing)?\s*(a|an|my)?\s*(llc|corporation|business\s*entity))\b/i' => 'oos_business_form',
          '/\b(incorporat(e|ion|ing)|articles\s*of\s*(incorporation|organization))\b/i' => 'oos_business_incorporate',
          '/\b(register(ing)?\s*(a|my)\s*(business|company|llc|dba))\b/i' => 'oos_business_register',
          '/\b(business\s*licens(e|ing)|operating\s*permit)\b/i' => 'oos_business_license',
          // Intellectual property.
          '/\b(patent\s*(my|an?|the)?\s*(invention|idea|product))\b/i' => 'oos_ip_patent',
          '/\b(help\s*(me\s*)?patent|file\s*(a\s*)?patent|patent\s*application)\b/i' => 'oos_ip_patent_file',
          '/\b(trademark\s*(my|a|the)?\s*(business|name|logo|brand))\b/i' => 'oos_ip_trademark',
          '/\b(help\s*(me\s*)?trademark|register\s*(a\s*)?trademark)\b/i' => 'oos_ip_trademark_register',
          '/\b(copyright\s*(my|a|the)?\s*(work|book|music|art|software))\b/i' => 'oos_ip_copyright',
          '/\b(intellectual\s*property|ip\s*(law|lawyer|attorney))\b/i' => 'oos_ip_general',
          // Commercial contracts.
          '/\b(business\s*contract|commercial\s*(contract|agreement|lease))\b/i' => 'oos_business_contract',
          '/\b((negotiate|draft|review)\s*(a\s*)?(business|commercial)\s*(contract|deal|agreement))\b/i' => 'oos_business_contract_draft',
          '/\b((negotiate|draft|review)\s*(my|a|an|the)?\s*(llc|company|corporate)\s*(contract|deal|agreement))\b/i' => 'oos_business_contract_draft',
          '/\b((llc|company|corporate)\s*(contract|agreement))\b/i' => 'oos_business_contract',
          '/\b(vendor\s*agreement|supplier\s*contract|distribution\s*agreement)\b/i' => 'oos_business_vendor',
          // Corporate matters.
          '/\b(shareholder\s*(dispute|agreement|rights)|corporate\s*governance)\b/i' => 'oos_business_corporate',
          '/\b(business\s*partner\s*dispute|partnership\s*(agreement|dispute))\b/i' => 'oos_business_partnership',
          '/\b((buy(ing)?|sell(ing)?)\s*(a\s*)?(business|company))\b/i' => 'oos_business_acquisition',
          // Real estate commercial.
          '/\b(commercial\s*(real\s*estate|property|lease|building))\b/i' => 'oos_business_commercial_re',
          // Employment from employer side.
          '/\b(fire\s*(an\s*)?employee|terminate\s*(an\s*)?employee|employee\s*handbook)\b/i' => 'oos_business_employer',
          '/\b(draft\s*(an\s*)?employment\s*contract|non-?compete\s*(agreement|clause))\b/i' => 'oos_business_employment_contract',
        ],
      ],

      // Priority 6: Federal Matters - Complex federal issues.
      'federal_matters' => [
        'category' => self::CATEGORY_FEDERAL_MATTERS,
        'response_type' => self::RESPONSE_REDIRECT,
        'patterns' => [
          // Bankruptcy - only catch complex process questions.
          // Simple "can I file bankruptcy" queries should route to consumer for debt options info.
          '/\b(bankruptcy\s*(court|trustee|hearing|case\s*number))\b/i' => 'oos_federal_bankruptcy_process',
          '/\b(chapter\s*(7|11|13)\s*(trustee|hearing|meeting|creditors))\b/i' => 'oos_federal_bankruptcy_process',
          '/\b(bankruptcy\s*(attorney|lawyer)\s*(for|near|in\s+my))\b/i' => 'oos_federal_bankruptcy_attorney',
          '/\b(discharg(e|ing)\s*(student\s*loans?|tax(es)?))\b/i' => 'oos_federal_bankruptcy_discharge',
          // IRS/Tax disputes.
          '/\b(irs\s*(dispute|audit|problem|issue|debt|lien))\b/i' => 'oos_federal_irs',
          '/\b(tax\s*court|tax\s*(attorney|lawyer)|back\s*taxes)\b/i' => 'oos_federal_tax',
          '/\b(owe\s*(the\s*)?irs|irs\s*is\s*(coming|auditing|garnishing))\b/i' => 'oos_federal_irs_debt',
          // Federal courts.
          '/\b(federal\s*court|federal\s*lawsuit|u\.?s\.?\s*district\s*court)\b/i' => 'oos_federal_court',
          // Social Security appeals (complex).
          '/\b(social\s*security\s*disability\s*(appeal|hearing|denied))\b/i' => 'oos_federal_ssdi_appeal',
          '/\b(ssi\s*(appeal|hearing|denied)|ssdi\s*(appeal|hearing|denied))\b/i' => 'oos_federal_ss_appeal',
          // VA benefits.
          '/\b(va\s*(benefits?|disability|claim|appeal)|veterans?\s*(benefits?|disability\s*claim))\b/i' => 'oos_federal_va',
          // Patent/Trademark (federal IP).
          '/\b(patent\s*infringement|trademark\s*infringement|copyright\s*infringement)\b/i' => 'oos_federal_ip_infringement',
        ],
      ],

      // Priority 7: High-Value Civil - Outside typical legal aid scope.
      'high_value_civil' => [
        'category' => self::CATEGORY_HIGH_VALUE_CIVIL,
        'response_type' => self::RESPONSE_REDIRECT,
        'patterns' => [
          // Personal injury.
          '/\b(personal\s*injury\s*(case|lawyer|attorney|claim))\b/i' => 'oos_civil_personal_injury',
          '/\b(car\s*accident\s*(lawyer|attorney|lawsuit)?|auto\s*accident\s*(claim|lawyer)?)\b/i' => 'oos_civil_auto_accident',
          '/\b((was|got)\s*in\s*(a\s*)?car\s*accident|accident\s*(and|to)\s*sue)\b/i' => 'oos_civil_auto_accident_sue',
          '/\b(slip\s*and\s*fall|premises\s*liability)\b/i' => 'oos_civil_slip_fall',
          '/\b(medical\s*malpractice|doctor\s*(messed|screwed)\s*up)\b/i' => 'oos_civil_med_mal',
          '/\b(wrongful\s*death\s*(lawsuit|claim|attorney))\b/i' => 'oos_civil_wrongful_death',
          // Class actions.
          '/\b(class\s*action|join\s*a\s*lawsuit)\b/i' => 'oos_civil_class_action',
          // Large monetary.
          '/\b(sue\s*(for|them\s*for)\s*\$?\d{6,}|million\s*dollar\s*(lawsuit|case))\b/i' => 'oos_civil_large_monetary',
          '/\b(big\s*(settlement|payout|damages))\b/i' => 'oos_civil_settlement',
          // Workers comp.
          '/\b(workers?\s*(comp|compensation)\s*(claim|case|lawyer|attorney))\b/i' => 'oos_civil_workers_comp',
          // Product liability.
          '/\b(product\s*liability|defective\s*product\s*(lawsuit|claim))\b/i' => 'oos_civil_product_liability',
        ],
      ],
    ];
  }

  /**
   * Classifies a message and returns out-of-scope determination.
   *
   * @param string $message
   *   The user's message to classify.
   *
   * @return array
   *   Classification result with keys:
   *   - 'is_out_of_scope' (bool): Whether the query is out of scope.
   *   - 'category' (string): The out-of-scope category.
   *   - 'reason_code' (string): Specific reason code for logging.
   *   - 'response_type' (string): How to respond (decline/redirect/emergency).
   *   - 'matched_pattern' (string|null): The pattern that matched.
   *   - 'suggestions' (array): Suggested alternative resources.
   */
  public function classify(string $message): array {
    // Check if this is an informational query that should be dampened.
    $is_informational = $this->isInformationalQuery($message);

    // Process rules in priority order.
    foreach ($this->rules as $category_key => $rule) {
      foreach ($rule['patterns'] as $pattern => $reason_code) {
        if (preg_match($pattern, $message)) {
          // Dampen certain categories for informational queries.
          // Emergency services should never be dampened.
          if ($is_informational && $category_key !== 'emergency_services') {
            // Allow informational queries about topics.
            $informational_dampened = ['business_commercial', 'high_value_civil'];
            if (in_array($category_key, $informational_dampened)) {
              continue;
            }
          }

          return [
            'is_out_of_scope' => TRUE,
            'category' => $rule['category'],
            'reason_code' => $reason_code,
            'response_type' => $rule['response_type'],
            'matched_pattern' => $pattern,
            'suggestions' => $this->getSuggestions($rule['category']),
          ];
        }
      }
    }

    // No matches - in scope.
    return [
      'is_out_of_scope' => FALSE,
      'category' => self::CATEGORY_IN_SCOPE,
      'reason_code' => 'in_scope_no_oos_triggers',
      'response_type' => self::RESPONSE_IN_SCOPE,
      'matched_pattern' => NULL,
      'suggestions' => [],
    ];
  }

  /**
   * Checks if a message is an informational query.
   *
   * @param string $message
   *   The user's message.
   *
   * @return bool
   *   TRUE if the query appears informational.
   */
  protected function isInformationalQuery(string $message): bool {
    $informational_patterns = [
      '/\b(what\s*(is|are)|how\s*does|tell\s*me\s*about|explain|learn\s*about)\b/i',
      '/\b(information\s*(on|about)|general\s*question)\b/i',
      '/\b(help\s*someone\s*(with|who)|friend\s*(has|needs)|family\s*member)\b/i',
    ];

    foreach ($informational_patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets suggested resources for an out-of-scope category.
   *
   * @param string $category
   *   The out-of-scope category.
   *
   * @return array
   *   Array of suggestions with 'text' and optionally 'url' keys.
   */
  protected function getSuggestions(string $category): array {
    $suggestions = [
      self::CATEGORY_CRIMINAL_DEFENSE => [
        ['text' => 'Contact the Idaho Public Defender if you cannot afford an attorney'],
        ['text' => 'Call the Idaho State Bar Lawyer Referral Service', 'url' => 'https://isb.idaho.gov/ilrs/'],
        ['text' => 'If you have civil legal issues (housing, family, benefits), ILAS may still help'],
      ],
      self::CATEGORY_IMMIGRATION => [
        ['text' => 'Contact an immigration attorney'],
        ['text' => 'Idaho Commission on Hispanic Affairs', 'url' => 'https://icha.idaho.gov/'],
        ['text' => 'Catholic Charities of Idaho (immigration services)', 'url' => 'https://www.ccidaho.org/'],
        ['text' => 'If you have civil legal issues (housing, family), ILAS may still help'],
      ],
      self::CATEGORY_NON_IDAHO => [
        ['text' => 'LawHelp.org can connect you with legal aid in your state', 'url' => 'https://www.lawhelp.org/'],
        ['text' => 'Search for "[Your State] Legal Aid" online'],
      ],
      self::CATEGORY_EMERGENCY_SERVICES => [
        ['text' => 'Call 911 for immediate emergencies'],
        ['text' => 'After emergency is resolved, ILAS can help with related legal issues'],
      ],
      self::CATEGORY_BUSINESS_COMMERCIAL => [
        ['text' => 'Idaho State Bar Lawyer Referral Service', 'url' => 'https://isb.idaho.gov/ilrs/'],
        ['text' => 'Idaho Small Business Development Center', 'url' => 'https://idahosbdc.org/'],
        ['text' => 'Secretary of State Business Services', 'url' => 'https://sos.idaho.gov/business/'],
      ],
      self::CATEGORY_FEDERAL_MATTERS => [
        ['text' => 'Idaho State Bar Lawyer Referral Service', 'url' => 'https://isb.idaho.gov/ilrs/'],
        ['text' => 'For bankruptcy: Idaho Bankruptcy Court', 'url' => 'https://www.id.uscourts.gov/'],
        ['text' => 'For tax issues: IRS Taxpayer Advocate Service', 'url' => 'https://www.taxpayeradvocate.irs.gov/'],
      ],
      self::CATEGORY_HIGH_VALUE_CIVIL => [
        ['text' => 'Personal injury attorneys typically work on contingency'],
        ['text' => 'Idaho State Bar Lawyer Referral Service', 'url' => 'https://isb.idaho.gov/ilrs/'],
        ['text' => 'Idaho Trial Lawyers Association', 'url' => 'https://www.itla.org/'],
      ],
    ];

    return $suggestions[$category] ?? [];
  }

  /**
   * Gets human-readable description for a reason code.
   *
   * @param string $reason_code
   *   The reason code.
   *
   * @return string
   *   Human-readable description.
   */
  public function describeReasonCode(string $reason_code): string {
    $descriptions = [
      // Emergency.
      'oos_emergency_police' => 'Emergency: Police/911 requested',
      'oos_emergency_ambulance' => 'Emergency: Ambulance requested',
      'oos_emergency_medical' => 'Emergency: Medical help needed',
      'oos_emergency_medical_crisis' => 'Emergency: Medical crisis',
      'oos_emergency_fire' => 'Emergency: Fire emergency',
      'oos_emergency_intrusion' => 'Emergency: Home intrusion',
      'oos_emergency_active_threat' => 'Emergency: Active threat',
      'oos_emergency_overdose' => 'Emergency: Drug overdose',
      'oos_emergency_spanish' => 'Emergency: Spanish language',

      // Criminal.
      'oos_criminal_arrested' => 'Criminal: User was arrested',
      'oos_criminal_charged' => 'Criminal: Facing charges',
      'oos_criminal_accused' => 'Criminal: Accused of crime',
      'oos_criminal_dui' => 'Criminal: DUI/DWI matter',
      'oos_criminal_drugs' => 'Criminal: Drug charges',
      'oos_criminal_assault' => 'Criminal: Assault/battery',
      'oos_criminal_theft' => 'Criminal: Theft/burglary',
      'oos_criminal_sex_offense' => 'Criminal: Sex offense',
      'oos_criminal_homicide' => 'Criminal: Homicide matter',
      'oos_criminal_dv_charge' => 'Criminal: DV criminal charge',
      'oos_criminal_harassment_charge' => 'Criminal: Harassment charge',
      'oos_criminal_trespass' => 'Criminal: Trespassing',
      'oos_criminal_fraud' => 'Criminal: Fraud/embezzlement',
      'oos_criminal_plea' => 'Criminal: Plea bargain',
      'oos_criminal_proceeding' => 'Criminal: Court proceeding',
      'oos_criminal_bail' => 'Criminal: Bail/bond',
      'oos_criminal_trial' => 'Criminal: Trial',
      'oos_criminal_sentencing' => 'Criminal: Sentencing',
      'oos_criminal_incarcerated' => 'Criminal: Currently incarcerated',
      'oos_criminal_incarcerated_slang' => 'Criminal: Incarcerated (slang)',
      'oos_criminal_inmate' => 'Criminal: Inmate inquiry',
      'oos_criminal_probation' => 'Criminal: Probation matter',
      'oos_criminal_parole' => 'Criminal: Parole matter',
      'oos_criminal_supervised' => 'Criminal: Supervised release',
      'oos_criminal_representation' => 'Criminal: Seeking representation',
      'oos_criminal_need_lawyer' => 'Criminal: Needs defense attorney',
      'oos_criminal_record' => 'Criminal: Record inquiry',
      'oos_criminal_expungement' => 'Criminal: Expungement request',
      'oos_criminal_warrant' => 'Criminal: Warrant issue',
      'oos_criminal_spanish' => 'Criminal: Spanish language',

      // Immigration.
      'oos_immigration_visa' => 'Immigration: Visa matter',
      'oos_immigration_visa_type' => 'Immigration: Specific visa type',
      'oos_immigration_visa_denied' => 'Immigration: Visa denied',
      'oos_immigration_visa_appeal' => 'Immigration: Visa appeal',
      'oos_immigration_green_card' => 'Immigration: Green card',
      'oos_immigration_green_card_apply' => 'Immigration: Green card application',
      'oos_immigration_green_card_path' => 'Immigration: Green card pathway',
      'oos_immigration_aos' => 'Immigration: Adjustment of status',
      'oos_immigration_citizenship' => 'Immigration: Citizenship',
      'oos_immigration_naturalization' => 'Immigration: Naturalization',
      'oos_immigration_n400' => 'Immigration: N-400 form',
      'oos_immigration_deportation' => 'Immigration: Deportation',
      'oos_immigration_ice' => 'Immigration: ICE matter',
      'oos_immigration_court' => 'Immigration: Immigration court',
      'oos_immigration_nta' => 'Immigration: Notice to appear',
      'oos_immigration_voluntary' => 'Immigration: Voluntary departure',
      'oos_immigration_asylum' => 'Immigration: Asylum/refugee',
      'oos_immigration_asylum_process' => 'Immigration: Asylum process',
      'oos_immigration_protection' => 'Immigration: Protection request',
      'oos_immigration_undocumented' => 'Immigration: Undocumented status',
      'oos_immigration_overstay' => 'Immigration: Visa overstay',
      'oos_immigration_daca_tps' => 'Immigration: DACA/TPS',
      'oos_immigration_sponsor' => 'Immigration: Family sponsorship',
      'oos_immigration_family' => 'Immigration: Family immigration',
      'oos_immigration_bring_family' => 'Immigration: Bringing family',
      'oos_immigration_general' => 'Immigration: General inquiry',
      'oos_immigration_uscis' => 'Immigration: USCIS matter',
      'oos_immigration_spanish' => 'Immigration: Spanish language',
      'oos_immigration_spanish_undocumented' => 'Immigration: Spanish undocumented',

      // Non-Idaho.
      'oos_location_western' => 'Location: Western US state',
      'oos_location_western_explicit' => 'Location: Western US state (explicit)',
      'oos_location_other' => 'Location: Other US state',
      'oos_location_another_state' => 'Location: Another state (generic)',
      'oos_location_not_idaho' => 'Location: Explicitly not Idaho',
      'oos_location_other_legal_aid' => 'Location: Other state legal aid',
      'oos_location_other_state_law' => 'Location: Other state law',
      'oos_location_international' => 'Location: International',
      'oos_location_international_generic' => 'Location: Outside US',

      // Business.
      'oos_business_start' => 'Business: Starting a business',
      'oos_business_form' => 'Business: Forming entity',
      'oos_business_incorporate' => 'Business: Incorporation',
      'oos_business_register' => 'Business: Registration',
      'oos_business_license' => 'Business: Licensing',
      'oos_ip_patent' => 'IP: Patent request',
      'oos_ip_patent_file' => 'IP: Filing patent',
      'oos_ip_trademark' => 'IP: Trademark request',
      'oos_ip_trademark_register' => 'IP: Registering trademark',
      'oos_ip_copyright' => 'IP: Copyright request',
      'oos_ip_general' => 'IP: General IP matter',
      'oos_business_contract' => 'Business: Commercial contract',
      'oos_business_contract_draft' => 'Business: Contract drafting',
      'oos_business_vendor' => 'Business: Vendor agreement',
      'oos_business_corporate' => 'Business: Corporate matter',
      'oos_business_partnership' => 'Business: Partnership issue',
      'oos_business_acquisition' => 'Business: Buying/selling business',
      'oos_business_commercial_re' => 'Business: Commercial real estate',
      'oos_business_employer' => 'Business: Employer-side HR',
      'oos_business_employment_contract' => 'Business: Employment contract',

      // Federal.
      'oos_federal_bankruptcy' => 'Federal: Bankruptcy',
      'oos_federal_bankruptcy_process' => 'Federal: Bankruptcy process',
      'oos_federal_irs' => 'Federal: IRS matter',
      'oos_federal_tax' => 'Federal: Tax court/attorney',
      'oos_federal_irs_debt' => 'Federal: IRS debt',
      'oos_federal_court' => 'Federal: Federal court',
      'oos_federal_ssdi_appeal' => 'Federal: SSDI appeal',
      'oos_federal_ss_appeal' => 'Federal: SS appeal',
      'oos_federal_va' => 'Federal: VA benefits',
      'oos_federal_ip_infringement' => 'Federal: IP infringement',

      // High-value civil.
      'oos_civil_personal_injury' => 'Civil: Personal injury',
      'oos_civil_auto_accident' => 'Civil: Auto accident claim',
      'oos_civil_auto_accident_sue' => 'Civil: Auto accident lawsuit',
      'oos_civil_slip_fall' => 'Civil: Slip and fall',
      'oos_civil_med_mal' => 'Civil: Medical malpractice',
      'oos_civil_wrongful_death' => 'Civil: Wrongful death',
      'oos_civil_class_action' => 'Civil: Class action',
      'oos_civil_large_monetary' => 'Civil: Large monetary claim',
      'oos_civil_settlement' => 'Civil: Large settlement',
      'oos_civil_workers_comp' => 'Civil: Workers compensation',
      'oos_civil_product_liability' => 'Civil: Product liability',

      // In scope.
      'in_scope_no_oos_triggers' => 'In scope: No out-of-scope triggers',
    ];

    return $descriptions[$reason_code] ?? 'Unknown reason code';
  }

  /**
   * Batch classify multiple messages.
   *
   * @param array $messages
   *   Array of messages to classify.
   *
   * @return array
   *   Array of classification results keyed by original keys.
   */
  public function classifyBatch(array $messages): array {
    $results = [];
    foreach ($messages as $key => $message) {
      $results[$key] = $this->classify($message);
    }
    return $results;
  }

  /**
   * Gets statistics about classification rules.
   *
   * @return array
   *   Statistics array.
   */
  public function getRuleStatistics(): array {
    $stats = [
      'total_categories' => count($this->rules),
      'total_patterns' => 0,
      'categories' => [],
    ];

    foreach ($this->rules as $category_key => $rule) {
      $patternCount = count($rule['patterns']);
      $stats['total_patterns'] += $patternCount;
      $stats['categories'][$category_key] = [
        'category' => $rule['category'],
        'response_type' => $rule['response_type'],
        'pattern_count' => $patternCount,
      ];
    }

    return $stats;
  }

  /**
   * Gets all reason codes for a category.
   *
   * @param string $category
   *   The category constant.
   *
   * @return array
   *   Array of reason codes.
   */
  public function getReasonCodesForCategory(string $category): array {
    $codes = [];
    foreach ($this->rules as $rule) {
      if ($rule['category'] === $category) {
        $codes = array_merge($codes, array_values($rule['patterns']));
      }
    }
    return $codes;
  }

}
