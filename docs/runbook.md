# Runbook

For whoever is holding the pager. Symptom first, then what to check, then what
it means.

---

## Read this before anything else

**This package has no jobs, no queues, no listeners, no commands, no schedule,
no migrations and no tables.** It is six Livewire components, a service
provider, two swappable support classes, three views and three pages. It runs
only while an HTTP request is in flight.

So there is nothing here to restart, nothing to drain and nothing to replay. If
you are looking at a backlog, a stuck job or a growing table, it is not this.

**And this is the load-bearing fact for most pages you will get about it:**

> A shopper can cause exactly two things through this package — a return
> **request**, and the **cancellation of a request they made**. Nothing else.

There is no amount property, no currency property and no refund control on any
component; `tests/Feature/ReturnTest.php` asserts the absence by reflection.
There is no approve, refuse, receive, inspect or resolve surface. `status` is not
fillable on the domain model and `TransitionReturn` is the single door.

So if you are paged about a refund that should not have happened, an approval
nobody remembers making, or a status that moved on its own, **it did not come
from here**, and the fastest way to prove it is one query:

```sql
SELECT to_status, actor_id, reason, created_at
FROM ecommerce_returns_status_changes
WHERE return_request_id = ?
ORDER BY created_at;
```

The only row this package can ever write is `to_status = 'cancelled'` with
`reason = 'cancelled-by-shopper'` and `actor_id` = the shopper's own customer
id — plus the opening row (`from_status` null, `to_status = 'requested'`) that
`RequestReturn` writes. Anything else came from a staff surface, a console
command, or a host listener.

---

## 1. Every "send something back" page 404s

**Symptom.** `/orders/{handle}/return` returns 404 for every shopper and every
order, including ones you can see are delivered. The history page and the return
detail page work fine.

**Almost certainly.** `Support\OrderSource` has not been bound. The shipped
default `forShopper()` returns `null` for everything, and `null` is the same
answer as "no such handle" and "not this shopper's" — deliberately, so a caller
cannot tell the three apart. That is why there is no error in the log.

**Check.**

```php
php artisan tinker
>>> get_class(app(\Liberu\Ecommerce\Returns\Livewire\Support\OrderSource::class));
```

If that prints `Liberu\Ecommerce\Returns\Livewire\Support\OrderSource` — the base
class, not an anonymous subclass or one of yours — nothing is bound. See
`docs/adoption.md` §2.1.

**If something is bound**, call it directly with a customer id and a handle you
know go together:

```php
>>> app(\Liberu\Ecommerce\Returns\Livewire\Support\OrderSource::class)->forShopper(123, 'ORD-…');
```

`null` from that is the whole failure. Work backwards: is the handle the
purchase's **public number** rather than a row id? Is `customer_id` on that order
the same integer `ShopperContext::customerId()` returns (§2)? Is the source
scoping on a tenant or a store the shopper is not in?

**Not a bug.** The 404 is correct behaviour for all three causes. This section
exists because the correct behaviour is indistinguishable from a
misconfiguration, on purpose.

### 1b. One return's page 404s for the shopper it belongs to

Same shape, different surface. `/returns/{number}` answers one 404 with no
distinguishing message to four different cases: a number that names nothing, a
number that names somebody else's return, an empty string, and a signed-in
shopper whose id is not the one on the row. Telling them apart is how a caller
learns which RMA numbers exist.

You are the operator, so you may look:

```sql
SELECT id, number, customer_id, store_id, status
FROM ecommerce_returns_requests
WHERE number = ?;
```

- **No row** — the number is wrong. RMA numbers are `RMA-` and twelve hex
  characters; check for a transcription error before anything else.
- **A row whose `customer_id` is somebody else's** — correct refusal.
- **A row whose `customer_id` is `null`** — an orphan. It belongs to nobody and
  no shopper will ever see it, by design; `where('customer_id', null)` compiles
  to `is null` and would list precisely these, which is why no code path here can
  build that query. Find out how it was written.
- **A row that looks right** — then either `ShopperContext::customerId()` is
  answering something else (§2) or `store_id` excludes it (§3).

---

## 2. Signed-in shoppers are told to sign in

**Symptom.** Every page shows "Sign in to see your returns" to people who are
demonstrably signed in. No error, no exception, nothing in the log.

**Cause.** `ShopperContext::customerId()` is returning `null`. It is
`Auth::id()` guarded by `is_numeric()`, so it answers `null` whenever:

- the user's primary key is a **ULID or UUID** — the single most common cause,
  because the domain's `customer_id` is a `foreignId` (unsigned bigint) and a
  string key can never be one;
