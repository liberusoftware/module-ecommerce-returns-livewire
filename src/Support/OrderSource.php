<?php

namespace Liberu\Ecommerce\Returns\Livewire\Support;

use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableOrder;

/**
 * Where a returnable purchase comes from — **the host's answer, never this
 * package's lookup.**
 *
 * This package presents one domain module and imports no sibling. It cannot see
 * a purchase, cannot join to one and must not try: eligibility is
 * `delivered − already returned`, over counters that live in the module that owns
 * the lines, and the domain package published `returnableQuantity` as an *input*
 * precisely so that nobody downstream derives it and gets it wrong.
 *
 * So the seam is a class the host binds, and the default answers null. Out of the
 * box this package shows a shopper their existing returns and tells them it
 * cannot see their purchases; the moment a deployment binds this, the request
 * form works. `docs/adoption.md` carries the implementation to copy.
 *
 *     $this->app->singleton(OrderSource::class, fn () => new HostOrderSource());
 *
 * ## The signature is the contract, and the actor is the first argument
 *
 * `forShopper()` takes a **customer id and a public handle**, in that order, and
 * that is not cosmetic. There is no method here that resolves a purchase without
 * an actor, so there is no way for a caller in this package to accidentally write
 * one — every call site already holds a customer id from
 * {@see ShopperContext::customerId()}, and a guest never reaches this class
 * because a guest has no id to pass.
 *
 * An implementation **must** return null for a purchase belonging to anybody
 * other than the customer it was handed. This package cannot check that for you:
 * it does not know what an order is. What it does do is make the mistake hard —
 * you cannot write the unscoped version without deleting an argument somebody
 * will notice in review.
 *
 * The handle is a **public reference**, whatever the module that owns purchases
 * mints for exactly this. It is not a row id, and a source that accepts one turns
 * a storefront page into an enumeration of everybody's orders.
 *
 * ## Read it again, do not cache it
 *
 * Every quantity on the value this returns is read fresh. A component asks once
 * to draw the form and again to write the request, and uses the second answer:
 * another return may have landed in between, and a ceiling carried across a
 * round trip is a ceiling the browser had a chance to hold still while the truth
 * moved.
 */
class OrderSource
{
    /**
     * One of this shopper's own purchases, or null.
     *
     * Null covers all three of "no such handle", "not this shopper's" and "this
     * deployment has not bound a source yet". A caller cannot tell them apart,
     * and that is the point — the difference between the first two is
     * information about somebody else's order.
     */
    public function forShopper(int $customerId, string $handle): ?ReturnableOrder
    {
        return null;
    }
}
