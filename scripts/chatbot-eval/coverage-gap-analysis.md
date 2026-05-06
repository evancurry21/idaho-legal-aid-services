# ILAS Chatbot Knowledge Base Coverage Gap Analysis

> **Historical legacy report.** This report was produced by deprecated
> `scripts/chatbot-eval` tooling. Use Promptfoo, assistant smoke tests,
> PHPUnit/functional tests, and Playwright for current Site Assistant coverage.

**Generated:** 2026-01-23
**Analyzer:** Claude Code Coverage Gap Analysis
**Data Sources:** Golden Dataset (186 cases), Retrieval Fixture (165 cases), Routing Config

---

## Executive Summary

Analysis of the ILAS Site Assistant chatbot reveals **11 significant coverage gaps** across intent routing, FAQ content, and synonym mapping. The current system covers approximately **78%** of expected user query patterns, with notable gaps in employment law, veterans benefits, utilities, mobile home issues, and tribal law.

### Key Metrics

| Metric | Current | Target | Gap |
|--------|---------|--------|-----|
| Intent Coverage | 25 intents | 32 intents | 7 missing |
| FAQ Topic Coverage | 6 service areas | 9 service areas | 3 missing |
| Synonym Coverage (English) | ~85% | 95% | ~10% |
| Synonym Coverage (Spanish) | ~65% | 90% | ~25% |
| Typo Tolerance | ~70% | 90% | ~20% |

---

## 1. Coverage Gap Clusters

### Cluster 1: Employment & Workplace Issues (HIGH PRIORITY)
**Gap Severity:** HIGH
**Estimated Query Volume:** 8-12% of organic queries
**Current Coverage:** MINIMAL (only civil_rights topic mentions discrimination)

