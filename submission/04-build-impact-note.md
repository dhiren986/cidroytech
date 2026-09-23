# 04 - Build Impact Note

## What changed, and why the shape of the fix is a bit unusual

Part 1 flagged `review/pr-02-erp-refund-sync`'s regression to
`RefundValidator::validate()` as the most serious defect: it drops the
prior-refunded-quantity subtraction and checks `qty_refund` against raw
`qty_ordered` instead of the remaining refundable quantity, violating BR-03
and allowing a line to be over-refunded across multiple partial refunds.

When I went to fix it in the running module (`main`), the production logic
was **already correct** - `main`'s `RefundValidator` already computes
`remaining = qty_ordered - qty_refunded_before` and checks against that. The
defect exists only in the *proposed* PR-02 branch, which hasn't merged.

So the actual, present-tense defect in the running module isn't the
production logic - it's that **no test distinguishes "exceeds remaining" from
"exceeds ordered."** Every existing case in `RefundValidatorTest.php` either
has zero prior refunds (so `remaining == qty_ordered`, and the two checks
agree) or sits exactly at the boundary. That gap is precisely why PR-02 could
regress this method and have every existing unit test still pass. A missing
regression test is a real defect - it's the reason a correct implementation
today has no guardrail against becoming an incorrect one tomorrow.

## The change

1. **`app/code/Acme/SellerRefund/Model/RefundValidator.php`** - the rejection
   message now reports the actual figures (requested quantity, remaining,
   ordered, already refunded) instead of a bare "exceeds the remaining
   refundable quantity." No behavioural change; existing tests assert on the
   exception class, not the message text, so nothing else was touched by
   this.
2. **`app/code/Acme/SellerRefund/Test/Unit/Model/RefundValidatorTest.php`** -
   added `testQuantityWithinOrderedButExceedingRemainingIsRejected`: 3
   ordered, 2 already refunded (remaining 1), request 2 - must be rejected
   even though 2 is well within the ordered quantity of 3.
3. **`app/code/Acme/SellerRefund/Test/Integration/RefundQuantityGuardTest.php`**
   (new) - the same scenario end-to-end against the real database, using the
   seeded order `SR-ORD-1003` (built for exactly this: `SELLER-YEL-04` at
   qty_ordered 3, with 2 already refunded by a prior, confirmed seed refund).
   Two tests: requesting 2 more is rejected, requesting exactly the remaining
   1 succeeds.

## How I verified the tests actually catch the defect, not just pass

Passing today isn't proof of anything, since `main` was already correct.  I
temporarily replaced `RefundValidator::validate()`'s check with PR-02's exact
regressed logic (`bccomp($qty, $qtyOrdered, 4) === 1`, dropping the prior
term), reran both new tests, and confirmed they failed red:

```
1) Acme\SellerRefund\Test\Unit\Model\RefundValidatorTest::testQuantityWithinOrderedButExceedingRemainingIsRejected
Failed asserting that exception of type "Acme\SellerRefund\Exception\ValidationException" is thrown.

1) Acme\SellerRefund\Test\Integration\RefundQuantityGuardTest::testRequestExceedingRemainingButNotOrderedQuantityIsRejected
Failed asserting that exception of type "Acme\SellerRefund\Exception\ValidationException" is thrown.
```

Then reverted to the correct logic and confirmed both pass again.

## Test commands and results

```bash
bin/assignment-test unit --filter RefundValidatorTest        # 7/7 passing
bin/assignment-test integration --filter RefundQuantityGuardTest   # 2/2 passing
bin/assignment-test all                                       # 30 unit / 9 integration / 6 smoke, all passing
```

## Effects

- No production behaviour changes for any currently-passing path - the
  message improvement is additive and cosmetic (better diagnostics for
  operators and audit review), and nothing downstream parses that message
  string.
- Closes a real regression-detection gap: if PR-02's `RefundValidator` change
  (or an equivalent future refactor) is ever merged as-is, this new coverage
  now fails the build instead of shipping a financial-integrity bug silently.
- Scope: this fix addresses the one defect Part 3 asked for. The other
  findings from `01-code-review.md` (the collapsed ERP exception handling,
  the inverted tax-code mapping, the idempotency-key drift, the worklist
  N+1/index regressions, and the PR-01 receipt/UI issues) are documented as
  review feedback but not implemented - see `06-questions-and-next-steps.md`
  for why, and for what I'd tackle next given more time.
