# 01 - Code Review

Review of `review/pr-01-partial-refund-presentation` and `review/pr-02-erp-refund-sync`
against `main`. Findings are ranked by **blast radius**: does the defect lose or
misstate money, does it violate a numbered business rule (BR-xx) or an explicit FRD
requirement, and how far does it reach (one order vs. every refund going through the
path). Volume was not a goal — six findings, three of them load-bearing.

## PR-02 - `review/pr-02-erp-refund-sync`

### 1. Critical - the per-line quantity guard no longer accounts for prior refunds (BR-03)

**File:** `app/code/Acme/SellerRefund/Model/RefundValidator.php::validate()`

`validate()` still accepts `$priorRefundedByItem` (`order_item_id => already refunded
qty`, per its own docblock) but never reads it inside the method body. The guard
collapsed from "remaining refundable quantity" to "raw ordered quantity":

```php
// main (correct): compares against qty_ordered - qty_refunded_before
$remaining = bcsub($qtyOrdered, $prior, 4);
if (bccomp($qty, $remaining, 4) === 1) { throw ...; }

// PR-02 (regressed): compares against qty_ordered alone
if (bccomp($qty, $qtyOrdered, 4) === 1) { throw ...; }
```

**Why it matters.** BR-03 is explicit: `qty_refund` must not exceed
`qty_ordered − qty_refunded_before`. Concretely: an order line of qty 2 is fully
refunded in refund #1 (`qty_refunded_before` becomes 2, remaining 0). A second,
independent refund request for 1-2 more units on the same line now **passes**
validation, because it is only compared against `qty_ordered` (2), not the actual
remaining (0). This is a financial-integrity bug - it lets an operator refund the
same units twice - not a cosmetic regression, and it reaches every partial refund
on a previously-touched line.

**Evidence it's real, not a misread:** the parameter is accepted, documented, and
silently dropped - a clean signal of an incomplete refactor rather than an
intentional simplification.

**What I'd do:** restore the `bcsub($qtyOrdered, $prior, 4)` remaining-quantity
calculation and add a test that issues two sequential partial refunds against the
same line and asserts the second is rejected once the remaining quantity is
exhausted.

**Review comment I'd send:** "This drops the `qty_refunded_before` term from the
guard entirely - `$priorRefundedByItem` is accepted but unused. That's the one
check standing between a customer's line item being refunded twice for the same
units. Can you restore the remaining-quantity calc and add a test that covers two
sequential partial refunds on one line?"

---

### 2. Critical - transient and rate-limited ERP errors are now treated as terminal business rejections

**File:** `app/code/Acme/SellerRefund/Service/RefundProcessor.php::process()`,
`Controller/Adminhtml/Refund/Save.php::execute()`

`ErpTransientException`, `ErpBusinessException`, and `ErpConflictException` all
extend a common `ErpException` (`Model/Erp/Exception/ErpException.php`). `main`
caught them separately: transient held the refund at `calculated`, recorded a
sub-status, and rethrew for retry; only a genuine business rejection moved the
refund to `failed`. PR-02 replaces the three-way catch with one
`catch (ErpException $e)` that unconditionally transitions the refund to
`STATUS_FAILED` / `SUB_BUSINESS_REJECTED` - for a 503, a timeout, and a 429 alike.

**Evidence it's real:** the PR's own rewritten test,
`testErpRequestFailureMarksRefundFailed`, throws a `503 ErpTransientException` and
then asserts `transition(..., STATUS_FAILED, ...)` was called. The test was
updated to assert the regression rather than catch it - the old test,
`testProcessTransientErrorHoldsCalculatedAndRethrows`, asserted the correct
behaviour and was deleted.

**Why it matters.** FRD §7: "Transient ERP Refund API errors never change the
main status... a Create Refund that fails leaves the record at `calculated`,
ready to retry." §9.6: "Only a definitive business rejection is terminal." Folding
all three exception types into one path means any blip in the ERP stub - a
timeout, a 503, a rate limit at the stated 25 submissions/minute peak -
permanently fails the refund and forces a human "new business decision" (per §7)
for something that should retry automatically. This is an operational
correctness bug with the widest blast radius of anything found: it fires on
every transient failure, not just an edge case.

