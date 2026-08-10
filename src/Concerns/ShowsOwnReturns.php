<?php

namespace Liberu\Ecommerce\Returns\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\Returns\Data\Money;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Data\ReturnLineData;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Liberu\Ecommerce\Returns\Livewire\Support\OrderSource;
use Liberu\Ecommerce\Returns\Livewire\Support\ShopperContext;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\Ecommerce\Returns\Queries\ReturnQuery;
use Livewire\Attributes\Locked;

/**
 * What every component here does before it does anything else: narrow to the
 * signed-in shopper's own returns, and then look inside that.
 *
 * ## The actor is the first clause of every query
 *
 * {@see mine()} is the only place a query starts, and it starts with
 * `forCustomer($customerId)`. Everything else — a number, a store — is applied
 * *to that builder*. There is no path through this package that reads a return
 * without a customer id already bound, and there is no path that reads one at all
 * when nobody is signed in: {@see mine()} answers null and the caller stops.
 *
 * The domain's query object publishes other reads too, keyed on a return's own
 * handles or on an order line. Those are the right reads for a staff surface with
 * a policy behind it, and the wrong ones here: a record fetched by a handle the
 * browser supplied leaves the ownership question to be answered afterwards, and a
 * question answered afterwards is one somebody eventually forgets to ask.
 * `tests/Feature/IdentityTest.php` asserts that this package reaches that object
 * exactly once, through the read that takes the actor.
 *
 * ## The number is how a return travels, and it travels locked
 *
 * A shopper follows a link to one return, so something has to identify it. Two
 * candidates, and the domain has already chosen between them: `number` is `RMA-`
 * and twelve hex characters minted from 48 bits of CSPRNG output, and `id` is an
 * incrementing integer. The domain refuses to publish a lookup by id at all,
 * because an id in a customer-facing URL is an enumeration of everybody's
 * returns. So the number travels, `#[Locked]` so the browser cannot swap the one
 * it was handed — and the lock is the *second* control, not the first. The first
 * is that the query is already narrowed to this shopper, which is what makes a
 * swapped number find nothing even if the lock were removed.
 *
 * ## Not found and not yours are the same answer
 *
 * {@see returnNumbered()} returns null for both, and for the empty string, and
 * every caller answers a 404. Telling them apart — "that return exists but is not
 * yours" — is how a caller learns which numbers exist, which is the enumeration
 * the number was minted to prevent.
 */
trait ShowsOwnReturns
{
    /**
     * What just happened, in words, for the component's live region.
     *
     * `#[Locked]` because it is announced verbatim into a screen reader. A string
     * the browser can set is a string an attacker can put in a shopper's ear, and
     * this is a page about goods somebody is posting back and money they are
     * owed: "your refund failed, call this number" is a sentence nobody should be
     * able to hand this property.
     *
     * **It never carries a shopper's note.** The one free-text field this package
     * accepts goes into the domain and stops there; nothing announced, linked or
     * logged here is built from it. `tests/Feature/RequestTest.php` asserts that
     * with a positive assertion alongside, so the absence cannot pass by being
     * absent from an empty page.
     */
    #[Locked]
    public string $announcement = '';

    /**
     * A refusal, for the component's assertive region.
     *
     * Separate from {@see $announcement} because the two are read out differently
     * and for a reason: a polite region can go unread until the shopper next
     * moves focus, and a shopper who has just pressed "send this back" and been
     * refused needs to know before they walk away believing a parcel is expected.
     */
    #[Locked]
    public string $refusal = '';

    /**
     * Both regions last exactly one render.
     *
     * A live region announces *changes*. Carrying the previous sentence into the
     * next request either says nothing new or says it again at a moment when it
     * is no longer true — and repeating "we could not accept that" at a moment
     * when the return has been raised is worse than saying nothing.
     */
    public function hydrateShowsOwnReturns(): void
    {
        $this->announcement = '';
        $this->refusal = '';
    }

    public function signedIn(): bool
    {
        return $this->shopper()->customerId() !== null;
    }

    /**
     * An amount of money as a string, from integer minor units.
     *
     * Public because the views call it. There is no float anywhere on this path:
     * `Money::decimal()` is string arithmetic over the minor units — split, pad,
     * concatenate — so `1999` is `19.99` and never `19.990000000000002`.
     *
     * Deliberately *not* `Number::currency()`, which takes a float. Handing
     * `19.99` to a formatter re-introduces, one layer later and in front of the
     * shopper, exactly the imprecision the domain stores integers to avoid.
     *
     * The currency and exponent are passed in rather than read from a component
     * property, because a return is agreed in the currency of the purchase it
     * came from and a history page renders several returns at once.
     */
    public function money(int $minor, string $currency, int $exponent): string
    {
        return $this->say('money', [
            'currency' => $currency,
            'amount' => new Money($minor, $currency, $exponent)->decimal(),
        ]);
    }

