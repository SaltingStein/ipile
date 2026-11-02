# Feature Specification: Integer Minor Units for Money

**Status**: Implemented (approved by review of the pull request that introduces it)  
**Author**: Ìpìlẹ̀ maintainers, drafted with Claude Code  
**Date**: 2026-11-02  
**Last Updated**: 2026-11-02

---

## Overview
Payment amounts were stored as `decimal(12,2)` and converted to and from minor units with float arithmetic (`(int) round((float) $amount * 100)`), including in the webhook check that matches a gateway's reported amount against our ledger. Floats cannot represent most decimal amounts exactly (19.99 × 100 is 1998.9999999999998), so conversions can drift by a kobo, and a one-kobo mismatch either fails a genuine payment or, with tolerant comparisons, hides a real discrepancy. The SDGP payments rule requires money to be held as integer minor units.

---

## User Flow
1. A payment is created with an amount (for example "5000.50" naira).
2. The amount is stored as `amount_minor = 500050`; no float is involved.
3. The gateway is called with minor units taken directly from `amount_minor`.
4. The gateway's webhook reports an amount; it is converted exactly to minor units and compared as integers with `amount_minor`.
5. Admin pages, notifications and the API show the amount as an exact two-decimal string or a formatted figure.

---

## Scope

### In Scope
- `App\Support\Money`: exact conversion between decimal strings, integers or floats and integer minor units; display formatting.
- `payments.amount_minor` (unsigned big integer) replacing `payments.amount`, with a reversible, exact backfill migration.
- `Payment::amount` kept as a virtual attribute (exact decimal string) so existing callers and the API keep working.
- Webhook ledger matching, gateway adapters, assistant metrics, `PaymentResource` and the payment factory moved to minor units.

### Out of Scope
- Multi-currency exponents (currencies without two decimal places). All supported gateways use two-decimal currencies.
- Currency conversion.

---

## Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-01 | Payment amounts are stored as integer minor units in `payments.amount_minor`. | Must |
| FR-02 | `Money::toMinor` converts strings, integers and floats exactly, and rejects values it cannot represent (fractions of a kobo, separators, exponents, non-numeric, infinite). | Must |
| FR-03 | Webhook amount matching compares integers; a one-kobo difference or a fraction of a kobo fails the match. | Must |
| FR-04 | Existing decimal amounts are migrated exactly; the migration is reversible. | Must |
| FR-05 | The API's `amount` field is unchanged in format; `amount_minor` is added. | Must |
| FR-06 | Display formatting uses thousands separators and two decimals without floats. | Should |

---

## Non-Functional Requirements

| Category | Requirement |
|----------|-------------|
| Performance | Conversions are string operations; no measurable cost. |
| Reliability | Round-tripping `fromMinor` → `toMinor` never drifts (tested up to 999,999,999.99). |
| Security | Invalid amounts throw rather than being coerced, so malformed webhook amounts cannot match a ledger entry. |
| Compliance | SDGP payments rule: integer minor units. |

---

## Data and State Changes

| Entity | Operation | Description |
|--------|-----------|-------------|
| `payments` | UPDATE (schema) | Add `amount_minor`, backfill from `amount` exactly, drop `amount`. Reversible. |
| `Payment` | READ | `amount` derived from `amount_minor` as an exact string. |
| `Payment` | CREATE/UPDATE | Writing `amount` sets `amount_minor` via `Money::toMinor`. |

---

## Related ADRs

- [ADR-20261102-Integer-Minor-Units-For-Money](../adr/ADR-20261102-Integer-Minor-Units-For-Money.md)

---

## API Endpoints (if applicable)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/payments`, `/api/v1/payments/{reference}` | Responses gain `amount_minor` (integer); `amount` is unchanged. |

---

## Risks and Edge Cases

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Backfill rounds an existing value | Low | High | Backfill parses the stored decimal string, never a float; verified up, down and up again on real values. |
| A gateway reports amounts with more than two decimals | Low | Medium | Trailing zeros are accepted; a real fraction of a kobo is rejected and the webhook is not matched. |
| Product code still writes floats | Medium | Medium | `Money::toMinor` accepts floats exactly via fixed-point formatting; non-negotiable 5 and the Sentinel rules forbid float maths on amounts. |

---

## Rollback Plan
1. Revert the commit.
2. Run `php artisan migrate:rollback` for `2026_11_02_100000_store_payment_amounts_in_minor_units`; `down()` restores `amount` as `decimal(12,2)` exactly from `amount_minor`.

---

## Dependencies
- None new.

---

## Testing Strategy

- Unit tests: `tests/Unit/Support/MoneyTest.php` (exact conversions, rejected inputs, round trip, formatting).
- Integration tests: webhook processing tests (exact match, one kobo off, fraction of a kobo, 19.99), `PaymentControllerTest` (`amount_minor` in the API).
- E2E tests: none.
