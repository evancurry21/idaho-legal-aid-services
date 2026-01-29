# Gem Export Evaluation Summary

**Date:** 2026-01-27 14:44:06
**Run ID:** gem_export_eval
**Fixture:** /var/www/html/scripts/chatbot-eval/../../fixtures/real_queries_gem_export.json
**Base URL:** https://ilas-pantheon.ddev.site

## Overview

| Metric | Value |
|--------|-------|
| Unique queries | 985 |
| Weighted total (with duplicates) | 1044 |
| HTTP errors | 0 |
| Fallback/unknown | 1 (0.1%) |
| No action URL | 87 (8.8%) |
| Safety triggered | 68 (6.9%) |

## Intent Distribution

| Intent | Weighted Count | % |
|--------|---------------|---|
| clarify | 266 | 25.5% |
| service_area | 237 | 22.7% |
| forms_finder | 107 | 10.2% |
| apply_for_help | 83 | 8% |
| topic | 49 | 4.7% |
| resources | 42 | 4% |
| offices_contact | 36 | 3.4% |
| escalation | 32 | 3.1% |
| services_overview | 25 | 2.4% |
| greeting | 22 | 2.1% |
| faq | 22 | 2.1% |
| safety_dv_emergency | 20 | 1.9% |
| legal_advice_line | 18 | 1.7% |
| safety_legal_advice | 14 | 1.3% |
| eligibility | 12 | 1.1% |
| safety_criminal | 11 | 1.1% |
| high_risk | 10 | 1% |
| out_of_scope | 9 | 0.9% |
| donations | 8 | 0.8% |
| feedback | 5 | 0.5% |
| safety_immigration | 4 | 0.4% |
| safety_eviction_emergency | 3 | 0.3% |
| disambiguation | 2 | 0.2% |
| safety_document_drafting | 2 | 0.2% |
| unknown | 1 | 0.1% |
| urgent_safety | 1 | 0.1% |
| safety_child_safety | 1 | 0.1% |
| safety_frustration | 1 | 0.1% |
| safety_scam_active | 1 | 0.1% |

## Response Mode Distribution

| Mode | Weighted Count | % |
|------|---------------|---|
| navigate | 580 | 55.6% |
| clarify | 268 | 25.7% |
| escalation | 74 | 7.1% |
| answer | 56 | 5.4% |
| topic | 49 | 4.7% |
| out_of_scope | 15 | 1.4% |
| fallback | 2 | 0.2% |

## Gate Decision Distribution

| Decision | Weighted Count | % |
|----------|---------------|---|
| answer | 640 | 61.3% |
| clarify | 266 | 25.5% |
| null | 89 | 8.5% |
| hard_route | 49 | 4.7% |

## Top Action URLs

| Action | Weighted Count | % |
|--------|---------------|---|
| /apply-for-help | 396 | 37.9% |
| none | 111 | 10.6% |
| /forms | 109 | 10.4% |
| /legal-help/housing | 105 | 10.1% |
| /legal-help/family | 68 | 6.5% |
| /what-we-do/resources | 42 | 4% |
| /legal-help/seniors | 37 | 3.5% |
| /legal-help/health | 36 | 3.4% |
| /contact/offices | 36 | 3.4% |
| /legal-help/consumer | 32 | 3.1% |
| /faq | 24 | 2.3% |
| /Legal-Advice-Line | 18 | 1.7% |
| /services | 9 | 0.9% |
| /legal-help/civil-rights | 8 | 0.8% |
| /donate | 8 | 0.8% |
| /get-involved/feedback | 5 | 0.5% |

## Reason Code Distribution

| Reason Code | Weighted Count | % |
|-------------|---------------|---|
| clarification_needed | 268 | 25.7% |
| direct_navigation_service_area | 237 | 22.7% |
| resource_match_found | 149 | 14.3% |
| direct_navigation_apply | 83 | 8% |
| topic_match | 49 | 4.7% |
| direct_navigation_offices | 36 | 3.4% |
| intent_services | 25 | 2.4% |
| greeting | 22 | 2.1% |
| faq_match_found | 22 | 2.1% |
| direct_navigation_hotline | 18 | 1.7% |
| policy_legal_advice | 13 | 1.2% |
| intent_eligibility | 12 | 1.1% |
| policy_pii | 10 | 1% |
| high_risk_high_risk_dv | 10 | 1% |
| out_of_scope | 9 | 0.9% |
| emergency_dv | 8 | 0.8% |
| policy_emergency | 8 | 0.8% |
| direct_navigation_donate | 8 | 0.8% |
| emergency_dv_protection_order | 6 | 0.6% |
| direct_navigation_feedback | 5 | 0.5% |
| emergency_dv_physical | 5 | 0.5% |
| out_of_scope_criminal_representation | 4 | 0.4% |
| legal_advice_statute | 4 | 0.4% |
| out_of_scope_criminal_record | 2 | 0.2% |
| legal_advice_legal_question | 2 | 0.2% |
| legal_advice_should | 2 | 0.2% |
| legal_advice_explicit | 2 | 0.2% |
| legal_advice_strategy | 2 | 0.2% |
| no_match_fallback | 2 | 0.2% |
| out_of_scope_criminal_incarceration | 2 | 0.2% |
| legal_advice_action | 2 | 0.2% |
| emergency_eviction_notice | 2 | 0.2% |
| document_drafting_fill | 2 | 0.2% |
| out_of_scope_criminal_charge | 2 | 0.2% |
| out_of_scope_immigration_deportation | 2 | 0.2% |
| emergency_dv_abuse | 1 | 0.1% |
| policy_criminal | 1 | 0.1% |
| out_of_scope_immigration_asylum | 1 | 0.1% |
| out_of_scope_criminal_arrest | 1 | 0.1% |
| emergency_child_protection | 1 | 0.1% |
| out_of_scope_immigration_status | 1 | 0.1% |
| frustration_human_request | 1 | 0.1% |
| emergency_lockout | 1 | 0.1% |
| emergency_elder_abuse | 1 | 0.1% |

## Safety Flags

| Flag | Weighted Count |
|------|---------------|
| dv_indicator | 25 |
| eviction_imminent | 28 |
| identity_theft | 3 |
| crisis_emergency | 3 |
| criminal_matter | 12 |

## Top Fallback Queries (need routing improvements)

| # | Query | Count | Fix Category |
|---|-------|-------|-------------|
| 1 | no, sheriff refused my request for police reports regardi... | 1 | unclassified |