- the storefront authenticates on a **guard the default `Auth::id()` does not
  resolve**;
- the route is missing session middleware, so nobody is authenticated as far as
  the component is concerned.

**Check.**

```php
>>> app(\Liberu\Ecommerce\Returns\Livewire\Support\ShopperContext::class)->customerId();
```

inside a request with a signed-in user, or compare `Auth::id()` against
`is_numeric(Auth::id())`.

**Fix.** Rebind `ShopperContext` and map to whatever **integer** id purchases are
filed against — `docs/adoption.md` §2.2. Do not try to widen the column; the
domain owns it.

**Why it fails silently.** A guest is not an error condition here. It is the
answer, and no query runs and no source is consulted for one — which is what
stops a signed-out response being an oracle for which RMA numbers exist. The
price of that is that a broken identity looks exactly like a logged-out visitor.

---

## 3. A shopper's history is empty, but you can see their returns in the database

**Symptom.** "You have not sent anything back yet", for somebody who has.

**Check, in this order.**

1. **Are you sure `customerId()` is right?** Compare
   `ecommerce_returns_requests.customer_id` on their rows with what
   `ShopperContext::customerId()` answers for them. If it is null, see §2.
2. **`RETURNS_LIVEWIRE_STORE_ID`.** The read is narrowed to that storefront when
   it is set. Two traps:
   - **Setting it hides returns whose own `store_id` is `null`.** That is
     deliberate — a return belonging to no storefront is not this storefront's —
     but it means a deployment that adds multi-store *after* raising returns
     hides every historical one. Backfill `store_id`, or leave the setting unset.
   - **A non-numeric value is silently treated as `null`.**
     `RETURNS_LIVEWIRE_STORE_ID=main` does not filter by "main"; it disables the
     filter entirely and lists the shopper's returns across every storefront.
     This fails in the *permissive* direction, so it will not page you — it will
     turn up in a support ticket about seeing another shop's returns.
3. **The domain's `forCustomer()` orders by `requested_at` descending**, and the
   history fetches `showing + 1` rows. If several returns share a
   `requested_at` to the second, their relative order between two "show more"
   presses is not defined. Harmless, and it looks like a row moving.

```sql
SELECT id, number, customer_id, store_id, status, requested_at
FROM ecommerce_returns_requests
WHERE customer_id = ?
ORDER BY requested_at DESC;
```

---

## 4. "Only 3 of this item can still be returned" — and the shopper says that is wrong

**Symptom.** A shopper is refused with

> You asked to send back 9, but only 3 of this item can still be returned.

and insists more than three were delivered and none have gone back.

**This package did not choose either number.** `wanted` is what they typed;
`returnable` is what **your** `OrderSource` answered, read on the server inside
the write. This package never computes, caches or guesses eligibility, and
`tests/Feature/RequestTest.php` proves the value written to the database is the
one the source returned, not one from the form.

So the investigation is entirely on your side of the seam:

1. **Call your source directly** with that customer and handle and read
   `returnableQuantity` off the line. If it says 3, this package is doing its
   job and the bug is upstream.
2. **Is the order module's `returned` counter too high?** Reconcile it against
   what this domain has actually taken delivery of:

   ```php
   >>> new \Liberu\Ecommerce\Returns\Queries\ReturnQuery()->receivedForOrderLine($orderLineId);
   ```

   That number and the order line's `returnedQuantity` should agree. They are
   kept in step by the **host's** `ReturnGoodsReceived` listener
   (`docs/adoption.md` §6). If the order module is higher, the listener has run
   twice — its receipts are **deltas**, and a redelivered queue job posts the
   same delta again. If it is lower, the listener is missing or has been failing.
3. **Are you subtracting open requests too?** If you implemented
   `docs/adoption.md` §2.1.1, remember it double-counts received-but-not-yet-
   accounted units until the listener catches up. It under-offers, which is the
   safe direction, but it does produce exactly this complaint.

**Never "fix" this by capping.** Quietly reducing nine to three would tell the
shopper they are sending nine things back and the receiving desk to expect
three, and the disagreement would first surface as a wrong refund — the worst
place in the system to find an error. The refusal carries both numbers so a
support agent can see the disagreement without asking the shopper to guess.

---

## 5. One order line has several open return requests against it

**Symptom.** A staff queue shows three `requested` returns for the same order
line, all raised within minutes.

