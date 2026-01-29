# Gem Export Evaluation Summary

**Date:** 2026-01-27 15:45:44
**Run ID:** gem_export_eval_post
**Fixture:** /var/www/html/scripts/chatbot-eval/../../fixtures/real_queries_gem_export.json
**Base URL:** https://ilas-pantheon.ddev.site

## Overview

| Metric | Value |
|--------|-------|
| Unique queries | 985 |
| Weighted total (with duplicates) | 1044 |
| HTTP errors | 0 |
| Fallback/unknown | 1 (0.1%) |
| No action URL | 85 (8.6%) |
| Safety triggered | 68 (6.9%) |

## Intent Distribution

| Intent | Weighted Count | % |
|--------|---------------|---|
| service_area | 271 | 26% |
| clarify | 207 | 19.8% |
| forms_finder | 92 | 8.8% |
| apply_for_help | 70 | 6.7% |
| navigation | 67 | 6.4% |
| topic | 44 | 4.2% |
| resources | 38 | 3.6% |
| escalation | 32 | 3.1% |
| offices_contact | 32 | 3.1% |
| greeting | 22 | 2.1% |
| services_overview | 22 | 2.1% |
| faq | 22 | 2.1% |
| safety_dv_emergency | 20 | 1.9% |
| legal_advice_line | 19 | 1.8% |
| safety_legal_advice | 14 | 1.3% |
| high_risk | 12 | 1.1% |
| eligibility | 12 | 1.1% |
| safety_criminal | 11 | 1.1% |
| donations | 10 | 1% |
| out_of_scope | 9 | 0.9% |
| feedback | 5 | 0.5% |
| safety_immigration | 4 | 0.4% |
| safety_eviction_emergency | 2 | 0.2% |
| safety_document_drafting | 2 | 0.2% |
| unknown | 1 | 0.1% |
| urgent_safety | 1 | 0.1% |
| safety_child_safety | 1 | 0.1% |
| disambiguation | 1 | 0.1% |
| safety_frustration | 1 | 0.1% |

## Response Mode Distribution

| Mode | Weighted Count | % |
|------|---------------|---|
| navigate | 647 | 62% |
| clarify | 208 | 19.9% |
| escalation | 72 | 6.9% |
| answer | 56 | 5.4% |
| topic | 44 | 4.2% |
| out_of_scope | 15 | 1.4% |
| fallback | 2 | 0.2% |

## Gate Decision Distribution

| Decision | Weighted Count | % |
|----------|---------------|---|
| answer | 699 | 67% |
| clarify | 207 | 19.8% |
| null | 87 | 8.3% |
| hard_route | 51 | 4.9% |

## Top Action URLs

| Action | Weighted Count | % |
|--------|---------------|---|
| /apply-for-help | 323 | 30.9% |
| /legal-help/housing | 114 | 10.9% |
| none | 109 | 10.4% |
| /forms | 93 | 8.9% |
| /faq | 91 | 8.7% |
| /legal-help/family | 66 | 6.3% |
| /legal-help/seniors | 55 | 5.3% |
| /what-we-do/resources | 38 | 3.6% |
| /legal-help/consumer | 33 | 3.2% |
| /legal-help/health | 32 | 3.1% |
| /contact/offices | 32 | 3.1% |
| /Legal-Advice-Line | 19 | 1.8% |
| /legal-help/civil-rights | 15 | 1.4% |
| /donate | 10 | 1% |
| /services | 9 | 0.9% |
| /get-involved/feedback | 5 | 0.5% |

## Reason Code Distribution

| Reason Code | Weighted Count | % |
|-------------|---------------|---|
| direct_navigation_service_area | 271 | 26% |
| clarification_needed | 208 | 19.9% |
| resource_match_found | 130 | 12.5% |
| direct_navigation_apply | 70 | 6.7% |
| navigation_page_match | 67 | 6.4% |
| topic_match | 44 | 4.2% |
| direct_navigation_offices | 32 | 3.1% |
| greeting | 22 | 2.1% |
| intent_services | 22 | 2.1% |
| faq_match_found | 22 | 2.1% |
| direct_navigation_hotline | 19 | 1.8% |
| policy_legal_advice | 13 | 1.2% |
| intent_eligibility | 12 | 1.1% |
| high_risk_high_risk_dv | 11 | 1.1% |
| policy_pii | 10 | 1% |
| direct_navigation_donate | 10 | 1% |
| out_of_scope | 9 | 0.9% |
| emergency_dv | 8 | 0.8% |
| policy_emergency | 8 | 0.8% |
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
| document_drafting_fill | 2 | 0.2% |
| out_of_scope_criminal_charge | 2 | 0.2% |
| out_of_scope_immigration_deportation | 2 | 0.2% |
| emergency_dv_abuse | 1 | 0.1% |
| policy_criminal | 1 | 0.1% |
| out_of_scope_immigration_asylum | 1 | 0.1% |
| emergency_eviction_notice | 1 | 0.1% |
| out_of_scope_criminal_arrest | 1 | 0.1% |
| emergency_child_protection | 1 | 0.1% |
| out_of_scope_immigration_status | 1 | 0.1% |
| high_risk_high_risk_eviction | 1 | 0.1% |
| frustration_human_request | 1 | 0.1% |
| emergency_lockout | 1 | 0.1% |

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
