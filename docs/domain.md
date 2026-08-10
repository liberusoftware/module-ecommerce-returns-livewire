# The domain, as this package presents it

This package owns no rule. Every rule below belongs to
`liberusoftware/ecommerce-returns`, and what is written here is which of them a
storefront surfaces, in what words, and what it refuses to surface at all.

The one thing this package genuinely decides is **what a shopper is allowed to
do**, and that is where most of this document goes.

---

## 1. Whose returns these are, and what stops them being somebody else's

### The problem, stated plainly

Six components render, three of them are routable pages, and one of them
**writes**. Every value on a Livewire component travels to the browser and back
on every request, which makes every one of them an input written by a client. So
"whose record is this" cannot be answered by what arrives; it has to be answered
by what the server already knows, before anything that arrived is looked at.

### What travels

Two handles, and nothing else:

| Handle | What it is | Where it comes from |
| --- | --- | --- |
| `ReturnDetail::$number`, `ReturnPage::$number` | `RMA-` and twelve hex characters | the domain, minted from 48 bits of CSPRNG output |
| `StartReturn::$orderNumber`, `StartReturnPage::$orderNumber` | the purchase's own public handle | the module that owns purchases, through the host |

**No incrementing id appears anywhere in this package** — not as a property, not
as a mount parameter, not in a URL, not in the rendered markup. An id on a
customer-facing page is an enumeration of everybody's records, which is the
entire argument that gave both a handle in the first place.
`tests/Feature/IdentityTest.php` asserts that by reflection over every registered
component, and again by walking the rendered markup for the attribute a link is
built from.

There is no customer id, store id, team id, order id or order line id on any
component, and no mount parameter for one. Those are resolved by the server on
every request, so there is nowhere for them to arrive from.

### Three controls, in this order

1. **Every read starts from the actor.** `Concerns\ShowsOwnReturns::mine()` is the
   only place a query begins, and it begins with the domain's own
   `forCustomer($customerId)`, resolved from the signed-in account by
   `Support\ShopperContext`. The number, and the storefront, are applied *to that
   builder*. There is no path through this package that reads a return without a
   customer id already bound. `IdentityTest` asserts that this package reaches the
   domain's query object exactly once, through the read that takes the actor.
2. **Every write starts from the actor too.** `Support\OrderSource::forShopper()`
   takes a customer id as its **first argument** and a handle second, and there is
   no method on it that omits the first. So the ownership question is answered
   before an order line id from the browser is looked at, and the line the shopper
   named is then matched **by identity** against the lines that answer came back
   with.
3. **Both handles are `#[Locked]`**, so the browser cannot swap the one it was
   handed. This is the second control, not the first: `IdentityTest` removes the
   lock by constructing each component directly — something a browser cannot do —
   and asserts the read still finds nothing.

### Not found and not yours are the same answer

Four cases collapse into one 404 with no distinguishing message:

- a handle that names nothing;
- a handle that names somebody else's record;
- an empty string;
- for the request surface, a deployment that has bound no source at all.

Telling any of them apart is how a caller learns which handles exist, which is
the enumeration the handle was minted to prevent. `docs/runbook.md` is where an
operator finds out which of the four it actually was.

### A signed-out visitor is not 404'd

A guest is shown "sign in to see your returns", and **no query runs and no source
is consulted**. That is not politeness — it is what stops the response being an
oracle. If a guest got a 404 for a handle that exists and a sign-in prompt for one
that does not, the difference would be a lookup service for somebody else's
records.

`Support\ShopperContext::customerId()` answers null for a guest, and nothing in
this package builds a query without a customer id. A `where('customer_id', null)`
compiles to `is null`, which lists precisely the orphan rows belonging to no
account — the leak written as a tautology. That query cannot be built here,
because there is no code path that starts one without an id.

The same care applies to the storefront clause: it is applied with `when()` and a
bound value, never `where('store_id', $storeId)` with a null, which would answer
with the orphan returns belonging to no storefront instead of with none.

---

## 2. Every public property is locked, with no exceptions list

Five public properties across six components, and every one carries `#[Locked]`.
`IdentityTest` asserts the exceptions list is empty rather than asserting a
particular set, so a property added next year without the attribute fails there
rather than never.

That rule survives a surface that takes real input because **anything a shopper
types arrives as a method argument**:

```php
public function request(string $line = '', string $quantity = '', string $reason = '', string $note = ''): void
```

Four strings, because that is what a browser sends. Each is turned into something
the domain will accept, or refused, before it gets near one — and none of them is
ever assigned to a property, so none of them can be re-sent on the next request as
though the server had chosen it.