**Expected, and here is why.** The `returned` counter your source reads only
moves when goods are **received**, so until a merchant approves and a parcel
arrives, the ceiling has not fallen. The domain considered and refused an
idempotency key on a request: a duplicated *placement* charges somebody twice,
but a duplicated *return request* is a second piece of paper a merchant can
refuse. What it does guard is the thing that would corrupt arithmetic — a unique
index on `(return_request_id, order_line_id)`, so one return can never list the
same line twice.

**Nothing has been authorised and no money has moved.** Approval, receipt,
inspection and refund are all staff acts.

**If you want it to stop:**

- subtract open requested quantity in your `OrderSource` (`docs/adoption.md`
  §2.1.1) — this is the right place, because the ceiling must be one number from
  one place;
- and/or put a rate limiter on the start-return route, which is yours.

Do not add a rule inside a component. A ceiling with two answers has one wrong
answer.

---

## 6. The cancel button is not there

**Symptom.** A shopper (or an agent on the phone with one) cannot find "cancel
this request".

**Two possible reasons, and both are correct behaviour.**

1. **`returns-livewire.cancellation.enabled` is `false`** — check
   `RETURNS_LIVEWIRE_CANCELLATION`. Calling the method anyway is refused too, so
   there is no console workaround from the shopper's side.
2. **The state machine will not take it.** The control is drawn only where
   `ReturnStatus::canTransitionTo(Cancelled)` is true, which is `requested` and
   `approved` and nowhere else. **The moment goods arrive, cancelling is gone**
   — a return in `received`, `inspected`, `resolved`, `refused`, `expired` or
   already `cancelled` offers nothing.

Drawing a control the state machine would refuse is worse than drawing none: a
shopper who presses it has been told they can call the return off.

**A shopper who genuinely needs it cancelled after goods arrived is a staff
job**, and it is not a cancellation — it is an inspection outcome and a
resolution.

---

## 7. "This request is now Received, so it can no longer be called off"

**Symptom.** A shopper reports pressing cancel and being refused.

**Working as designed.** The page was drawn when the return was still
cancellable, a parcel was booked in before the button was pressed, and the race
resolved in the domain's favour. `ReturnDetail::cancel()` deliberately does
**not** re-check the state before calling — that would be a second copy of a rule
the domain owns — so the refusal comes back from the state machine itself, as
`IllegalReturnTransition`, and nothing is written: no status, no timestamp, no
history row, no event.

The component then **re-reads from the database** before choosing its wording, so
the state named in the message is where the return actually got to, not a stale
copy. If the message names a state that surprises you, that state is what is in
the table.

---

## 8. `Unable to find component` / a blank page where a component should be

**Symptom.** `<livewire:module-ecommerce-returns::return-history />` throws, or a
route bound to one of the page classes 500s at render.

**Check in this order.**

1. **Is the module enabled?** `MODULES_ENABLED` must name
   `ecommerce-returns-livewire` **and** `ecommerce-returns`. The package ships no
   `extra.laravel.providers`, so Composer discovery boots nothing at all —
   installing is not enabling. If `ReturnsLivewireServiceProvider` is not in
   `app()->getProviders()`, that is the whole answer.
2. **Is the alias spelled right?** Six, and no others:
   `return-history`, `return`, `start-return`, `returns-page`, `return-page`,
   `start-return-page`, each prefixed `module-ecommerce-returns::`. Note the
   namespace **drops** the `-livewire` suffix — it names the bounded context, not
   the technology.
3. **Did somebody fork or re-implement the provider?** If registration was
   rewritten to use `Livewire::addNamespace()` or to `Livewire::component()`
   alone, namespaced tags stop resolving while direct
   `Livewire::test(SomeClass::class)` keeps passing — so the test suite stays
   green and only rendered pages break. Livewire 4's
   `Finder::resolveClassComponentClassName()` returns `null` for a
   `namespace::name` before it consults the explicit registry;
   `Livewire::resolveMissingComponent()` is what answers. `docs/adoption.md` §3.

**Views missing rather than components:** check whether somebody published the
views and then deleted or renamed one.
`view()->exists('module-ecommerce-returns::livewire.return-detail')` in tinker
tells you in one line; a published copy in
`resources/views/vendor/module-ecommerce-returns/` wins over the package's.

---

## 9. A raw translation key is on the page

**Symptom.** A shopper sees literally
`module-ecommerce-returns::returns.status.something`.

**Cause.** A **published** language file that is behind the package. In this
repository the status and next-step lists are asserted **equal** to
`ReturnStatus::cases()` — not a subset in either direction — so a missing key
cannot ship from here. A published copy in
`lang/vendor/module-ecommerce-returns/` is a frozen snapshot, and a domain
release that adds a state leaves it short.

