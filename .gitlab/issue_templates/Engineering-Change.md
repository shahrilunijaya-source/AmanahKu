> Governing standard:
> `ursb-engineering-standard/governance/change-classification-and-minimum-gates.md`

## Change summary
<what is changing, in one or two lines>

## Business reason
<why; the outcome this serves>

## Linked requirement
<REQ-xxx>

## Change classification
- [ ] Level 1 — Standard Change
- [ ] Level 2 — Significant Change
- [ ] Level 3 — Sensitive / Critical Change

## Risk indicators
- [ ] Authentication or authorisation
- [ ] Client, personal, confidential or government data
- [ ] Payment, claims or financial calculations
- [ ] Database schema or migration
- [ ] External API or integration
- [ ] Production infrastructure or deployment
- [ ] High business or operational impact
- [ ] Difficult rollback
- [ ] Security control or audit evidence
- [ ] Regulatory, contractual or client obligation

## Required gates (derived from classification)
- [ ] PM / Product Owner approval
- [ ] Technical plan approval
- [ ] Security / Compliance review
- [ ] Code Owner approval
- [ ] QA sign-off
- [ ] UAT acceptance
- [ ] Production release approval
- [ ] Post-release monitoring

## Acceptance criteria
- [ ] <testable criterion>

## Affected modules
<components / services touched>

## Data impact
<data read/written; classification; PII / client / government data?>

## Security impact
<authn/authz, secrets, audit evidence affected?>

## Migration requirements
<schema/data migration; backup; sequencing>

## Rollback approach
<how this is reverted; rollback plan link for Level 3>

## Test requirements
<unit / integration / manual; evidence expected>

## Decision owners
PM / Product Owner: <name>  ·  Conventional Coder / Code Owner: <name>  ·  Security / Compliance: <name>

## Approval evidence
<links to approvals, labels applied, QA report, UAT acceptance>

/label ~decision-required