Cancellation goes further and takes **no arguments at all**. A shopper pressing
"cancel this request" is itself the reason; the slug written to the domain's
history column is a constant chosen on the server.

---

## 3. Eligibility is an input, exactly the way tax is an input

Whether a unit may come back is `delivered − already returned`, over counters that
live in the module that owns purchases. The domain package refuses to compute it,
refuses to guess it and refuses to look it up — `ReturnLineInput::$returnableQuantity`
is an input, handed in by whoever asked.

So this package refuses too, and the refusal has a shape:

- `Support\OrderSource` is a class the **host binds**. Its default answers null.
- It is asked at render to draw the form, and **again inside the write**. The
  second answer is the one the request is built from, because another return may
  have landed in between. `tests/Feature/RequestTest.php` swaps the source between
  the two and asserts the second answer wins.
- The number is **never** a property, never a form field, and never accepted from
  the browser. A ceiling the client can hold still while the truth moves is not a
  ceiling.

What the domain then does with it is **store it**, as evidence of what the shopper
was told on the day they asked. Three months later, that is what the argument is
about.

### Refused, never capped

Asking for more than is returnable raises `ReturnQuantityExceeded`, and this
package surfaces that refusal in words **with both numbers in it**:

> You asked to send back 9, but only 3 of this item can still be returned.

Quietly reducing nine to three would tell the shopper they are sending nine things
back and the receiving desk to expect three, and the disagreement would first
appear as a wrong refund — which is the worst place in the system to find an
error.

---

## 4. Reasons are a closed set; the note is contained

`ReturnReason` is seven slugs, and the control is a `<select>` enumerated from the
enum's own cases rather than from configuration. A deployment cannot add an
eighth, and a release that adds one cannot leave this page behind.

The value is validated with `ReturnReason::tryFrom()`, which is identity against
the cases. `FAULTY`, `faulty ` with a trailing space, and a sentence somebody typed
are all **dropped** — not truncated into something that happens to match, not
stored, and the shopper is told to choose one.

That matters because the slug is copied into a domain event and a log line, and a
free-text field next to an event logger is where a customer's email address, or
the name of the person a gift was for, ends up in somebody's log retention.

### The note goes in and cannot come out

`ReturnLine::$note` is the one free-text field in the whole domain, and it exists
because a shopper returning a faulty item genuinely does have something to say
that seven slugs cannot hold. The domain contains it **by rule**, each half pinned
by a test there:

| Where | Does the note travel? |
| --- | --- |
| the `ReturnLine` model, behind the policy | **Yes.** A staff surface reading a shopper's sentence is the job. |
| `Data\ReturnLineData` | **No** — the field does not exist on the read model. |
| any domain event | **No.** |
| any log line | **No.** |

This package accepts one and adds one refusal of its own, and then has **no way at
all to get it back out**, because every read here goes through the read model. It
is not in the announcement, not in the refusal, not in the rendered markup, not in
a URL and not in a query string — this package binds nothing to the URL at all.

`tests/Feature/RequestTest.php` asserts the note is on the model **first**, and
only then asserts the four absences, so none of them can pass by there being no
note to find.

**Too long is refused, not truncated.** The column is `text` and imposes no limit,
so the limit is this package's, it is configurable, and going over it is an error
message rather than a silent cut. The half a truncation removes is the half
describing the fault, and the shopper is never told it happened.

---

## 5. What a shopper may do, and what they may not

### Returns begin after delivery

Whoever owns purchases drew the line there: `completed → cancelled` is not a legal
transition over there, and cancelling something with anything already sent is
refused. Calling off what has not happened is a cancellation; getting back what has
is a return.

So this package says which, in words, rather than rendering an empty list:

- a purchase with **nothing sent yet** gets "there is nothing to send back — if you
  no longer want it, you can still cancel the order", and a link to the order;
- a purchase with **nothing returnable left** gets "there is nothing left on this
  order to send back";
- a delivered purchase gets the form.

`ReturnableOrder::$anythingDelivered` is what the host answers that with. It is a
flag rather than a computation for the same reason `returnableQuantity` is: this
package cannot see a purchase.

### Requesting, and cancelling, and nothing else

The domain publishes seven staff abilities on a return. **One** is offered here:

