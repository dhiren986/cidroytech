# 06 - Questions and Next Steps

## Open questions (would ask before/at sign-off)

1. **BR-02 says the refund window is 14 calendar days from delivery; the very
   next paragraph ("Contract note (procurement)") says the signed seller
   agreement sets it at 7 days, and that CS must not be able to override the
   contractual window.** These can't both be the enforced rule, and it isn't
   an engineering call - it's a legal/compliance one. I designed
   `02-refund-design.md` against 7 days, since the contract note frames it as
   the binding constraint, but this needs a real answer, not an assumption,
   before anyone builds against it. (Seed order `SR-ORD-1004` is deliberately
   set 20 days out specifically to test the window boundary - worth checking
   whether its expected behaviour was written against 14 or 7 days.)
2. **FRD §2 says the store allocates `refund_no` locally, in date sequence,
   at record-creation time (format `SR-YYYYMMDD-NNNNNN`) - before Create
   Refund is even called. FRD §9.5 says the ERP allocates it and returns it
   with the Create Refund response.** The §9.1 example payload only makes
   sense under §2 (the store is shown *sending* `refund_no` to the ERP). This
   is also the literal idempotency key for Create Refund (BR-07), so getting
   the ownership question wrong isn't cosmetic.
3. Does the `Outbox`/`Dispatcher` abstraction already in `main` (see
   `RefundProcessor`, `mp_refund_outbox`) support scheduled/cron dispatch
   today, or only the synchronous in-request call the code currently makes?
   This determines how much of the architecture review's R1/R2 fix
   (decoupling Read-Status/Confirm from the request thread) is wiring versus
   new code.
4. Is there an intended retention/archival policy for terminal refunds, so
   the worklist doesn't grow its "full working set" without bound?
5. What's the DB's transaction isolation level? `OrderLock::lock()` takes a
   `SELECT ... FOR UPDATE` row lock to serialise refund creation per order
   (BR-05) - that's the right idea, but its effectiveness against phantom
   inserts depends on isolation level, and I didn't get to verify which one
   the seeded MariaDB instance runs under.

## Assumptions made

- 7-day refund window (see question 1), pending confirmation.
- The four other Part 1 findings (collapsed ERP exception handling in
  PR-02, the inverted tax-code mapping, the drifting idempotency key, the
  worklist N+1/index regressions, and PR-01's receipt/UI issues) are real and
  worth fixing, but Part 3 asked for one high-value fix, not all of them -
  I picked the quantity-guard coverage gap because it's the cleanest,
  most self-contained financial-integrity issue with an unambiguous fix and
  test. The others remain as review comments for their respective PR
  authors in `01-code-review.md`, not implemented.
- I assumed the architecture review should be graded against what's actually
  in `main` where the two disagree, not just against the Architecture
  Overview document's own narrative - e.g. I found `main` already
  implements the order-level lock and an outbox pattern the document says
  don't exist yet. I flagged the discrepancy rather than silently trusting
  either source.

## Where I deliberately stopped

- I did not implement fixes for the other Part 1 findings, per the brief's
  "precision over volume" instruction and Part 3's scope of one fix.
- I did not load-test the worklist or settlement scan at the NFR's stated
  volumes (250k orders/month, 25 submissions/minute peak, 40 concurrent
  operators, 20k rows/day) - there's no load-testing harness supplied, and
  building one wasn't a good use of the time budget against a local stub
  environment that doesn't reflect production infrastructure.
- I did not verify the assumptions the Architecture Overview's sign-off rests
  on (ERP responds within 5 seconds, retries are rare enough not to exhaust
  PHP workers) - these are stated as assumptions in the source document, and
  I don't have production telemetry to confirm or refute them; they're
  listed as facts to verify in `03-architecture-review.md` rather than
  treated as settled.

## What I'd do next, given more time

1. Get answers to the two FRD contradictions above before anything else -
   they change what "correct" means for BR-02 and BR-07/§9.1's payload
   contents.
2. Implement the architecture review's target-state extension in priority
   order: move Read-Status/Confirm onto the existing outbox/dispatcher
   pattern (closes the two contradictory-mechanism risk and the synchronous
   ERP-call-in-request risk together), then the shared presentation
   calculator (closes the drift risk PR-01 already demonstrated), then the
   worklist pagination/index restoration.
3. Fix PR-02's remaining defects for real, in priority order: the exception
   handling collapse (§7/§9.6 violation), the idempotency-key/tax-code
   issues, then the worklist/index regressions - each with its own
   regression test, the same way Part 3 was approached.
4. Add a load-test harness (even a simple one against the local stub) before
   treating the NFR numbers in the architecture review as satisfied rather
   than assumed.