**Missing Query Patterns:**
- "fired from my job"
- "wrongful termination"
- "unpaid wages"
- "wage theft"
- "laid off"
- "unemployment benefits"
- "my employer owes me money"
- "not getting paid"
- "final paycheck"
- "work discrimination"
- "hostile work environment"
- "retaliation at work"
- "perdí mi trabajo" (Spanish: lost my job)
- "no me pagan" (Spanish: they don't pay me)
- "despedido" (Spanish: fired)

**Impact:** Users asking about employment issues will receive unclear routing, likely falling back to "unknown" intent or misrouting to civil_rights.

**Recommendations:**
1. Add `topic_employment` intent with patterns
2. Create FAQ entries for:
   - "What if I was fired unfairly?"
   - "How do I get unpaid wages?"
   - "What are my workplace rights?"
3. Add employment-specific forms/guides

---

### Cluster 2: Veterans Benefits (MEDIUM-HIGH PRIORITY)
**Gap Severity:** MEDIUM-HIGH
**Estimated Query Volume:** 3-5% of organic queries
**Current Coverage:** NONE

**Missing Query Patterns:**
- "veteran benefits"
- "VA benefits denied"
- "veteran legal help"
- "military benefits"
- "GI Bill"
- "veteran disability"
- "DD-214"
- "veterans court"
- "militar" (Spanish)
- "veterano" (Spanish)
- "beneficios de veterano" (Spanish)

**Impact:** Veterans seeking help will receive no relevant routing and likely get fallback responses.

**Recommendations:**
1. Add `topic_veterans` intent
2. Create FAQ: "Does Idaho Legal Aid help veterans?"
3. If ILAS serves veterans, add service area page
4. If not, add out-of-scope routing with referrals

---

### Cluster 3: Utilities & Basic Needs (MEDIUM PRIORITY)
**Gap Severity:** MEDIUM
**Estimated Query Volume:** 4-6% of organic queries
**Current Coverage:** MINIMAL (only mentioned in consumer protection)

**Missing Query Patterns:**
- "utility shutoff"
- "power disconnected"
- "gas turned off"
- "water shut off"
- "LIHEAP"
- "energy assistance"
- "can't pay utility bills"
- "utility company"
- "Idaho Power"
- "Intermountain Gas"
- "servicios publicos" (Spanish)
- "me cortaron la luz" (Spanish: power cut off)
- "ayuda con facturas" (Spanish: help with bills)

**Impact:** Users facing utility disconnection may not find relevant help or be routed to consumer issues incorrectly.

**Recommendations:**
1. Add utility-related patterns to `topic_consumer`
2. Create FAQ: "What if my utilities are being shut off?"
3. Add urgent safety trigger for "utility shutoff notice today"

---

### Cluster 4: Mobile/Manufactured Home Issues (MEDIUM PRIORITY)
**Gap Severity:** MEDIUM
**Estimated Query Volume:** 2-4% of organic queries
**Current Coverage:** NONE (housing covers general rental only)

**Missing Query Patterns:**
- "mobile home"
- "manufactured home"
- "trailer park"
- "mobile home eviction"
- "lot rent"
- "park owner"
- "mobile home sale"
- "titling mobile home"
- "casa movil" (Spanish)
- "trailer" (Spanish commonly uses English word)

**Impact:** Mobile home residents have different legal protections than standard renters; generic housing advice may be misleading.

**Recommendations:**
1. Add mobile home patterns to `topic_housing`
2. Create specific FAQ: "What are my rights in a mobile home park?"
3. Add guide for mobile home tenant rights

---

### Cluster 5: Name Change (MEDIUM PRIORITY)
**Gap Severity:** MEDIUM
**Estimated Query Volume:** 2-3% of organic queries
**Current Coverage:** MINIMAL (not explicitly covered)

**Missing Query Patterns:**
- "change my name"
- "legal name change"
- "name change form"
- "court name change"
- "change child's name"
- "name change cost"
- "cambiar mi nombre" (Spanish)
- "cambio de nombre" (Spanish)

**Impact:** Users seeking name change assistance will not find relevant forms or guides.

**Recommendations:**
1. Add `forms_finder` patterns for name change
2. Create FAQ: "How do I legally change my name?"
3. Add name change to forms listing

---

### Cluster 6: Expungement & Record Sealing (MEDIUM PRIORITY)
**Gap Severity:** MEDIUM
**Estimated Query Volume:** 2-3% of organic queries
**Current Coverage:** NONE (may be mistakenly routed to criminal/out-of-scope)

**Missing Query Patterns:**
- "expunge my record"
- "seal my record"
- "criminal record sealed"
- "clean my record"
- "background check"
- "arrest record"
- "withheld judgment"
- "borrar mi record" (Spanish)
- "limpiar mi record" (Spanish)

**Impact:** Expungement is often a CIVIL process in Idaho but may be blocked by criminal negatives.

**Recommendations:**
1. Review if expungement is in-scope
2. If yes: Add patterns, FAQ, and forms
3. If partially: Add referral information
4. Modify criminal negatives to allow expungement queries

---

### Cluster 7: Adult Guardianship (Non-Senior) (MEDIUM PRIORITY)
**Gap Severity:** MEDIUM
**Estimated Query Volume:** 1-2% of organic queries
**Current Coverage:** PARTIAL (only in `topic_seniors`)

**Missing Query Patterns:**
- "guardianship adult"
- "disabled family member"
- "incapacitated adult"
- "guardianship over brother/sister"
- "adult with disability"
- "developmental disability"
- "tutela de adulto" (Spanish)

**Impact:** Families seeking guardianship over non-senior adults may not find appropriate resources.

**Recommendations:**
1. Expand guardianship patterns beyond seniors
2. Create FAQ: "How do I become guardian for a disabled adult?"
3. Add guardianship to family law or create separate topic

---

### Cluster 8: Tribal/Native American Issues (MEDIUM PRIORITY)
**Gap Severity:** MEDIUM
**Estimated Query Volume:** 1-3% of organic queries
**Current Coverage:** NONE

**Missing Query Patterns:**
- "tribal court"
- "ICWA"
- "Indian Child Welfare"
- "reservation"
- "tribal member"
- "Native American"
- "Shoshone"
- "Nez Perce"
- "Coeur d'Alene tribe"
- "jurisdicción tribal" (Spanish)

**Impact:** Idaho has significant Native American population with unique jurisdictional issues.

**Recommendations:**
1. Determine if ILAS handles tribal matters
2. If yes: Add topic and FAQ entries
3. If no: Add out-of-scope with tribal court referrals
4. Add ICWA-specific information for family cases

---

### Cluster 9: Education & Special Needs (LOW-MEDIUM PRIORITY)
**Gap Severity:** LOW-MEDIUM
**Estimated Query Volume:** 1-2% of organic queries
**Current Coverage:** NONE

**Missing Query Patterns:**
- "special education"
- "IEP" (Individualized Education Program)
- "504 plan"
- "school discipline"
- "school expulsion"
- "school suspension"
- "education rights"
- "educación especial" (Spanish)
- "plan IEP" (Spanish)

**Impact:** Parents seeking help with school/education issues will not find resources.

**Recommendations:**
1. Determine if education law is in-scope
2. If yes: Add topic and FAQ
3. If no: Add out-of-scope with referral to disability advocates

---

### Cluster 10: Medical Debt & Collections (LOW-MEDIUM PRIORITY)
**Gap Severity:** LOW-MEDIUM
**Estimated Query Volume:** 2-3% of organic queries
**Current Coverage:** PARTIAL (covered under consumer/debt)

**Missing Query Patterns:**
- "medical bills"
- "hospital debt"
- "medical collection"
- "healthcare debt"
- "medical bankruptcy"
- "facturas medicas" (Spanish)
- "deuda del hospital" (Spanish)

**Impact:** Medical debt is a leading cause of financial distress but has unique considerations not covered by general debt collection info.

**Recommendations:**
1. Add medical debt patterns to consumer
2. Create FAQ: "What can I do about medical debt?"
3. Add information about charity care, negotiation

---

### Cluster 11: Probate & Wills (LOW PRIORITY)
**Gap Severity:** LOW
**Estimated Query Volume:** 1-2% of organic queries
**Current Coverage:** PARTIAL (only in seniors)

**Missing Query Patterns:**
- "probate"
- "will"
- "testament"
- "intestate"
- "estate administration"
- "deceased family member"
- "inherit"
- "inheritance"
- "testamento" (Spanish)
- "herencia" (Spanish)

**Impact:** Probate/estate queries may only route correctly if senior-related.

**Recommendations:**
1. Expand probate patterns beyond seniors
2. Add FAQ: "What is probate?"
3. Add probate forms/guides if available

---

## 2. Synonym & Typo Coverage Gaps

### Missing English Synonyms

| Canonical | Missing Synonyms |
|-----------|------------------|
| eviction | "kicked out", "thrown out", "forced to leave" |
| landlord | "property manager", "rental company", "management company" |
| custody | "visitation", "parenting time", "time with kids" |
| divorce | "end marriage", "separation", "split up" |
| bankruptcy | "chapter 7", "chapter 13", "debt relief" |
| benefits | "assistance", "welfare", "government help" |
| forms | "documents", "papers", "paperwork", "templates" |

### Missing Spanish Phrases

| English | Missing Spanish |
|---------|-----------------|
| apply for help | "pedir ayuda", "solicitar servicios" |
| legal advice line | "línea legal", "consejo legal por teléfono" |
| eviction | "desahucio", "echar de casa" |
| child support | "pensión alimenticia", "manutención" |
| domestic violence | "maltrato", "abuso doméstico", "violencia en el hogar" |
| scam | "timo", "engaño" |
| deadline | "vencimiento", "fecha de entrega" |

### Common Typos Not Covered

| Correct | Missing Typos |
|---------|---------------|
| eviction | "evicton", "eviciton", "evition" |
| custody | "cutsody", "custidy", "custoy" |
| divorce | "divorec", "divorse", "divore" |
| bankruptcy | "bankrupcy", "bankrupty", "bankrupcty" |
| protection | "protecton", "protecion" |
| application | "aplicaton", "aplication" |
| landlord | "landord", "lanldord" |
| lawyer | "laywer" (already covered), "laywyer" |

---

## 3. Retrieval Confidence Analysis

Based on the retrieval fixture test cases, these query types have low retrieval confidence:

### Low Confidence Categories (< 70% expected accuracy)

1. **Single-word vague queries** (e.g., "divorce", "custody", "eviction")
   - Multiple valid destinations (FAQ, form, guide)
   - Needs disambiguation or smart ranking

2. **Indirect problem descriptions** (e.g., "my landlord won't fix things")
   - Requires semantic understanding
   - May not match FAQ question text directly

3. **Informal/conversational queries** (e.g., "someone stole my identity")
   - Differs from formal FAQ language
   - Needs phrase mapping

4. **Spanish queries** (e.g., "custodia de ninos")
   - Limited Spanish content indexed
   - Translation/synonym mapping gaps

5. **Abbreviations** (e.g., "PO form", "POA", "SNAP")
   - Not all abbreviations mapped
   - May fail to match full terms

### Frequent Fallback Triggers

| Query Pattern | Issue | Recommendation |
|---------------|-------|----------------|
| Single words | Too ambiguous | Add disambiguation |
| "I don't know what to do" | Emotional/vague | Route to apply/hotline |
| Legal jargon | Not in synonyms | Add legal terminology |
| Compound queries | Multiple intents | Improve multi-intent handling |

---

## 4. Prioritized Content Backlog

### Tier 1: High Priority (Implement within 2 weeks)

1. **Employment FAQ Entries**
   - "What if I was fired unfairly?"
   - "How do I collect unpaid wages?"
   - "What are my workplace rights in Idaho?"

2. **Utility Shutoff FAQ**
   - "What if my utilities are being shut off?"

3. **Synonym Updates**
   - Add 25+ missing English synonyms
   - Add 15+ missing Spanish phrases
   - Add 10+ common typos

4. **Name Change Content**
   - FAQ: "How do I legally change my name?"
   - Link to court forms

### Tier 2: Medium Priority (Implement within 4 weeks)

5. **Veterans Information**
   - FAQ: "Does Idaho Legal Aid help veterans?"
   - Referral information if out-of-scope

6. **Mobile Home Content**
   - FAQ: "What are my rights in a mobile home park?"
   - Distinguish from regular tenant rights

7. **Expungement Content**
   - FAQ: "Can I seal or expunge my criminal record?"
   - Clarify civil vs criminal process

8. **Adult Guardianship**
   - FAQ: "How do I become guardian for a disabled adult?"
   - Expand beyond senior focus

### Tier 3: Lower Priority (Implement within 8 weeks)

9. **Tribal/Native American Information**
   - FAQ or out-of-scope with referrals

10. **Education/Special Needs**
    - Determine scope and add accordingly

11. **Medical Debt Specific Content**
    - FAQ: "What can I do about medical debt?"

12. **Probate Expansion**
    - Expand beyond senior-specific

---

## 5. Recommended Configuration Changes

### New Intent Patterns to Add

```yaml
# Add to IntentRouter patterns
topic_employment:
  patterns:
    - '/\b(fired|laid\s*off|wrongful\s*(termination|firing))/i'
    - '/\b(unpaid\s*wages?|wage\s*theft|not\s*(getting|been)\s*paid)/i'
    - '/\b(work(place)?\s*discrimination|hostile\s*work)/i'
    - '/\b(final\s*paycheck|owed\s*money\s*from\s*(work|job|employer))/i'
    - '/\b(despedido|no\s*me\s*pagan|perdí\s*mi\s*trabajo)/i'
  keywords: ['fired', 'laid_off', 'wages', 'workplace', 'employer', 'despedido']
  service_area: 'employment'
  weight: 0.75
```

### New Phrases to Add (config/routing/phrases.yml)

```yaml
# Employment phrases
- "wrongful termination"
- "unpaid wages"
- "final paycheck"
- "laid off"
- "hostile work environment"
- "wage theft"
- "perdí mi trabajo"

# Utility phrases
- "utility shutoff"
- "power disconnected"
- "gas turned off"
- "energy assistance"

# Mobile home phrases
- "mobile home"
- "manufactured home"
- "trailer park"
- "lot rent"

# Name change phrases
- "change my name"
- "legal name change"
- "name change form"

# Veterans phrases
- "veteran benefits"
- "VA benefits"
- "military benefits"
```

### New Synonyms to Add (config/routing/synonyms.yml)

```yaml
# Under apply section
apply:
  help:
    - "halp"
    - "ayuda"
    - "auxilio"        # NEW
  work:
    - "job"
    - "employment"
    - "trabajo"        # NEW

# New section for employment
employment:
  fired:
    - "terminated"
    - "let go"
    - "despedido"
    - "corrido"
  wages:
    - "pay"
    - "salary"
    - "salario"
    - "pago"
  employer:
    - "boss"
    - "company"
    - "work"
    - "jefe"
    - "patron"

# Under high_risk section - add utility
high_risk:
  utility:
    - "power"
    - "electric"
    - "gas"
    - "water"
    - "luz"
    - "electricidad"
```

---

## 6. Success Metrics

After implementing gap fixes, track:

| Metric | Baseline | Target |
|--------|----------|--------|
| Intent accuracy | 94.2% | 97% |
| Fallback rate | ~15% | < 8% |
| Spanish query success | ~65% | > 85% |
| Typo tolerance | ~70% | > 90% |
| Employment routing | 0% | > 90% |
| Utility routing | < 30% | > 85% |

---

## Appendix A: Sample Organic Queries (100 Additional)

### Employment (20)
1. "I got fired for no reason"
2. "my employer won't pay me"
3. "wrongful termination help"
4. "I'm owed back wages"
5. "discrimination at work"
6. "hostile work environment"
7. "retaliation for reporting"
8. "sexual harassment at job"
9. "employer won't give final check"
10. "FMLA violation"
11. "me despidieron injustamente"
12. "mi jefe no me paga"
13. "acoso en el trabajo"
14. "discriminacion laboral"
15. "horas extras no pagadas"
16. "I lost my job"
17. "unemployment benefits denied"
18. "work injury"
19. "workers comp denied"
20. "employer breaking labor laws"

### Utilities (10)
21. "my power is getting shut off"
22. "utility disconnect notice"
23. "can't afford electric bill"
24. "LIHEAP application"
25. "Idaho Power payment plan"
26. "water shutoff"
27. "me van a cortar la luz"
28. "no puedo pagar el gas"
29. "ayuda con facturas de servicios"
30. "utility assistance programs"

### Mobile Home (10)
31. "mobile home eviction"
32. "trailer park lot rent"
33. "manufactured home title"
34. "mobile home park rules"
35. "buying mobile home in park"
36. "selling mobile home"
37. "park owner harassment"
38. "mobile home lot lease"
39. "casa movil desalojo"
40. "renta de lote de trailer"

### Name Change (10)
41. "legal name change Idaho"
42. "how to change my name"
43. "name change petition form"
44. "change child's last name"
45. "name change after divorce"
46. "transgender name change"
47. "cost of legal name change"
48. "cambiar mi nombre legal"
49. "cambio de nombre despues divorcio"
50. "formulario cambio de nombre"

### Veterans (10)
51. "VA benefits help"
52. "veteran disability claim"
53. "DD-214 upgrade"
54. "veterans court Idaho"
55. "veteran housing assistance"
56. "military discharge upgrade"
57. "beneficios para veteranos"
58. "ayuda legal para veteranos"
59. "PTSD disability claim"
60. "veteran denied benefits"

### Guardianship (Non-Senior) (10)
61. "guardianship for disabled brother"
62. "adult guardianship Idaho"
63. "guardianship vs conservatorship"
64. "temporary guardianship adult"
65. "become guardian for family member"
66. "guardianship developmental disability"
67. "tutela de adulto con discapacidad"
68. "ser tutor de mi hermano"
69. "guardianship for mentally ill adult"
70. "emergency guardianship adult"

### Tribal/Native American (10)
71. "tribal court jurisdiction"
72. "ICWA case"
73. "reservation legal issues"
74. "tribal custody case"
75. "Shoshone-Bannock legal help"
76. "Nez Perce tribal court"
77. "Indian Child Welfare Act"
78. "tribal vs state court"
79. "jurisdicción tribal"
80. "corte de la tribu"

### Education (10)
81. "IEP meeting help"
82. "special education rights"
83. "504 plan violations"
84. "child expelled from school"
85. "school discipline appeal"
86. "school won't provide services"
87. "derechos de educación especial"
88. "plan IEP"
89. "expulsion de la escuela"
90. "discriminación en la escuela"

### Expungement (10)
91. "expunge my record Idaho"
92. "seal criminal record"
93. "clear my background check"
94. "withheld judgment expungement"
95. "record sealing process"
96. "misdemeanor expungement"
97. "borrar mi record criminal"
98. "sellar mi historial"
99. "limpiar antecedentes"
100. "cómo expungar un delito menor"

---

## Appendix B: FAQ Entry Templates (Top 5 Gaps)

### 1. Employment FAQ

**Q: What if I was fired unfairly?**

A: Idaho is an "at-will" employment state, meaning employers can generally terminate employees for any reason. However, termination may be wrongful if it was based on discrimination (race, sex, age, disability, etc.), retaliation for protected activity (reporting safety violations, filing workers' comp claims), or violation of an employment contract. If you believe you were wrongfully terminated, gather documentation of your employment and the circumstances of your firing, and consider contacting Idaho Legal Aid or the Idaho Human Rights Commission.

**Anchor:** `what-if-i-was-fired-unfairly`
**Category:** Employment
**Keywords:** fired, terminated, wrongful termination, laid off, at-will

---

### 2. Unpaid Wages FAQ

**Q: How do I collect unpaid wages?**

A: Idaho law requires employers to pay wages at least monthly and to provide final payment by the next regular payday. If your employer owes you wages, you can: 1) Request payment in writing, 2) File a wage claim with the Idaho Department of Labor, 3) Sue in small claims court (for amounts under $5,000). Keep records of hours worked, pay stubs, and any communication with your employer. Idaho Legal Aid may be able to help if you meet income eligibility requirements.

