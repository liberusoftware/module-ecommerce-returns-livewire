# Adoption

What a host application has to do to put this package's six components on a
storefront, and — because this is the one shopper-facing surface in this wave
that causes a domain write — exactly what a shopper can and cannot cause once it
is there.

Everything below is checkable against the code in this repository. Where a claim
is pinned by a test, the test is named.

---

## 1. Install

### 1.1 Two VCS repository entries, in the host's own `composer.json`

Neither this package nor the domain module it presents is on Packagist, and
**Composer honours `repositories` only from the root manifest**. This package
declares a VCS entry for its own dependency — which works for *its* CI, because
here this package is root — and that entry does nothing at all for you. Until
both are published, the host declares both itself:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-returns" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-returns-livewire" }
]
```

Two entries, not one. The repository names keep the `module-` prefix; the
Composer package names do not.

```bash
composer require liberusoftware/ecommerce-returns-livewire:^0.1
```

### 1.2 The domain package is in both `require` and `require-dev` here

`liberusoftware/ecommerce-returns: ^0.1` appears twice in this package's
`composer.json` on purpose. It is a real runtime dependency — every read goes
through `ReturnQuery`, every write through `RequestReturn` and
`TransitionReturn` — and it is *also* what this package's own test suite runs
against, and the only manifest whose `repositories` block Composer reads when
that suite runs is this one. `tests/Feature/BoundaryTest.php` asserts both
halves, and additionally that `module.json`'s `requires.packages` is **exactly**
the `liberusoftware/*` entries of `require` — one package, no more and no fewer.

Nothing about that duplication is the host's problem. It is documented here
because it looks like a mistake and is not.

### 1.3 Installing boots nothing

The package ships no `extra.laravel.providers`
(`BoundaryTest` and `RegistrationTest` both assert the empty array), so Composer
discovery registers nothing. `ModuleManagerServiceProvider` globs
`config('modules.paths')` for `*/module.json` and registers only what the
deployment names:

```dotenv
MODULES_ENABLED=ecommerce-returns,ecommerce-returns-livewire
```

Both. This package's `module.json` lists `liberusoftware/ecommerce-returns` in
`requires.packages`, and it is useless without it. `default_enabled` is `false`;
installing a module is not enabling it.

This package ships **no migrations and owns no tables.** Every table it reads is
the domain module's, so `php artisan migrate` matters only for that package —
see its own `docs/adoption.md`.

### 1.4 What the domain package needs, and what it does not

The domain's settings are documented there. Two notes that are specific to
composing it with *this* package:

| Domain setting | Does this package need it? |
| --- | --- |
| `returns.customer_model` | **No.** This package never calls `ReturnRequest::customer()`. It scopes on the `customer_id` column, which the domain never resolves to a class. A deployment that leaves this unset gets working storefront returns and a throw only from whatever *else* asks for the relation. |
| `returns.team_model` | Only indirectly. This package writes `team_id` from the purchase you describe (§2.1) and never reads the policy. Staff surfaces read it. |
| `returns.telemetry.*` | No. This package logs nothing. |

---

## 2. The two questions this package cannot answer itself

Both are singletons bound in `ReturnsLivewireServiceProvider::register()`, and
both are plain non-final classes so a deployment can extend and rebind them.
Nothing else in the package asks either question, so replacing one replaces the
answer everywhere — `RegistrationTest` asserts both are bound, and
`IdentityTest` proves the ShopperContext swap by rebinding it and watching every
read follow.

### 2.1 `Support\OrderSource` — what the shopper bought

**Binding this is not optional if you want the request form to work.** The
shipped default answers `null` for everything, which means every
`start-return` page 404s. That is deliberate: this package cannot see a
purchase, refuses to guess, and would rather refuse than invent.

Against `liberusoftware/ecommerce-orders` `0.1.0`, whose published line contract
is `OrderQuery::byNumber()`, `OrderQuery::lines()` and `OrderLineData`:

```php
use Liberu\Ecommerce\Orders\Data\OrderLineData;
use Liberu\Ecommerce\Orders\Queries\OrderQuery;
use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableLine;
use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableOrder;
use Liberu\Ecommerce\Returns\Livewire\Support\OrderSource;

