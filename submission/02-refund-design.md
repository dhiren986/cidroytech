# 02 - Refund Design

## A note on scope

The FRD describes two events that are *decoupled by design* — the operator's
click and the ERP's eventual answer — and one contradiction worth resolving
before any of this is built:

**BR-02 says the refund window is 14 calendar days from delivery. The very next
paragraph ("Contract note (procurement)") says the signed seller agreement sets
it at 7 days, and that CS must not be able to override the contractual window.**
These can't both be the enforced rule. I've designed against **7 days**, since
the contract note frames it as the binding legal constraint and explicitly says
the eligibility check must come from the delivery record, not an
operator-overridable setting — but this is exactly the kind of thing to get a
one-line confirmation on before writing a line of code (see also
`06-questions-and-next-steps.md`).

## Sequence diagram

Design principle: the operator-facing request only ever does fast, local work
(validate, calculate, persist, enqueue). Every ERP round trip happens off the
request thread, driven by a scheduled sweep against sub-status fields, per the
FRD's own "DB outbox with cron/CLI consumers" baseline. This is the single
biggest divergence from the inherited Architecture Overview, whose synchronous,
in-request ERP calls are the subject of `03-architecture-review.md`.

```mermaid
sequenceDiagram
    actor CS as CS Operator
    participant UI as Admin Refund Screen
    participant Ctrl as RefundController
    participant DB as mp_refund / mp_refund_item / mp_refund_event
    participant Outbox as Outbox + Cron Dispatcher
    participant ERP as ERP Refund API (stub)
    actor Fin as Finance (offline)

    CS->>UI: Select seller lines & quantities
    UI->>Ctrl: Calculate (server-side, every change)
    Ctrl-->>UI: Line + grand totals (read-only)

    CS->>UI: Submit (confirmation dialog shows total)
    UI->>Ctrl: POST save (submit disabled until response)
    Ctrl->>Ctrl: Re-validate qty vs remaining (BR-03), reason code,<br/>window vs delivery date (7 days), one-active-refund guard (BR-05)

    alt validation fails
        Ctrl-->>UI: 422 + reason
        UI-->>CS: Show error, re-enable submit
    else validation passes
        Ctrl->>DB: INSERT mp_refund(calculated) + mp_refund_item rows<br/>+ outbox row (op=create, key=refund_no) — one transaction (BR-06)
        DB-->>Ctrl: committed
        Ctrl-->>UI: 200 "Saved; ERP notification pending" (refund_no, status=calculated)
        UI-->>CS: Show saved state (not "Refunded") + receipt link (BR-11)

        Note over Outbox,ERP: Async, outside the request
        Outbox->>ERP: POST Create Refund (idempotency key = refund_no)
        alt 2xx
            ERP-->>Outbox: erp_refund_id, status=refund-pending
            Outbox->>DB: status→cash_refund_pending, create_status=succeeded
        else timeout / 5xx / 429
            Outbox->>DB: create_status=retryable_error (main status holds)
            Outbox->>Outbox: retry with backoff, same key
        else definitive business rejection
            Outbox->>DB: status→failed, create_status=business_rejected
            Note right of DB: terminal — needs a new business decision
        end
    end

    Fin-->>CS: Bank transfer completed (offline, own schedule)
    CS->>UI: Open refund, press "Refund Complete"
    UI->>Ctrl: POST cash-refund (transaction_number, transaction_date)
    Ctrl->>Ctrl: Require cash_refund_status not yet succeeded;<br/>require status = cash_refund_pending (BR-10)
    Ctrl->>DB: status→cash_refunded, cash_refund_status=succeeded
    Ctrl-->>UI: 200 (fast — no ERP call on this request)

    Note over Outbox,ERP: Scheduled sweep, bounded batches, on a fixed interval (FRD §9.2)
    loop every N seconds, rows where status=cash_refunded
        Outbox->>ERP: GET Read Refund Status(erp_refund_id)
        alt found, refund-pending
            Outbox->>DB: status→erp_confirm_pending
            Outbox->>ERP: POST Confirm Refund (idempotency key = erp_refund_id,<br/>transaction_number, transaction_date)
            alt 2xx or already-confirmed-matching (BR-08)
                Outbox->>DB: status→erp_confirmed, confirm_status=succeeded
            else timeout / 5xx / 429
                Outbox->>DB: confirm_status=retryable_error (status holds)
            else business rejection
                Outbox->>DB: status→failed, confirm_status=business_rejected
            end
        else not-found (eventually consistent) or error
            Outbox->>DB: status_check_status=retryable_error, retry within bound
        end
    end

    UI-->>CS: Worklist/detail poll every 2s reflects current sub-status only<br/>(never a status the server hasn't declared)
```

Failure boundaries called out deliberately: validation rejection (nothing
persisted), Create Refund transient vs. business rejection (BR-06/BR-07),
cash-refund registration gating confirmation (BR-10), Read-Status
not-found-but-eventually-consistent, Confirm Refund transient vs. business
rejection, and Confirm Refund idempotent replay (BR-08). Everything after
"Saved" is retryable from the worklist without an operator ever touching the
database, per the NFR on recovery.

## Phased delivery plan (epics)

| # | Epic | Depends on | Acceptance gate |
|---|------|-----------|------------------|
| 1 | **Refund core**: entry screen, server-side calculation/tax, `mp_refund`/`mp_refund_item` persistence, ACL-gated actions, per-order concurrency guard (BR-05) | — | CS can create full/partial refunds against a seeded seller order; over-quantity, wrong role, and duplicate-in-flight submissions are all rejected server-side; no ERP call yet |
| 2 | **ERP Create Refund via outbox**: payload builder (correct tax-code direction), idempotent dispatch keyed on `refund_no`, retry/backoff, event log | 1 | Under an induced timeout/5xx/429/business-rejection matrix against the stub, exactly one credit note opens per refund, main status transitions match FRD §7 exactly, and every attempt is retryable from the worklist without a DB edit |
| 3 | **Cash-refund registration + confirmation sweep**: Refund Complete action, scheduled Read-Status→Confirm sweep, BR-08/BR-10 gating | 2 | Full happy path reaches `erp_confirmed`; a sweep run that hits a transient error on one refund doesn't block the others in its batch; a repeated Confirm call against an already-confirmed refund is a no-op success |
| 4 | **Presentation surfaces**: one shared calculation/snapshot service consumed by receipt PDF, order-details, order history, email, and the downstream export | 1 (can start once schema is stable; independent of 2/3) | All five surfaces render identical figures for the same refund from one shared acceptance-test fixture; changing the shared calculator once updates all five |
| 5 | **Non-functional hardening**: worklist server-side pagination + indexing, settlement-scan indexing, load test at stated volumes | 1–3 substantially complete | Worklist first page < 1.5s at a simulated 40 concurrent operators / current refund volume; settlement sweep completes within its interval at 20k candidate rows |
| 6 | **Ops/observability** (stretch): correlation id surfaced end-to-end in the UI's "safe summary," dashboards on sub-status distribution, alerting on refunds stuck in a retryable sub-status past a threshold | 2–3 | On-call can identify and explain a stuck refund from the event log and a dashboard alone, without a database console |

Epics 1→2→3 are a hard chain (each needs the previous epic's data/contract).
Epic 4 only needs epic 1's schema and can run in parallel with 2/3 — it has no
ERP dependency. Epic 5 is validation work that should start incrementally
alongside 2/3 rather than being saved entirely for the end, but its gate is the
last one checked before go-live. Epic 6 is genuinely optional for a first
release at the stated scale (5,000 refunds/month) and is the one I'd cut first
under time pressure.
