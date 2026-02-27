# Audit Governance Specification: "No Legal Advice" Compliance

**Status:** Draft
**Deliverable:** P0-DEL-03
**Unblocks:** IMP-GOV-01
**Risk addressed:** R-GOV-01

## 1. Purpose

This specification defines the governance framework for auditing the Aila chatbot's "no legal advice" enforcement boundary. It establishes which safety events constitute the audit domain, what a compliance report must contain, who may access and sign off on reports, and the required review cadence.

## 2. Audit Domain

### 2.1 Safety Classes

The following `SafetyClassifier::CLASS_*` constants define the "no legal advice" enforcement domain:

| Class | Constant | Role in Boundary |
|-------|----------|------------------|
| `legal_advice` | `CLASS_LEGAL_ADVICE` | Primary: direct refusal of legal advice requests |
| `document_drafting` | `CLASS_DOCUMENT_DRAFTING` | Primary: direct refusal of document drafting requests |
| `criminal` | `CLASS_CRIMINAL` | Secondary: OOS redirect enforcing the boundary by declining |
| `immigration` | `CLASS_IMMIGRATION` | Secondary: OOS redirect enforcing the boundary by declining |
| `external` | `CLASS_EXTERNAL` | Secondary: OOS redirect enforcing the boundary by declining |

**Excluded classes** (not part of no-legal-advice domain):

| Class | Reason for Exclusion |
|-------|---------------------|
| `crisis`, `immediate_danger`, `dv_emergency`, `eviction_emergency`, `child_safety`, `scam_active` | Life-safety domain (separate governance) |
| `pii` | Privacy domain |
| `prompt_injection`, `wrongdoing` | Abuse prevention domain |
| `frustration` | UX domain |
| `safe` | No enforcement action taken |

### 2.2 Analytics Event Types

The following event types logged to `ilas_site_assistant_stats` are included in audit reports:

| Event Type | Source | Description |
|------------|--------|-------------|
| `safety_violation` | `SafetyClassifier` pre-generation check | Input classified as unsafe before LLM generation |
| `policy_violation` | `PolicyFilter` keyword check | Input matched policy keyword patterns |
| `out_of_scope` | `OutOfScopeClassifier` | Input classified as out-of-scope for the assistant |
| `post_gen_safety_legal_advice` | Post-generation safety check | LLM output flagged as containing legal advice |
| `post_gen_safety_review_flag` | Post-generation safety check | LLM output flagged for manual review |

### 2.3 Domain Invariant

The `legal_advice` and `document_drafting` classes MUST always be present in the audit domain. Removal of either requires governance review and explicit approval.

## 3. Report Schema

Each monthly compliance report MUST contain the following fields:

```
report_period_start    : date (ISO 8601, inclusive)
report_period_end      : date (ISO 8601, inclusive)
generated_at           : datetime (ISO 8601 with timezone)
generated_by           : string (Drupal user ID or system identifier)
totals_by_class        : map<class_name, integer>
totals_by_reason_code  : map<reason_code, integer>
totals_by_escalation_level : map<level, integer>
totals_by_source       : map<source, integer>
daily_breakdown        : list<{date, class, reason_code, count}>
signoff:
  signed_by            : string (Drupal user ID, null if pending)
  signed_at            : datetime (ISO 8601, null if pending)
  status               : enum(pending, approved, escalated)
  notes                : string (optional reviewer notes)
```

### 3.1 Reproducibility

Reports MUST be deterministically reproducible from `ilas_site_assistant_stats` table data for the specified date range. The data source columns are:

- `event_type` — maps to analytics event types in section 2.2
- `event_value` — JSON containing `class`, `reason_code`, and other contextual fields
- `created` — timestamp for date bucketing

Given the same date range and the same database state, regenerating a report MUST produce identical totals.

## 4. Access Control

| Action | Required Permission |
|--------|-------------------|
| Generate and view audit reports | `view ilas site assistant reports` |
| Record monthly signoff | `approve ilas site assistant audit` |