// In the host's own service provider — never in the package. Only the host is
// entitled to know that both modules exist.
$this->app->singleton(OrderSource::class, fn (): OrderSource => new class extends OrderSource
{
    public function forShopper(int $customerId, string $handle): ?ReturnableOrder
    {
        $order = new OrderQuery()->byNumber($handle);

        // The actor is the first argument for a reason. Return null — never
        // throw, never a different message — for a purchase that is not this
        // shopper's. "Not found" and "not yours" must be indistinguishable.
        if ($order === null || $order->customer_id !== $customerId) {
            return null;
        }

        $lines = new OrderQuery()->lines($order);

        return new ReturnableOrder(
            orderId: $order->id,
            number: $order->number,
            currency: $order->currency,
            lines: array_map(fn (OrderLineData $line): ReturnableLine => new ReturnableLine(
                orderLineId: $line->id,
                name: $line->name,
                // Published there precisely so nobody derives it differently.
                // Read it, do not recompute it. See §2.1.1.
                returnableQuantity: $line->returnableQuantity(),
                sku: $line->sku,
                productId: $line->productId,
                variantId: $line->variantId,
            ), $lines),
            currencyExponent: $order->currency_exponent,
            // Returns begin after delivery. Anything with a fulfilled unit on
            // it has begun; everything else is still a cancellation.
            anythingDelivered: array_sum(array_map(
                fn (OrderLineData $line): int => $line->fulfilledQuantity,
                $lines,
            )) > 0,
            teamId: $order->team_id,
        );
    }
});
```

Five obligations on any implementation:

1. **Return `null` for anybody else's purchase.** This package cannot check
   ownership for you — it does not know what an order is. What it does is make
   the mistake hard: there is no method on `OrderSource` that resolves a purchase
   without an actor, so you cannot write the unscoped version without deleting an
   argument a reviewer will notice. `IdentityTest` asserts a stranger's handle
   and a handle nobody minted get the same 404, on the component *and* on the
   page.
2. **Take a public handle, never a row id.** `$handle` is whatever the module
   that owns purchases mints for a customer-facing URL. A source that accepts an
   incrementing id turns a storefront page into an enumeration of everybody's
   orders — which is the whole argument that gave orders a number in the first
   place.
3. **Read `returnableQuantity` fresh, every call.** It is `delivered − already
   returned` over counters this package cannot see. It is asked once to draw the
   form and **again inside the write**, and the second answer is the one the
   request is built from; `RequestTest` swaps the bound source between the two
   and asserts the second wins. A cached ceiling is a ceiling the browser had a
   chance to hold still while the truth moved.
4. **Set `teamId`.** The domain registers a policy on the return and reads
   tenancy off the actor. A multi-tenant deployment whose source leaves this null
   raises returns its own staff cannot approve.
5. **Set `anythingDelivered` honestly.** It is what draws the line between the
   two modules in words — see §5.

#### 2.1.1 Consider subtracting what is already asked for

`returnableQuantity` as the order module publishes it is `delivered − returned`,
and the `returned` counter moves when goods are **received**, not when a return
is requested (§4). So a source that reports it verbatim permits a shopper to
raise several open `requested` returns for the same order line, each for the full
quantity.

The domain considers that acceptable — "a duplicated return request is a second
piece of paper a merchant can refuse", and no money moves without an approval, a
receipt and a refund, none of which a shopper can cause. If you would rather it
did not happen at all, **the ceiling is yours and this is where it belongs**:

```php
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

// Units of this order line already spoken for by a return that is still open.
$spokenFor = (int) ReturnLine::query()
    ->where('order_line_id', $line->id)
    ->whereHas('returnRequest', fn ($query) => $query->open())
    ->sum('quantity_requested');