**Fix.** Re-publish (`--tag=module-ecommerce-returns-translations --force`) and
re-apply your wording, or diff your copy against
`resources/lang/en/returns.php` and add the missing keys.

**Why it is worth paging about.** The keys most likely to be missing are
`status.*` and `next.*`, and `next.expired` / `next.inspected` are precisely the
two sentences that tell a shopper holding a parcel that this return will not take
it and they need a new request. A raw key there is a dead end at the worst
moment.

---

## 10. A rendered amount is a penny out

**Symptom.** A refund shows as `GBP 19.98` where the row says `1999`, or an
amount has a trailing float artefact.

**Cause.** A **published view** that stopped using `$this->money()`. Everything
in this package is integer minor units, and `money()` is string arithmetic —
split on the point, pad to the currency's exponent, concatenate. `number_format()`
and `Number::currency()` both take a float, and `(int) (19.99 * 100)` is `1998`.

**Fix.** Put the amount back through `$this->money($minor, $currency, $exponent)`
in your published copy. `docs/adoption.md` §5.

The currency is rendered as a **code**, not a symbol, on purpose: a symbol table
is a per-locale problem this package would get wrong, and "GBP 19.99" is never
ambiguous about which of the four dollars it means.

---

## 11. Returns are being raised that staff cannot approve

**Symptom.** New returns appear in the database but a merchant's staff panel
either cannot see them or is refused when acting on them.

**Cause.** `team_id` on the return is `null`, which means your `OrderSource`
returned a `ReturnableOrder` with `teamId: null`. The domain registers a policy
that reads tenancy off the actor, and a return belonging to no team is
deliberately nobody's to act on — so that an orphan can be found without being
quietly claimed.

**Check.**

```sql
SELECT id, number, team_id, store_id, customer_id, created_at
FROM ecommerce_returns_requests
WHERE team_id IS NULL
ORDER BY created_at DESC;
```

**Fix.** Set `teamId` in your source (`docs/adoption.md` §2.1, obligation 4),
then backfill the orphans from the order they name. This package writes whatever
the source says and holds no opinion about tenancy.

---

## 12. "Where did the customer's note go?"

**Symptom.** A support agent cannot find the sentence a shopper says they typed
when raising the return.

**It is on the `ReturnLine` model, behind the domain's policy, and nowhere
else.** That is by design, and it is mechanical rather than a promise:

| Where | Present? |
| --- | --- |
| `ecommerce_returns_lines.note` | **yes** |
| `Data\ReturnLineData` (the read model) | no — the field does not exist on it |
| any domain event | no |
| any log line | no |
| anything this package renders, announces, links or puts in a URL | no |

Every read in this package goes through the read model, so there is **no path at
all** by which the note comes back out to a storefront. A staff surface with the
policy behind it is the correct place to read it, and that is what the containment
is for: a free-text field next to an event logger is where a customer's address —
or the name of the person a gift was for — ends up in log retention.

**If the shopper says they typed one and the column is empty**, check
`returns-livewire.request.note.enabled`. When it is off, the textarea is not
rendered **and anything submitted against it is dropped**.

**If they say it was cut short** — it was not. Over the limit is refused with a
message, never truncated, and nothing is written at all. They saw the refusal and
may not have realised the request did not go through.

---

## 13. Things that are not this package's problem

Route them, do not debug them here.

| Symptom | Owner |
| --- | --- |
| A refund went out that should not have | whoever moved the money, then the domain module. Nothing here can cause one. |
| A return was approved / received / inspected / resolved unexpectedly | staff surface, console command, or host listener. Check `ecommerce_returns_status_changes.actor_id`. |
| Approved returns piling up with no goods | the domain module's expiry sweep — its `docs/runbook.md` §1 |
| The order module's counters disagree with the returns module's | the host's `ReturnGoodsReceived` listener — the domain's `docs/runbook.md` §4 |
| Stock did not go back on the shelf | the host's `ReturnInspected` listener, and the inventory module |
| Slow storefront, N+1 | the history read already eager-loads `lines` and `refunds` and fetches one row more than the page to answer "show more" without a second `count()` query. If it is slow, look at the indexes on `ecommerce_returns_requests` (`customer_id`, `store_id`) first. |
| A shopper can see another shopper's return | **page immediately.** Every read here starts from `forCustomer($customerId)` and `IdentityTest` asserts that is the only entry point into the domain's query object. If it is genuinely happening, the cause is a rebound `ShopperContext` answering the wrong id, or a published view reaching past the read model. Capture the two ids before anything else. |
