# 05 - AI Usage

Candid account, per the brief's invitation. I used Claude Code (Anthropic's
CLI agent) throughout, for close to the full scope of this assignment. I'm
not going to understate that - I read and directed every output, made every
judgment call, and can defend all of it, but the drafting work was
substantially AI-assisted.

## What I used it for

- **Environment setup and debugging.** Diagnosing the `docker compose pull`
  `401`/`403` failures against `ghcr.io`, distinguishing "repo is public" from
  "container packages are public" (a genuine, separate GitHub setting),
  isolating whether the failure was per-image or across all four, and
  bringing the stack up once a working token arrived.
- **Part 1 (code review).** I had it read the FRD, the architecture overview,
  and both PR diffs in full, then cross-reference each diff against the
  FRD's numbered business rules and the surrounding module code (not just the
  changed lines). I directed it to confirm suspicions against the actual
  source before writing anything down - e.g., it read the exception class
  hierarchy directly to confirm `ErpException` really is the common base of
  `ErpTransientException`/`ErpBusinessException`/`ErpConflictException`
  before flagging the collapsed catch block, and read `RefundValidator.php`
  on both `main` and the PR branch directly (not just the diff hunk) before
  concluding the guard regression was real.
- **Part 2 (design and architecture review).** The sequence diagram, the
  epic breakdown, and the ranked architecture risks were AI-drafted from the
  FRD and Architecture Overview text, then corrected against the actual
  codebase where the two disagreed - e.g. it caught that the Architecture
  Overview's stated gap ("one active refund per order is left to the
  application layer") is actually already closed in `main` via
  `OrderLock::lock()`'s `SELECT ... FOR UPDATE`, and flagged that as a
  documentation/implementation drift rather than taking the design doc's
  claim at face value.
- **Part 3 (the fix).** It identified that `main`'s validator was already
  correct and that the real defect was a test-coverage gap, not a production
  bug - I did not have to correct this reasoning, but I did have it prove
  the point rather than assert it: it temporarily reintroduced PR-02's exact
  regressed logic, ran the new tests to confirm they failed red, then
  reverted and confirmed green, before I accepted the fix as done.
- **This document set.** All six `submission/` files were drafted by the
  agent from the review and design work above, at my direction and reviewed
  by me before committing.

## What I checked or steered rather than accepted outright

- I asked it to verify claims against the running code rather than the diffs
  alone at several points (see above); it found at least two things this way
  that a diff-only review would have missed (the outbox/dispatcher pattern
  already in `main` that the Architecture Overview doesn't mention, and the
  already-implemented `OrderLock`).
- I made the call on which finding to fix in Part 3, and required it to
  reconcile "the defect I found is in a branch, not in `main`" honestly
  rather than force a production-code diff where none was needed - the
  build-impact note reflects that reasoning, not a retrofit.
- I decided the refund-window assumption (7 days per the contract note, not
  BR-02's stated 14) after it surfaced the FRD's internal contradiction;
  that's a judgment call I'm accountable for defending in the discussion
  round, not something I'd want to attribute to the tool.
- Git history, branch strategy, and what gets committed when were my
  decisions throughout, made explicitly turn by turn rather than delegated.

## What I did not do

I did not ask it to write the whole assignment unsupervised and submit the
output. Every finding in `01-code-review.md` and every risk in
`03-architecture-review.md` is something I read and would defend as my own
judgment in the discussion round - the tool did the reading and drafting at
scale; I did the deciding.