returnableQuantity: max(0, $line->returnableQuantity() - $spokenFor),
```

(`ReturnRequest::scopeOpen()` is `requested`, `approved`, `received` and
`inspected` — the four states in which a return has not finished. Received units
are counted twice by that subtraction until the host's `ReturnGoodsReceived`
listener has moved the order line's `returned` counter, which is the correct
direction to be wrong in: it under-offers rather than over-offers.)

Do not put that rule in this package or in a component. A ceiling has to be one
number from one place, and the moment there are two answers one of them is wrong.

### 2.2 `Support\ShopperContext` — who the shopper is, and which storefront

The default is that a customer *is* a user: `customerId()` is `Auth::id()` when
it is numeric and `null` otherwise, and `storeId()` is
`config('returns-livewire.store_id')` when *that* is numeric and `null`
otherwise.

Rebind it when either is untrue:

```php
use Liberu\Ecommerce\Returns\Livewire\Support\ShopperContext;

$this->app->singleton(ShopperContext::class, fn (): ShopperContext => new class extends ShopperContext
{
    public function customerId(): ?int
    {
        return Auth::user()?->crm_contact_id;   // customers are not users here
    }

    public function storeId(): ?int
    {
        return app(CurrentSite::class)->id();   // resolved per request, from the host
    }
});
```

Two things that will catch you:

- **`Auth::id()` must be numeric.** The domain's `customer_id` is a
  `foreignId` — an unsigned bigint. A deployment with ULID or UUID user keys
  gets `null` from the default `customerId()`, and *every signed-in shopper is
  treated as a guest*: the sign-in prompt on every page, forever, with no error
  anywhere. Rebind, and map to whatever integer id your purchases are actually
  filed against.
- **`storeId()` is never a component property and never a mount parameter.**
  `IdentityTest` asserts by reflection that no component carries a property or a
  mount parameter matching `id`/`Id`/`customer`/`store`/`team`, so there is
  nowhere for a browser to put one. A per-request storefront is resolved here,
  in the class the server controls.

---

## 3. Components, and how they are registered

Six aliases, all under one namespace, asserted by name in `RegistrationTest`
because renaming one is a breaking change:

| Alias | Class | Mount |
| --- | --- | --- |
| `module-ecommerce-returns::return-history` | `Components\ReturnHistory` | — |
| `module-ecommerce-returns::return` | `Components\ReturnDetail` | `$number` |
| `module-ecommerce-returns::start-return` | `Components\StartReturn` | `$orderNumber` |
| `module-ecommerce-returns::returns-page` | `Pages\ReturnHistoryPage` | — |
| `module-ecommerce-returns::return-page` | `Pages\ReturnPage` | `$number` |
| `module-ecommerce-returns::start-return-page` | `Pages\StartReturnPage` | `$orderNumber` |

The namespace drops the `-livewire` suffix and keeps the ownership prefix: it
names the bounded context, not the technology presenting it, so the Filament and
API packages for this domain answer to the same one.

**The host does nothing here.** It is written down because the registration is
not the obvious one, and a deployment that forks or re-implements the provider
will lose an afternoon to it:

```php
foreach ($aliases as $alias => $component) {
    Livewire::component($alias, $component);
}