    /**
     * The state of a return, in the deployment's words.
     *
     * Keyed by the enum's own value, so the only states this package can render
     * are the ones the domain publishes. `tests/Feature/ReturnTest.php` asserts
     * that the translated list and the enum's cases are the **same set**, not
     * that one contains the other: a missing key would render a raw translation
     * key at the worst moment, and an extra one is this package inventing a state
     * the domain refused.
     *
     * Progress within a state is not a state, and there is no key here for one. A
     * return of five can be two received, one rejected on inspection and two
     * still in a van at the same moment; the quantities on each line say that,
     * and no word does.
     */
    public function status(ReturnData $return): string
    {
        return $this->say('status.'.$return->status->value);
    }

    /**
     * What the shopper should do next, given where the return has got to.
     *
     * A state word on its own is a dead end. This is the sentence that turns each
     * of the eight into an instruction, and two of them are the reason it exists
     * at all: an **expired** return is one the merchant closed because the goods
     * never came, and an **inspected** one is closed to further goods because a
     * disposition has been recorded and the arithmetic settled. In both cases a
     * parcel sent now lands against a return that will not take it, so the answer
     * is "raise a new request", said out loud, rather than a page with nothing on
     * it.
     */
    public function nextStep(ReturnData $return): string
    {
        return $this->say('next.'.$return->status->value);
    }

    /**
     * Where a link in these views goes, or null.
     *
     * Routes belong to the application composing this package. An unregistered
     * name is treated as none, because a `#` href is a control that announces
     * itself as a link and then does nothing.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function link(string $kind, array $parameters = []): ?string
    {
        $name = config('returns-livewire.routes.'.$kind);

        if (! is_string($name) || $name === '' || ! Route::has($name)) {
            return null;
        }

        return route($name, $parameters);
    }

    /**
     * The counters on one line, said out loud, in the order they happen.
     *
     * Three of the domain's five, and the omission is deliberate.
     * `restockable` and `rejected` are an **inspection disposition** — a
     * merchant's judgement about the condition of goods they are holding — and
     * putting "1 rejected" on a storefront states a verdict without the
     * conversation that goes with it. The consequence a shopper is entitled to
     * see is the money, and that is rendered from the domain's own sum. See
     * `docs/domain.md`.
     */
    public function lineProgress(ReturnLineData $line): string
    {
        $parts = [$this->say('line.requested', ['count' => $line->requestedQuantity])];

        foreach (['approved' => $line->approvedQuantity, 'received' => $line->receivedQuantity, 'outstanding' => $line->outstandingQuantity()] as $what => $count) {
            if ($count > 0) {
                $parts[] = $this->say('line.'.$what, ['count' => $count]);
            }
        }

        return implode(' ', $parts);
    }

    /** A reason slug in the deployment's words. Slugs are the domain's closed set. */
    public function reasonLabel(string $reason): string
    {
        return $this->say('reason.'.$reason);
    }

    protected function shopper(): ShopperContext
    {
        return app(ShopperContext::class);
    }

    protected function orders(): OrderSource
    {
        return app(OrderSource::class);
    }

    protected function announce(string $message): void
    {
        $this->announcement = $message;
    }

    protected function refuse(string $message): void
    {
        $this->refusal = $message;
    }

    /**
     * Shorthand for this package's translation namespace.
     *
     * @param  array<string, mixed>  $replace
     */
    protected function say(string $key, array $replace = []): string
    {
        return __(ReturnsLivewireServiceProvider::NAMESPACE.'::returns.'.$key, $replace);
    }

    /**
     * The signed-in shopper's own returns, narrowed to this storefront, newest
     * first — or null when nobody is signed in.
     *
     * The customer clause comes from the domain's own `forCustomer()`, which is
     * the read it published for exactly this. The store clause is applied with
     * `when()` and a bound value rather than `where('store_id', $storeId)`:
     * passing null there compiles to `is null`, which would answer with the
     * orphan returns belonging to no storefront instead of with none.
     *
     * @return Builder<ReturnRequest>|null
     */
    protected function mine(): ?Builder
    {
        $customerId = $this->shopper()->customerId();

        if ($customerId === null) {
            return null;
        }

        $storeId = $this->shopper()->storeId();

        return app(ReturnQuery::class)
            ->forCustomer($customerId)
            ->with(['lines', 'refunds'])
            ->when($storeId !== null, fn (Builder $query): Builder => $query->where('store_id', $storeId));
    }

    /**
     * One of the shopper's own returns, by the number a link named.
     *
     * The scoping is the authorization. `mine()` is already narrowed to this
     * customer and this storefront, so a number belonging to anybody else finds
     * nothing — and finding nothing is the same answer a number that was never
     * minted gets, and the same answer an empty string gets, because the
     * difference between them is information about somebody else's return.
     */
    protected function returnNumbered(string $number): ?ReturnRequest
    {
        if ($number === '') {
            return null;
        }

        return $this->mine()?->where('number', $number)->first();
    }
}
