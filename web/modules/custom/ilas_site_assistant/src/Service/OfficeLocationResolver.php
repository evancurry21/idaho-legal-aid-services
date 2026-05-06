<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Resolves user-provided city/county text to the nearest ILAS office.
 *
 * Holds only lookup logic (city -> office slug, county -> office slug,
 * abbreviations). All office address/phone/hour facts come from
 * {@see OfficeDirectory}, which is the canonical first-party source backed by
 * published office_information nodes.
 */
class OfficeLocationResolver {

  /**
   * City -> office slug map.
   *
   * Slugs match {@see OfficeDirectory} keys (derived from node titles).
   */
  const CITY_MAP = [
    // Boise region.
    'boise' => 'boise',
    'meridian' => 'boise',
    'eagle' => 'boise',
    'mountain home' => 'boise',
    'garden city' => 'boise',
    'star' => 'boise',
    'kuna' => 'boise',
    'mccall' => 'boise',
    // Nampa region.
    'nampa' => 'nampa',
    'caldwell' => 'nampa',
    'emmett' => 'nampa',
    // Pocatello region.
    'pocatello' => 'pocatello',
    'blackfoot' => 'pocatello',
    'american falls' => 'pocatello',
    'soda springs' => 'pocatello',
    'preston' => 'pocatello',
    'malad' => 'pocatello',
    // Twin Falls region.
    'twin falls' => 'twin_falls',
    'jerome' => 'twin_falls',
    'burley' => 'twin_falls',
    'rupert' => 'twin_falls',
    'hailey' => 'twin_falls',
    'ketchum' => 'twin_falls',
    'gooding' => 'twin_falls',
    'shoshone' => 'twin_falls',
    // Lewiston region.
    'lewiston' => 'lewiston',
    'moscow' => 'lewiston',
    'orofino' => 'lewiston',
    'grangeville' => 'lewiston',
    // Coeur d'Alene region (formerly routed to Lewiston in legacy data).
    "coeur d'alene" => 'coeur_dalene',
    'coeur dalene' => 'coeur_dalene',
    'sandpoint' => 'coeur_dalene',
    'post falls' => 'coeur_dalene',
    'wallace' => 'coeur_dalene',
    'bonners ferry' => 'coeur_dalene',
    'hayden' => 'coeur_dalene',
    // Idaho Falls region.
    'idaho falls' => 'idaho_falls',
    'rexburg' => 'idaho_falls',
    'driggs' => 'idaho_falls',
    'salmon' => 'idaho_falls',
    'rigby' => 'idaho_falls',
    'st anthony' => 'idaho_falls',
    'arco' => 'idaho_falls',
    'challis' => 'idaho_falls',
  ];

  /**
   * County -> office slug map (all 44 Idaho counties).
   */
  const COUNTY_MAP = [
    // Boise region.
    'ada' => 'boise',
    'boise' => 'boise',
    'elmore' => 'boise',
    'valley' => 'boise',
    // Nampa region.
    'canyon' => 'nampa',
    'gem' => 'nampa',
    'owyhee' => 'nampa',
    'payette' => 'nampa',
    'washington' => 'nampa',
    'adams' => 'nampa',
    // Pocatello region.
    'bannock' => 'pocatello',
    'bear lake' => 'pocatello',
    'caribou' => 'pocatello',
    'franklin' => 'pocatello',
    'oneida' => 'pocatello',
    'power' => 'pocatello',
    // Twin Falls region.
    'twin falls' => 'twin_falls',
    'jerome' => 'twin_falls',
    'blaine' => 'twin_falls',
    'camas' => 'twin_falls',
    'cassia' => 'twin_falls',
    'gooding' => 'twin_falls',
    'lincoln' => 'twin_falls',
    'minidoka' => 'twin_falls',
    // Lewiston region.
    'nez perce' => 'lewiston',
    'latah' => 'lewiston',
    'lewis' => 'lewiston',
    'clearwater' => 'lewiston',
    'idaho' => 'lewiston',
    // Coeur d'Alene region.
    'kootenai' => 'coeur_dalene',
    'benewah' => 'coeur_dalene',
    'bonner' => 'coeur_dalene',
    'boundary' => 'coeur_dalene',
    'shoshone' => 'coeur_dalene',
    // Idaho Falls region.
    'bonneville' => 'idaho_falls',
    'butte' => 'idaho_falls',
    'clark' => 'idaho_falls',
    'custer' => 'idaho_falls',
    'fremont' => 'idaho_falls',
    'jefferson' => 'idaho_falls',
    'lemhi' => 'idaho_falls',
    'madison' => 'idaho_falls',
    'teton' => 'idaho_falls',
    'bingham' => 'idaho_falls',
  ];

  /**
   * Common abbreviations -> normalized city name.
   */
  const ABBREVIATIONS = [
    'cda' => "coeur d'alene",
    'if' => 'idaho falls',
    'tf' => 'twin falls',
    'mtn home' => 'mountain home',
  ];

  public function __construct(
    private readonly OfficeDirectory $officeDirectory,
  ) {}

  /**
   * Resolves a user message to the nearest ILAS office.
   *
   * @param string $message
   *   The user's message (e.g. "boise", "Ada County").
   *
   * @return array|null
   *   Office data array with name, address, phone, hours, url keys, or NULL
   *   when no city/county match was found OR the matched office is not
   *   present in the canonical directory (e.g. unpublished, poisoned).
   */
  public function resolve(string $message): ?array {
    $normalized = $this->normalize($message);
    if ($normalized === '') {
      return NULL;
    }

    if (isset(self::ABBREVIATIONS[$normalized])) {
      $normalized = self::ABBREVIATIONS[$normalized];
    }

    $slug = $this->lookupSlug($normalized);
    if ($slug === NULL) {
      return NULL;
    }

    return $this->officeDirectory->get($slug);
  }

  /**
   * Returns all current public ILAS offices.
   *
   * @return array
   *   Offices keyed by slug.
   */
  public function getAllOffices(): array {
    return $this->officeDirectory->all();
  }

  /**
   * Returns the office slug a normalized phrase maps to, or NULL.
   */
  private function lookupSlug(string $normalized): ?string {
    if (isset(self::CITY_MAP[$normalized])) {
      return self::CITY_MAP[$normalized];
    }

    foreach (self::CITY_MAP as $city => $office_slug) {
      if (preg_match('/\b' . preg_quote($city, '/') . '\b/u', $normalized)) {
        return $office_slug;
      }
    }

    $county = $normalized;
    $has_county_suffix = FALSE;
    if (preg_match('/^(.+?)\s+county$/', $normalized, $m)) {
      $county = $m[1];
      $has_county_suffix = TRUE;
    }
    if (isset(self::COUNTY_MAP[$county]) && ($has_county_suffix || $normalized === $county)) {
      return self::COUNTY_MAP[$county];
    }

    foreach (self::COUNTY_MAP as $county_name => $office_slug) {
      if (
        preg_match('/\b' . preg_quote($county_name, '/') . '\s*county\b/u', $normalized) ||
        $normalized === $county_name
      ) {
        return $office_slug;
      }
    }

    return NULL;
  }

  /**
   * Normalizes input text for lookup.
   */
  protected function normalize(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = preg_replace("/[^\w\s']/u", '', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    return trim($text);
  }

}