Livewire::resolveMissingComponent(
    static fn (string $name): ?string => $aliases[$name] ?? null,
);
```

- `Livewire::component()` is the direction that makes a class *report as* its
  alias. It does not make `<livewire:module-ecommerce-returns::return />`
  resolve: Livewire 4's `Finder::resolveClassComponentClassName()` returns
  `null` for a `namespace::name` **before** it ever consults the explicit
  registry, so `component()` alone leaves every namespaced tag unresolvable
  while every `Livewire::test(SomeClass::class)` carries on passing — which is
  exactly the failure mode that hides until a page is rendered.
- `Livewire::resolveMissingComponent()` is the direction that answers. It is
  used instead of `Livewire::addNamespace()` because `addNamespace()` maps one
  Livewire namespace onto exactly **one** class namespace, and this package
  deliberately has two — `Components\` for the reusable components and `Pages\`
  for the routable pages, which are different things.

`RegistrationTest` renders all six by their namespaced names, which is the
assertion that both halves are present.

---

## 4. Routes the host mounts

Routes, layouts, navigation and middleware belong to the application. The pages
declare no layout — a package naming a layout view only installs into an
application that happens to have one by that name.

```php
use Liberu\Ecommerce\Returns\Livewire\Pages\ReturnHistoryPage;
use Liberu\Ecommerce\Returns\Livewire\Pages\ReturnPage;
use Liberu\Ecommerce\Returns\Livewire\Pages\StartReturnPage;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/returns', ReturnHistoryPage::class)->name('storefront.returns');
    Route::get('/returns/{number}', ReturnPage::class)->name('storefront.return');
    Route::get('/orders/{orderNumber}/return', StartReturnPage::class)->name('storefront.start-return');
});
```

**Put `auth` on all three.** The pages work without it — a guest is shown "sign
in to see your returns", no query runs and no source is consulted — but a shopper
following a bookmark after their session expired would rather be sent to the
sign-in form than to a page telling them to find one. The guest handling is a
safety property, not a feature: see §6.

`{number}` receives the RMA number and `{orderNumber}` the purchase's public
handle. **Neither ever receives a row id**, and there is no route here that could
take one.

### The three route *names* this package links to

Names, in configuration, because the routes are yours. An unregistered name is
treated as no link at all — a `#` href is a control that announces itself as a
link and then does nothing (`Concerns\ShowsOwnReturns::link()` calls
`Route::has()` and returns `null`).

| Key | Env | Receives | Where it is rendered |
| --- | --- | --- | --- |
| `returns-livewire.routes.return` | `RETURNS_LIVEWIRE_RETURN_ROUTE` | `number` | every row of the history list |
| `returns-livewire.routes.order` | `RETURNS_LIVEWIRE_ORDER_ROUTE` | `number` | "go to this order", when nothing has been delivered yet |
| `returns-livewire.routes.help` | `RETURNS_LIVEWIRE_HELP_ROUTE` | — | beside every refusal, and on a return closed to further goods |

Set all three. Leaving `return` unset renders the history as a list of numbers
nobody can click; leaving `help` unset leaves a refusal with no next step, next
to a shopper holding a parcel.

---

## 5. Publishing views, translations and configuration

Three separate tags, because a deployment that wants its own wording rarely wants
its own markup as well (`RegistrationTest` asserts all three exist):

```bash
php artisan vendor:publish --tag=module-ecommerce-returns-views
php artisan vendor:publish --tag=module-ecommerce-returns-translations
php artisan vendor:publish --tag=module-ecommerce-returns-config
```

- views → `resources/views/vendor/module-ecommerce-returns/`
- translations → `lang/vendor/module-ecommerce-returns/`
- config → `config/returns-livewire.php`

The six shipped views are structure and labels only: no classes, no tokens, no
layout. A theme is expected to publish and restyle them. **Five things a
published copy must not drop**, each of which is behaviour rather than
decoration and each of which `tests/Feature/AccessibilityTest.php` will catch
only in *this* repository — once you publish, they are yours:

1. **The live regions.** `<p role="status" aria-live="polite">` carrying both the
   `wire:loading` text and `$announcement`, and `<p role="alert">` for
   `$refusal`. A form disappearing is invisible to a screen reader, and so is a
   spinner. The split is deliberate: a polite region can go unread until the
   shopper next moves focus, and somebody who has just pressed "request this
   return" and been refused needs to know before they walk away believing a
   parcel is expected.
2. **A real `<label for>` on every visible control**, and the note's
   `aria-describedby` pointing at its character-limit hint. A placeholder is not
   a label.
3. **The accessible name on each history link** — it carries the RMA number,
   because a page of links all called "View return" is a page nobody can use
   without looking at it.
4. **`wire:key` on every line**, so focus survives a re-render.
5. **Every amount through `$this->money()`.** It is string arithmetic over
   integer minor units. `number_format()`, `Number::currency()` and anything else
   taking a float re-introduce, one layer later and in front of the shopper,
   exactly the imprecision the domain stores integers to avoid —
   `(int) (19.99 * 100)` is `1998`.