The `approve ilas site assistant audit` permission has `restrict access: true` to prevent accidental assignment to untrusted roles.

### 4.1 Separation of Duties

The report viewing permission and signoff permission are intentionally distinct. An operator may view reports without being authorized to record compliance signoff.

## 5. Review Cadence

| Parameter | Value |
|-----------|-------|
| Report period | 30 days |
| Signoff deadline | 14 days after period end |
| Report retention | 365 days |

### 5.1 Cadence Rules

- Reports cover a rolling 30-day period aligned to calendar months where practical.
- Signoff MUST be recorded within 14 days of the period end date.
- Overdue signoffs SHOULD be flagged in the admin UI (implementation deferred to IMP-GOV-01).
- Generated reports are retained for 365 days. After retention expiry, reports may be archived or purged per organizational policy.

## 6. Data Source

All audit data originates from the `ilas_site_assistant_stats` database table, which is populated by the existing analytics logging pipeline (`AssistantApiController`). No new data collection is introduced by this specification.

### 6.1 Table Schema (Relevant Columns)

| Column | Type | Usage |
|--------|------|-------|
| `event_type` | varchar | Filter by audit domain event types |
| `event_value` | text (JSON) | Extract class, reason_code, escalation_level, source |
| `created` | int (timestamp) | Date bucketing for report periods |

## 7. Configuration

The audit governance parameters are stored in `ilas_site_assistant.settings` under the `audit_governance` key:

```yaml
audit_governance:
  audit_domain_classes:
    - legal_advice
    - document_drafting
    - criminal
    - immigration
    - external
  audit_domain_event_types:
    - safety_violation
    - policy_violation
    - out_of_scope
    - post_gen_safety_legal_advice
    - post_gen_safety_review_flag
  report_cadence_days: 30
  report_required_permission: 'view ilas site assistant reports'
  signoff_required_permission: 'approve ilas site assistant audit'
  report_retention_days: 365
  signoff_deadline_days: 14
```

These values are locked by contract tests in `NoLegalAdviceGovernanceTest.php` and `SafetyConfigGovernanceTest.php`.

## 8. Scope Boundary

This specification does NOT cover:

- Report generation UI, controller, or routes (deferred to IMP-GOV-01)
- Database tables for signoff records (deferred to IMP-GOV-01)
- Signoff form implementation (deferred to IMP-GOV-01)
- Runtime changes to the safety pipeline
- Crisis/emergency event governance (separate domain)

## 9. Contract Test Coverage

The following contract tests enforce this specification:

| Test | What It Locks |
|------|--------------|
| `testAuditGovernanceBlockExistsInInstallConfig` | All 7 config sub-keys present in install config |
| `testAuditGovernanceBlockExistsInActiveConfig` | All 7 config sub-keys present in active config |
| `testSchemaCoversAuditGovernanceBlock` | Schema defines audit_governance mapping |
| `testAuditDomainClassesAreValidClassConstants` | Each class maps to a SafetyClassifier constant |
| `testAuditDomainContainsLegalAdviceClass` | legal_advice is always in domain |
| `testAuditDomainContainsDocumentDraftingClass` | document_drafting is always in domain |
| `testAuditDomainClassesMatchExpectedSet` | Exact sorted set of 5 classes |
| `testAuditDomainEventTypesAreComplete` | All 5 required event types present |
| `testReportCadenceDaysIsMonthly` | Cadence equals 30 days |
| `testReportRequiredPermissionIsSet` | Non-empty report permission |
| `testSignoffRequiredPermissionIsSet` | Non-empty signoff permission, distinct from report |
| `testSignoffPermissionExistsInPermissionsYml` | Permission defined in permissions.yml |
| `testInstallAndActiveConfigAuditGovernanceMatch` | Install and active config parity |
| `testReportRetentionAndSignoffDeadlineArePositive` | Both values > 0 |