**What I'd do:** restore the three-way catch (or a `match` on exception class),
put back the "transient holds `calculated` and rethrows" test, and keep the
business-rejection path as the only one that sets `failed`.

**Review comment I'd send:** "This merges three exception types with
deliberately different recoverability semantics into one catch block. A 503 or a
timeout now permanently fails the refund and needs a human business decision,
when the spec says it should retry automatically. The giveaway is the test got
rewritten to assert the new (wrong) behaviour instead of catching it. Please
restore the three-way handling and the original transient-error test."

---

### 3. High - outbound tax code is mapped in the wrong direction, breaking the ERP contract

**File:** `app/code/Acme/SellerRefund/Model/Erp/PayloadBuilder.php::buildTaxes()`

`PayloadBuilder` now runs the line's business tax code (`010`/`008`) through
`TaxCodeResolver::toOptionId()` before sending it to ERP. Confirmed by the PR's
own updated test, which now asserts `'code' => '7'` where it previously asserted
`'code' => '010'`.

**Why it matters.** FRD §11 is explicit about the direction of this mapping: "The
store must send the admin option **value**... the business code `999`/`010`/`008`
... These business codes are what the ERP expects... on any **inbound** sync,
convert a received business code back to the corresponding local attribute
option." PR-02 does the inbound conversion outbound - every Create Refund call
will now hand the ERP a store-internal attribute option id instead of the stable,
contractually-shared business code. Every refund line touches this path, so this
breaks the integration wholesale, not at the margin.

**What I'd do:** send `$item->getTaxCode()` (the business code) unchanged in the
payload; reserve `TaxCodeResolver` for the inbound-sync direction the FRD
describes.

---

### 4. High - the Create Refund idempotency key changes value on retry, inside the field it's supposed to protect

**File:** `app/code/Acme/SellerRefund/Model/Erp/RequestKey.php`,
`Model/Erp/PayloadBuilder.php::build()`

`RequestKey::forAttempt()` returns the plain `refund_no` on attempt 1 but a
suffixed value (`SR-...-02`) on later attempts, and `PayloadBuilder` writes that
suffixed value into the payload's `refund_no` field itself (not just a
correlation header). BR-07: "Create Refund uses `refund_no` as its idempotency
key... a retry of a create that already succeeded must not open a second credit
note." Sending a different `refund_no` on retry defeats that guarantee outright -
ERP has no way to recognise attempt 2 as a retry of attempt 1's credit note.