| Ability | Offered? | Why |
| --- | --- | --- |
| request | ✅ | It is the shopper's own act. |
| cancel | ✅ | Calling off a request you made, before anything has moved, is the one thing a shopper genuinely owns. Drawn only where the domain's transition table will take it — `requested` and `approved`, and it stops the moment goods arrive. |
| approve | ❌ | The merchant deciding, for a quantity of their own choosing, whether goods may come back. A shopper authorising their own return is the workflow with no merchant in it. |
| refuse | ❌ | The same decision, the other way. |
| receive | ❌ | A receiving desk saying a parcel physically arrived. Nobody can assert that from a browser, and a shopper who could would be able to trigger a refund for a box nobody has. |
| inspect | ❌ | A judgement about the condition of goods somebody is holding. |
| resolve | ❌ | Closing the arithmetic. |
| record a refund | ❌ | Money. See §6. |

Neither offered control restates a rule. `cancellable()` asks the domain's
transition table; pressing the button calls the domain's action, which consults it
again before it writes. Two guards on the same rule, because the first is a render
and the second is the truth: a parcel can be booked in between the page being drawn
and the button being pressed, and that race resolves in the domain's favour — the
refusal comes back from the state machine, and the shopper is told which state it
actually reached.

### Only the states the domain publishes

The status list in `resources/lang/en/returns.php` is asserted **equal** to
`ReturnStatus::cases()`, not a subset in either direction. A missing key renders a
raw translation key at the worst possible moment; an extra one is this package
publishing a state the domain refused, which puts a disagreement in front of the
customer instead of in a column.

Progress within a state is not a state, and there is no word for one here. A return
of five can be two received, one rejected on inspection and two still in a van at
the same moment; the quantities say that, and no word can.

### Inspecting closes the door, so the page says so

There is a `next.<status>` sentence for every one of the eight states, and two of
them are why it exists:

- **inspected** — every received unit has a disposition, the merchant has priced the
  outcome, and a parcel arriving now would land against settled arithmetic. The
  domain refuses to adopt it, loudly.
- **expired** — the authorisation window ran out and nothing came.

Both are closed to further goods. A shopper holding a parcel is told "this request
is closed to further items — if you still have something to send back, start a new
request", which is an answer. A page with no controls on it is a dead end.

`closedToGoods()` derives that from the domain's own two answers rather than from a
list of state names copied over: a return is closed when it does not accept goods
now and can no longer reach the state that would. A freshly requested return fails
the second half — nobody has authorised it yet, but somebody still might — so it is
waiting rather than closed.

---

## 6. A shopper can never say what they are owed

There is **no amount property, no currency property and no refund control** on any
component in this package, and `tests/Feature/ReturnTest.php` asserts the absence
by reflection rather than asserting that an amount is validated.

What is rendered is `ReturnData::$refundedMinor` — a sum over the refund rows, which
the domain computes and stores nowhere. A row exists only because money already
moved.

Three things are deliberately not rendered:

| Not shown | Why |
| --- | --- |
| a refund status | The domain publishes none. `pending / processing / failed` would be a second, diverging copy of a state machine somebody else's system owns. |
| a balance, or "fully refunded" | This package holds no line prices, so it cannot know what "fully" would be, and a number claiming to know is a number somebody trusts. |
| the opaque reference | It is whatever the host calls the movement — a ledger entry, a credit note — and it is never parsed anywhere. Putting an unvetted internal identifier on a storefront page is not a decision this package gets to make on a deployment's behalf. |

Money is rendered from integer minor units by string arithmetic, through the
domain's own `Money::decimal()`. There is no float on the path: `(int) (19.99 * 100)`
is 1998, and the same class of error on the way out is a penny of drift per line.
`Number::currency()` is deliberately not used, because it takes a float.

---

## 7. Two per-line quantities are deliberately not shown

The domain keeps five per line. Three are rendered — requested, approved, received
— plus the derived "still to send back".

`restockable` and `rejected` are **not**. They are an inspection disposition: a
merchant's judgement about the condition of goods they are holding. Putting "1
rejected" on a storefront states a verdict without the conversation that goes with
it, and the shopper has no way to answer it from a web page.

The consequence a shopper is entitled to see is the money, and that is rendered.
A deployment that disagrees publishes the view and adds them; the counters are on
the read model and nothing hides them.

---

## 8. What crosses the boundary

Nothing but identifiers and values already resolved. This package requires exactly
one `liberusoftware/*` package — the domain module it presents — and `src/` names
no other commerce namespace at all. `tests/Feature/BoundaryTest.php` asserts that
as a set rather than by listing what is forbidden, because a test that spells out a
forbidden name puts that name in the repository in order to go looking for it.

The whole suite runs with **no module that owns an order, a product, a basket or a
checkout installed**, over ids nothing in the database has heard of, under a test
named for the fact.

The two places this domain's work has to become somebody else's — goods arriving,
and an inspection saying what is saleable — are events the **host** subscribes to.
This package registers no listener, because the host is the only party entitled to
know that two modules exist. See `docs/adoption.md`.
