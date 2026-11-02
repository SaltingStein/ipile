# ADR-20261102-Integer-Minor-Units-For-Money

**Status**: Proposed (Accepted when the introducing pull request is approved)  
**Date**: 2026-11-02  
**Decision Owner**: Ìpìlẹ̀ maintainers  
**Approved By**: Pull request reviewer

---

## Context
Payments were stored as `decimal(12,2)` and converted to minor units with `(int) round((float) $amount * 100)`, including in the webhook check that matches a gateway's amount against our ledger. Floating-point values cannot represent most decimal amounts exactly, so conversions can be off by one minor unit. Money movement is an ADR trigger, and the SDGP payments rule requires integer minor units. Every project built on Ìpìlẹ̀ inherits this choice. Spec: [integer-money](../specs/integer-money.md).

---

## Decision
Store money as integer minor units in `*_minor` integer columns (`payments.amount_minor`), convert only through `App\Support\Money` (exact string parsing; floats are fixed-point formatted before parsing; fractions of a minor unit are rejected), compare amounts as integers, and keep `Payment::amount` as a read/write decimal-string view for backwards compatibility.

---

## Options Considered

### Option 1: Integer minor units with a small Money helper (chosen)
- **Pros**: Exact; integer comparison is trivially correct; matches what gateways send; no new dependency; easy to index and sum in SQL.
- **Cons**: Display code must convert; the currency exponent is assumed to be 2.

### Option 2: Keep `decimal(12,2)` and use BCMath for conversions
- **Pros**: No schema change.
- **Cons**: Every call site must remember BCMath; Eloquent still hands back strings that tempt float casts; does not satisfy the SDGP rule as written.

### Option 3: A money library (moneyphp/money or brick/money)
- **Pros**: Full multi-currency support, allocation, rounding modes.
- **Cons**: New external dependency (its own ADR trigger) and a larger API for every product team, for currencies we do not support today.

---

## Rationale
Option 1 removes the bug class with the smallest surface: one column type, one helper, integer comparisons. It satisfies the SDGP rule directly. A money library can be adopted later behind the same `amount_minor` storage if multi-currency becomes a requirement.

---

## Consequences

### Positive
- Webhook ledger matching is exact; a one-kobo difference is caught.
- Sums and reports in SQL are exact.

### Negative
- Product code must use `Money` for display and input; enforced by convention, agent instructions and Sentinel rules rather than the type system.

### Neutral
- The API gains `amount_minor`; `amount` keeps its format.

---

## Security and Risk Notes
- Invalid amounts throw instead of being coerced, so a malformed webhook amount cannot match a ledger entry.
- The migration backfills from the decimal string, never a float, and is reversible.

---

## Review Triggers
- Support for a currency whose exponent is not 2 (for example JPY or KWD).
- A requirement for allocation, multi-currency wallets or FX.

---

## ISO 27001 Mapping
Which controls does this ADR support?

- [x] A.8 - Change Management
- [x] A.5 - Risk Assessment
- [x] A.8.25 - Secure Development
- [ ] A.5.15 - Access Control
- [ ] A.5.29 - Business Continuity
- [ ] A.5.37 - Documented Procedures

---

## Related ADRs
- None.

## References
- [Spec: integer-money](../specs/integer-money.md)
- SDGP §5.1 Payments
