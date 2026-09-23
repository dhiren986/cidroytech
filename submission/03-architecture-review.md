# 03 - Architecture Review

## Ranking criteria

Ranked by blast radius at sign-off: does the risk fire on every refund or an
edge case, does it violate a requirement the FRD states as a hard rule (BR-xx,
or explicit "must"/"never" language) rather than a soft preference, and how
hard would it be to unwind after production data depends on the current
behaviour.

## Risks, ranked

### R1 — Critical: the document describes two different, contradictory confirmation mechanisms

§4 ("Backend") and §4 ("Integration") describe **confirmation as a synchronous,
per-refund loop**: after cash-refund registration, the client polls Read Refund
Status every 2 seconds for up to 30 seconds inside the same request, then calls
Confirm Refund. §5 ("Performance") separately describes **a daily settlement
sweep** that "scans refunds in `cash_refunded` to drive confirmation and
export." FRD §9.2 says Read Refund Status "runs as a scheduled batch that
sweeps all records in `cash_refunded` on a fixed interval." These aren't two
descriptions of the same thing — a synchronous per-click loop and a
scheduled batch are different transactional models with different failure
modes, and the document treats both as current-state fact in the same
sign-off note. **This can't be signed off until it's one mechanism.**

*Response:* adopt the FRD's scheduled-sweep model (§9.2). It also matches the
Candidate Brief's stated technical baseline — "durable async work uses a DB
outbox with cron/CLI consumers" — which the codebase already partially
implements (`main`'s `Save` controller already dispatches through an
`Outbox`/`Dispatcher` abstraction for the create step; see R8). The operator's
"Refund Complete" click should only register the cash refund and let the next
sweep pass pick it up — see `02-refund-design.md`.

### R2 — Critical: Create Refund is synchronous inside the admin request, with retries that can block a PHP worker for ~90 seconds

§4: "Create Refund is called synchronously inside the same admin request,
right after the database write" — each attempt has a 30-second timeout,
followed by "two immediate retries." Worst case, one admin request occupies a
PHP-FPM worker for on the order of 90 seconds. At the stated peak of 25
submissions/minute with 40 concurrent worklist operators sharing the same
worker pool, a slow-but-not-down ERP (which is explicitly one of the stub's
supported failure modes) can exhaust workers and take down the worklist itself
— the same NFR document requires the worklist's first page in under 1.5
seconds. This also technically satisfies "the platform declares no message
broker" but ignores the DB-outbox alternative the technical baseline already
names.

*Response:* move Create Refund dispatch off the request thread via the
outbox/cron-dispatcher pattern (R8). The admin request's job ends at "committed
to the database" (BR-06's literal wording), full stop.

### R3 — High: the UI is designed to fabricate a "Refunded" state ahead of the server, contradicting an explicit FRD requirement

§2: "on Submit the UI immediately marks the row **Refunded** and renders the
success state, then the request completes in the background... the submit
control stays available while the request is in flight." FRD §14: "The submit
action remains disabled while a submission request is in flight... the browser
must not invent a lifecycle transition or show a confirmed state ahead of the
server." This isn't a minor UX quibble — it's the architecture document
proposing, as signed-off behaviour, exactly what the functional spec forbids in
so many words. (It also independently surfaced as a real regression in the
PR-01 review — see `01-code-review.md`.)

*Response:* disable submit while in flight; render only server-declared
sub-status; never pre-render "Refunded," "accepted," or a receipt link ahead of
the server confirming the record exists.

### R4 — High: the response-handling table marks 429 as terminal, contradicting the FRD's own failure-handling section

§4's table: "4xx, including **429** → Mark the operation and the refund
`failed`." FRD §9.6: "A 429 is a rate-limit signal and should be retried after
a backoff." A rate limit is by definition a signal the caller is going too
fast, not evidence of a bad request — treating it as a terminal business
rejection means the system fails exactly the requests it should be
throttling and retrying, and does so more often the busier the store gets
(campaign peak = more retries = more 429s = more permanently failed refunds,
at the worst possible time).

*Response:* only a definitive 4xx business rejection from the ERP (not a rate
limit) is terminal; 429 and 5xx/timeout both hold the main status and retry
with backoff, per FRD §7/§9.6.

### R5 — High: "one active refund per order" (BR-05) has no enforcement mechanism, and the document defers it rather than closing it

§7 (Assumptions) states this outright: "the next phase should also settle the
concurrency mechanism for the 'one active refund per order' invariant, which v1
leaves to the application layer." The `version` column protects a single row
from a lost update; it does nothing to stop two concurrent requests from each
successfully inserting a *new* `mp_refund` row against the same order. At 40
concurrent operators this is a real collision surface, not a hypothetical —
and BR-05 is phrased as a hard requirement ("must not allow"), not a
nice-to-have. This should not go to production as an open item.

*Response:* enforce at the database — a lock row per order (or a partial
unique index over `order_id` scoped to non-terminal statuses) taken inside the
same transaction as the `mp_refund` insert, so the guarantee doesn't depend on
application-layer timing.

### R6 — Medium: five presentation surfaces each independently recompute refund figures, despite the FRD requiring one shared snapshot