And one thing a published copy must not *add*: **the shopper's note.** See §7.

### Configuration reference

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `store_id` | `RETURNS_LIVEWIRE_STORE_ID` | `null` | Narrows every read to one storefront. Null means the deployment has one. A non-numeric value is treated as null. Setting it **hides returns whose own `store_id` is null** — deliberately: a return belonging to no storefront is not this storefront's. |
| `per_page` | — | `10` | How many returns the history fetches. "Show more" grows the count rather than turning a page, so an insert at the top cannot make page two start a row late. Anything non-positive falls back to 10. |
| `request.enabled` | `RETURNS_LIVEWIRE_REQUEST` | `true` | Off removes the request form **and refuses the write**, not just the render. |
| `request.note.enabled` | `RETURNS_LIVEWIRE_NOTE` | `true` | Off removes the textarea and **drops anything submitted against it**. |
| `request.note.max_length` | — | `500` | Refused, never truncated. Anything non-positive falls back to 500. |
| `cancellation.enabled` | `RETURNS_LIVEWIRE_CANCELLATION` | `true` | Off removes the cancel control **and refuses the call**. |
| `routes.return` / `routes.order` / `routes.help` | see §4 | `null` | Route names. Unregistered means no link. |

Both switches are enforced on the write as well as on the render, and
`RequestTest` and `ReturnTest` each call the disabled method anyway and assert
the refusal. A control that is not drawn is not a control that cannot be called.

---

## 6. The listeners the host must wire — and the one this package must not

**This package registers no event listener at all.** `BoundaryTest` asserts that
`src/` contains no `Event::listen` or `Event::subscribe`, written as "this
package subscribes to nothing" rather than by naming a call it must not make.

That is not tidiness. The domain module publishes five events, and two of them
have to become somebody else's work:

| Event | Who subscribes | Why not here |
| --- | --- | --- |
| `ReturnGoodsReceived` | **the host** | Raising the `returned` counter on an order line is the job of whoever owns that line. A presentation package registering it would be a second place that decision lives, and the first symptom would be a counter moved twice. |
| `ReturnInspected` | **the host** | Putting a unit back on a shelf is a stock movement in the module that owns stock, and whether it happens at the desk or after a week in quarantine is a warehouse policy. |
| `ReturnRequested` | nobody, by default | Past tense about an *intention*. Nothing is authorised and nothing has moved. A listener that allocates, reserves or credits on this is acting on a request the merchant has not agreed to. |
| `ReturnTransitioned` | host's choice | Notifications, audit. |
| `RefundRecorded` | host's choice | **Past tense — the money already moved.** A listener that calls a payment provider on this refunds every shopper twice. |

The listeners themselves are written out in the domain package's
`docs/adoption.md` §2. The one that matters to *this* package is
`ReturnGoodsReceived`, because it is what eventually moves the `returned`
counter your `OrderSource` reads `returnableQuantity` from. **If it is missing,
this package's ceiling never falls**, and a shopper can keep asking to send back
things that have already come back. That is a `docs/runbook.md` §4 page, not a
code change here.

---

## 7. What a shopper can and cannot cause

This is the section to read before deciding whether this package goes on a public
storefront. Everything in it is asserted by a test in `tests/`.

### 7.1 The two writes a browser can reach, in full

| # | Write | Guarded by |
| --- | --- | --- |
| 1 | Create a return request: **one** order line, a quantity, a reason slug, an optional note. | The purchase is resolved from the signed-in account *before* the line id is looked at; the line is matched **by identity** against the lines the source just returned; the quantity's ceiling is re-read on the server inside the write; the reason is `ReturnReason::tryFrom()`. |
| 2 | Cancel a request they made — **no arguments at all**. | The domain's own transition table, consulted by `TransitionReturn` at write time. The reason slug written to the history row is a server-side constant, `cancelled-by-shopper`. |