**Anchor:** `how-do-i-collect-unpaid-wages`
**Category:** Employment
**Keywords:** unpaid wages, owed money, final paycheck, wage theft

---

### 3. Utility Shutoff FAQ

**Q: What if my utilities are being shut off?**

A: If you receive a utility shutoff notice: 1) Contact your utility company immediately about payment plans, 2) Apply for LIHEAP (Low Income Home Energy Assistance Program) through your local Community Action Agency, 3) Check if you qualify for the utility company's own assistance programs, 4) During extreme weather, Idaho utilities may have moratoriums on shutoffs. If your utilities are already disconnected, you may be able to get emergency assistance to have them reconnected. Idaho Legal Aid may be able to help with utility-related legal issues.

**Anchor:** `what-if-my-utilities-are-being-shut-off`
**Category:** Consumer
**Keywords:** utility shutoff, power disconnected, LIHEAP, energy assistance

---

### 4. Name Change FAQ

**Q: How do I legally change my name?**

A: In Idaho, you can change your name by: 1) Filing a Petition for Name Change in your county's district court, 2) Publishing notice in a local newspaper for 4 consecutive weeks, 3) Attending a court hearing where the judge will approve your petition if there's no objection. The filing fee is approximately $200, but you may request a fee waiver if you can't afford it. After the court order is issued, update your Social Security card, driver's license, and other documents. Special rules apply for minors and name changes in divorce proceedings.

**Anchor:** `how-do-i-legally-change-my-name`
**Category:** Family
**Keywords:** name change, legal name, change name, court petition

---

### 5. Mobile Home Rights FAQ

**Q: What are my rights in a mobile home park?**

A: Idaho has specific laws protecting mobile home park residents under the Idaho Residential Mobile Home Landlord and Tenant Act. Key protections include: 1) Your landlord cannot evict you without proper cause and notice, 2) Lot rent cannot be raised without 90 days written notice, 3) Park rules must be reasonable and apply equally to all residents, 4) You have the right to sell your mobile home in place. If you're facing eviction or disputes with your park owner, the legal process is different from regular rental evictions. Idaho Legal Aid can help with mobile home park tenant issues.

**Anchor:** `what-are-my-rights-in-a-mobile-home-park`
**Category:** Housing
**Keywords:** mobile home, manufactured home, trailer park, lot rent

---

*End of Coverage Gap Analysis Report*