§6: "Each surface builds the figures where it renders them... Keeping each
channel-specific avoids coupling the PDF, storefront, email, and integration
layers to one presentation DTO." FRD §10.4 requires the opposite: figures
"derived from the same stored refund snapshot used by integration and
downstream sync." Independent recomputation is exactly how the same order can
show different totals on the receipt versus the order-details screen after
some later event changes what "the order" looks like — which is precisely
what happened in the PR-01 review (the receipt started reading live order data
instead of the stored snapshot, and double-counted "already refunded" qty as a
direct consequence of this design choice).

*Response:* one shared calculation service (the `RefundTotalCalculator` PR-01
deleted is the right shape) that all five surfaces and the export consume;
per-channel *formatting* stays separate, per-channel *figures* do not. Back it
with the "shared acceptance tests" §7 already gestures toward but never
defines.

### R7 — Medium: worklist has no pagination and the settlement scan has no supporting index, at *current*, not future, stated volume

§5: the worklist "renders the full working set; there is no server-side
aggregation," and the settlement scan's filter columns are "not specifically
indexed beyond the primary key and the unique `refund_no`; the scan relies on
the table staying small." §7 lists both as items "the next phase should
revisit... sized for current volume." But the NFRs in §12 already state
current volume as 20,000 rows/day for the settlement scan and 40 concurrent
worklist operators with a 1.5-second SLA — these aren't projected future
numbers, they're the stated baseline. Deferring index/pagination work "to next
phase" is deferring work the current-phase NFRs already require.

*Response:* add the composite index the query needs (`status`, `created_at` at
minimum) and server-side pagination on the worklist before sign-off, not after.

### R8 — Low/informational: the document undersells what the codebase already has

`main`'s actual `Save` controller (before either review PR) already routes
Create Refund dispatch through an `Outbox`/`Dispatcher` abstraction
(`Outbox::OP_CREATE`, etc.) rather than calling the ERP client inline — which
the Architecture Overview doesn't mention at all; §4 flatly states "there is no
separate queue, dead-letter store, or replay path." Either the document is
describing an earlier revision than what's in `main`, or the outbox layer
exists but isn't yet wired to a scheduled dispatcher (i.e., it currently only
supports the synchronous in-request `dispatcher->run(...)` call visible in the
diff). Either way, this is the seam R1/R2's fix should build on, and it's worth
five minutes to confirm with whoever owns the code before writing a design
around an assumption.

## Target-state extension

1. **Async dispatch for every ERP call**, via the outbox pattern already
   scaffolded in `main`: commit `mp_refund` + an outbox row in one transaction
   (BR-06, exactly); a cron/CLI consumer dispatches with its own retry/backoff,
   fully off the request thread. Applies to Create Refund immediately, and to
   the Read-Status→Confirm sequence as one scheduled sweep (closes R1 and R2
   together).
2. **Database-enforced one-active-refund-per-order** lock, taken
   transactionally alongside the `mp_refund` insert (closes R5).
3. **One shared presentation/calculation service** behind all five
   presentation surfaces and the downstream export, replacing five independent
   implementations (closes R6).
4. **Corrected failure-classification table**: only genuine business
   rejections are terminal; 429 and 5xx/timeout hold and retry (closes R4);
   confirmed via a failure-injection test matrix against the stub's documented
   fault modes as an explicit acceptance gate, not just code review.
5. **Server-rendered-only UI state**: no client-invented lifecycle
   transitions, submit disabled while in flight (closes R3).
6. **Indexing and pagination sized to the NFRs already in the document**, not
   deferred: `(status, created_at)` on `mp_refund` (also flagged in
   `01-code-review.md` — PR-02 as reviewed actually removes this index, making
   it a present-tense fix, not a future one), server-side worklist pagination
   (closes R7).
7. **Load- and failure-test at the stated scale before go-live**: 250k
   orders/month, 5k refunds/month, 25/min campaign peak, 40 concurrent
   worklist operators, 20k rows/day settlement scan — run against the actual
   outbox dispatcher and worklist query, not extrapolated from code reading.

## Facts to verify / questions before sign-off

- **BR-02 (14 days) vs. the procurement contract note (7 days)** — these are
  stated as two different enforced windows in the same document. Which one is
  actually binding? This is a legal/compliance question, not an engineering
  one, and it changes what BR-02's acceptance tests should assert. (Design in
  `02-refund-design.md` assumes 7 days, per the contract note's own framing as
  the authoritative constraint — but this needs a real answer, not an
  assumption, before build.)
- Does the existing `Outbox`/`Dispatcher` abstraction already support scheduled
  (cron) dispatch, or only the synchronous in-request call visible in `main`
  today? This determines whether R1/R2's fix is mostly wiring or mostly new
  code.
- What is the actual, currently-running mechanism for Read Status → Confirm —
  the synchronous loop §4 describes, the daily sweep §5 describes, or
  something else neither section mentions? The document shouldn't be trusted
  as the sole source of truth here given it contradicts itself.
- Is there an intended retention/archival policy for terminal refunds so the
  worklist's "full working set" doesn't grow without bound, or is pagination
  alone considered sufficient long-term?
- FRD §9.5 says the ERP allocates `refund_no` and the store persists the
  returned value; FRD §2 says the store allocates `refund_no` locally in date
  sequence *before* Create Refund is even called (and §9.1's own example
  payload has the store *sending* `refund_no` to the ERP, which only makes
  sense if the store already owns it). These two statements assign ownership
  of the same identifier to different systems — worth a definitive answer,
  since it's also the literal idempotency key for Create Refund (BR-07).