That is the complete list. There is no third.

### 7.2 What a shopper cannot cause, and what makes it impossible

| Cannot | What stops it |
| --- | --- |
| **Set a refund amount, or cause a refund at all.** | There is no amount property, no currency property and no refund control on any component. `ReturnTest` asserts the *absence* by reflection over `ReturnDetail`'s properties — not that an amount is validated. What is rendered is `ReturnData::$refundedMinor`, a sum over rows the domain computes and stores nowhere; a row exists only because money already moved. |
| **Approve their own return.** | No control, no method, and `approve` is a staff ability behind the domain's policy. A shopper authorising their own return is the workflow with no merchant in it. |
| **Say that goods arrived.** | Nobody can assert a parcel physically arrived from a browser. A shopper who could would be able to trigger a refund for a box nobody has. |
| **Record an inspection outcome, or resolve a return.** | Same: staff abilities, no surface here. |
| **Set a status directly.** | `status` is not fillable on the domain model and `TransitionReturn` is the single door. The only status a shopper can reach is `cancelled`, and only from `requested` or `approved`. |
| **Set their own eligibility ceiling.** | `returnableQuantity` is never a property, never a form field and never accepted from the browser. It is read from `OrderSource` at render and **again inside the write**, and the second answer is used. Adding a hidden field for it in a published view would hand the browser the one value the whole design keeps away from it. |
| **Get more back than is returnable by asking louder.** | `ReturnQuantityExceeded` is surfaced **in words with both numbers in it** — "you asked to send back 9, but only 3 of this item can still be returned" — and nothing is written. Quietly capping nine to three would tell the shopper one thing and the receiving desk another, and the disagreement would first appear as a wrong refund. |
| **Read somebody else's return.** | `Concerns\ShowsOwnReturns::mine()` is the only place a query starts and it starts with `forCustomer($customerId)`. `IdentityTest` asserts this package reaches `ReturnQuery` exactly once, through that read. |
| **Raise a return against somebody else's purchase.** | `OrderSource::forShopper()` takes the customer id as its **first** argument and there is no method that omits it. A stranger's line id is simply not in the list it is checked against. |
| **Learn which RMA numbers or order handles exist.** | A handle that names nothing, a handle that names somebody else's record, an empty string, and (for the request surface) an unbound source are **all the same 404 with no distinguishing message**. |
| **Use a signed-out response as an oracle.** | A guest is shown a sign-in prompt, and **no query runs and no source is consulted**. If a guest got a 404 for a real handle and a prompt for a made-up one, the difference would be a lookup service for other people's records. |
| **Put words into a screen reader.** | Both message properties are `#[Locked]` — see §7.3. |
| **Enumerate records from a URL.** | Nothing is bound to the URL: `IdentityTest` asserts no `#[Url]` on any property. Returns have no filter, no sort and no search here, so a query string has nothing to carry — and a `#[Url]` on either handle would put it in a referrer header and a shared screenshot on top of the path it already travels in. |

### 7.3 Every public property is `#[Locked]`, including both message strings

Five public properties across six components, and every one carries `#[Locked]`.
`IdentityTest` asserts the **exceptions list is empty** rather than asserting a
particular set, so a property added next year without the attribute fails there
rather than never.

Two of the five are the ones this section exists for:

- `$announcement` — announced verbatim into a polite live region.
- `$refusal` — announced into an assertive one, which **interrupts**.

Every Livewire property travels to the browser and back on every request, which
makes an unlocked one a string the client writes. These two are rendered straight
into a message the shopper reads and a screen reader speaks, on a page about goods
somebody is posting back and money they are owed. "Your refund failed, call this
number" is a sentence nobody should be able to hand a component — and an
assertive region is the loudest surface in the product to hand it to. Locked,
both. They are also reset to `''` on every hydration, because a live region
announces *changes*, and repeating "we could not accept that" at a moment when
the return has actually been raised is worse than saying nothing.

The rule survives a surface that takes real input because **everything a shopper
chooses or types arrives as a method argument**:

```php
public function request(string $line = '', string $quantity = '', string $reason = '', string $note = ''): void
```

Four strings, because that is what a browser sends. None is ever assigned to a
property, so none can be re-sent on the next request as though the server had
chosen it. Cancellation goes further and takes no arguments at all.

### 7.4 The note goes in and cannot come out

`note` is the one free-text field this package accepts, and it exists because a
shopper returning a faulty item genuinely does have something to say that seven
slugs cannot hold. It is contained **mechanically**, not by promise:

| Where | Does it travel? |
| --- | --- |
| the domain's `ReturnLine` model, behind the policy | **Yes** — a staff surface reading a shopper's sentence is the job |
| `Data\ReturnLineData` (the read model) | **No** — the field does not exist on it |
| any domain event | **No** |
| any log line | **No** |
| this package's announcement, refusal, markup, links, query string | **No** — and there is no path, because every read here goes through the read model |

`RequestTest` asserts the note is on the model **first**, and only then asserts
the absences, so none of them can pass by there being no note to find.

**Do not undo this in a published view.** Reaching for the `ReturnLine` model to
render the shopper's own words back on the storefront is the one edit that breaks
the containment, and it is exactly the edit that looks helpful.

Too long is **refused, not truncated**. The column is `text` and imposes no
limit, so the limit is this package's and it is configurable. The half a
truncation removes is the half describing the fault, and the shopper is never
told it happened.

### 7.5 The reason is a closed set, and that is a logging decision

`ReturnReason` is seven slugs, and the control is a `<select>` enumerated from
the enum's own cases rather than from configuration — so a deployment cannot add
an eighth, and a domain release that adds one cannot leave this page behind.

The value is validated with `ReturnReason::tryFrom()`, which is identity.
`FAULTY`, `faulty ` with a trailing space, and a sentence somebody typed are all
**dropped** — not truncated into something that happens to match, not stored —
and the shopper is told to choose one. That matters because the slug is copied
into a domain event and a log line, and a free-text field next to an event logger
is where a customer's email address, or the name of the person a gift was for,
ends up in somebody's log retention.

### 7.6 Where the two modules meet, in words

Whoever owns purchases drew the line at delivery: `completed → cancelled` is not
a legal transition over there, and cancelling anything already sent is refused.
Calling off what has not happened is a cancellation; getting back what has is a
return.

So `ReturnableOrder::$anythingDelivered` — which **you** answer — decides which
sentence the shopper is shown:

- nothing sent yet → "there is nothing to send back; if you no longer want it,
  you can still cancel the order", plus the `routes.order` link;
- delivered, nothing returnable left → "there is nothing left on this order to
  send back";
- delivered, something returnable → the form.

An empty list is not an answer. A shopper who is shown one guesses.

---

## 8. A ten-minute smoke test after wiring

1. Sign in as a shopper with at least one existing return; open the history
   route. You should see it, with a working link.
2. Sign out and open the same route. You should see "sign in to see your
   returns" — **not** a 404, and not an empty list.
3. Signed in, open `/returns/{someone-else's-RMA}`. 404. Then open
   `/returns/RMA-000000000000`. The same 404, with the same body.
4. Open the start-return route for a delivered order. If it 404s, your
   `OrderSource` is not bound or is not answering for this customer —
   `docs/runbook.md` §1.
5. Request a return for one unit. You should get "Return RMA-… has been
   requested" in the live region, and a new row in
   `ecommerce_returns_requests` whose `customer_id`, `store_id`, `team_id`,
   `order_id`, `currency` and `currency_exponent` are all values the browser
   never sent.
6. Request a quantity larger than the ceiling. You should get a refusal naming
   **both** numbers, in a `role="alert"`, and **no row written**.
7. Cancel the request. `ecommerce_returns_status_changes` should carry
   `to_status=cancelled`, `actor_id` = the shopper, `reason=cancelled-by-shopper`.

If step 7 shows anything other than that reason slug against a shopper's id, the
cancellation did not come from this package.