This compounds with the second half of the same change: PR-02 also deletes the
local pre-check (`ErpRefundClient::hasSucceededCreate()` /
`Refund::isCreateSucceeded()`) that guarded exactly this case (see the deleted
`REF-142` comment: "a retried Create after a worker crash must not open a second
credit note"). With both the local guard and the stable key gone, nothing stops
a retried create from opening a second credit note in the ERP.

**What I'd do:** keep `refund_no` constant across attempts in the payload; if a
per-attempt correlation id is wanted for ERP-side audit trails, carry it in a
header (`X-Request-Id`, which the PR already adds) rather than the idempotency
field. Restore the local pre-check as defence in depth.

---

### 5. Medium - worklist grid reintroduces N+1 queries and drops its page size

**File:** `app/code/Acme/SellerRefund/Block/Adminhtml/Refund/Worklist.php::_prepareCollection()`

`main` joined the order summary and line summary at the collection level
specifically so "the template renders one row per refund from joined columns
alone and never loads an order model per row" (deleted docblock, verbatim).
PR-02 replaces that with a `foreach` over the collection calling
`$orderRepository->get()` and `$refundRepository->getItems()` per row, and drops
`setPageSize(50)` with nothing to replace it.

**Why it matters.** The architecture overview already flags the worklist as a hot
path with a 1.5s first-page SLA at ~40 concurrent operators; this PR moves
directly against that constraint on the very surface the NFRs single out, and
removes the only pagination bound in the same change.

---

### 6. Low / unexplained - drops the `(status, created_at)` index

**File:** `app/code/Acme/SellerRefund/etc/db_schema.xml`

`MP_REFUND_STATUS_CREATED_AT` is removed, unmentioned in the PR description. It's
the index the settlement sweep (`cash_refunded` scan) and worklist status
filtering would both want, and the architecture overview already calls out
"additional indexes on the settlement scan" as a future risk - this makes it
worse instead. Worth asking the author directly why an index drop rode along in
a PR about "ERP tax mapping and refund retry handling."

## PR-01 - `review/pr-01-partial-refund-presentation`

### 1. High - receipt stops reading from the stored refund snapshot; "refunded before" double-counts the current refund

**File:** `app/code/Acme/SellerRefund/Model/Pdf/RefundReceipt.php::buildData()`

The class docblock used to read: "Every figure is taken from the stored refund
snapshot through the calculator, so the receipt agrees with the other
presentation surfaces and the downstream export." PR-01 deletes
`RefundTotalCalculator` and instead recomputes the header live from
`$order->getAllVisibleItems()`, and computes "qty refunded before" via a new
live query, `ResourceModel\Refund::loadRefundedQtyByOrder()`, instead of reading
the already-stored, immutable `mp_refund_item.qty_refunded_before` column (which
FRD §6 lists as captured at refund-creation time for exactly this purpose).

That new query filters only by `order_id` and excludes cancelled/failed
statuses - it does **not** exclude the refund currently being rendered. For the
very refund whose receipt is being generated, its own just-inserted line
quantities get summed into "already refunded before it" - a straightforward
double-count, and a step away from FRD §10.4's "derived from the same stored
refund snapshot" requirement (this receipt now disagrees with a surface built
from the same underlying rows, on principle, whenever the order is touched again
later).

**What I'd do:** read `mp_refund_item.qty_refunded_before` directly (it's already
captured at creation time) rather than deriving it live; if a live figure is
kept for another reason, exclude the current `refund_id` from the aggregate.

### 2. High - submit button no longer disabled in flight; UI fabricates "Refunded" before the server responds

**File:** `app/code/Acme/SellerRefund/view/adminhtml/web/js/refund-form.js`

`_create()` drops the `inFlight` flag, and `_save()` now sets the state text to
"Refunded" and reveals the receipt link **before** the AJAX call resolves. The
`.fail()` handler and all server-state rendering - including the required
"Saved; ERP notification pending retry" message - are deleted outright.

**Why it matters.** FRD §14 is explicit on both counts: "The submit action
remains disabled while a submission request is in flight" and "the browser must
not invent a lifecycle transition or show a confirmed state ahead of the
server." If the request fails outright (timeout, network drop), the operator now
sees "Refunded" with a live receipt link for a refund that was never saved -
worse than before this PR, not better, and it removes the double-submit
throttle that existed as one layer of defence for BR-05.

**What I'd do:** restore the disabled-while-in-flight state and the
server-state-driven rendering; only reveal the receipt link and "Refunded" copy
once the server confirms the record exists (BR-11 ties receipt availability to
the record existing, not to the click happening).

### 3. Medium - the new test doesn't exercise the scenario the PR claims to fix

**File:** `app/code/Acme/SellerRefund/Test/Unit/Model/Pdf/RefundReceiptTest.php`

The PR description says QA reopened this because the *partial*-refund breakdown
was missing from the receipt. The one new test covers a *full* refund with a
single tax rate and an empty refunded-before map - it never exercises the
partial-refund label/breakdown, multiple tax-rate groups, or the double-count
bug in finding #1 above.

## Ranking recap and Part 3 candidate

Two PR-02 findings sit above everything else because they violate a *numbered*
business rule on every affected refund, not an edge case: the over-refund guard
(BR-03) and the transient-error-as-terminal handling (§7/§9.6). Between the two,
**the quantity guard (PR-02 #1)** is the cleaner, most self-contained fix - a
single method, an obvious regression test (two sequential partial refunds
against one line, second one rejected once remaining hits zero) - and it's the
one I picked for Part 3.
