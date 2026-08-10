<?php

namespace Liberu\Ecommerce\Returns\Livewire\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Whose returns these are, and which storefront they belong to — decided
 * entirely by the server.
 *
 * Every read and every write in this package starts here. No component holds a
 * customer id, a store id, a team id, a return id or an order id; there is no
 * mount parameter for any of them, and therefore no request a shopper can craft
 * that points a component at somebody else's records.
 * `tests/Feature/IdentityTest.php` asserts that by reflection over every
 * registered component.
 *
 * ## A guest has no returns, and that is the answer rather than a gap
 *
 * A return carries a shopper's account of what went wrong, in their own words,
 * attached to an order somebody paid for. There is no column this package could
 * match a guest on that would be safe, and the domain mints no guest token, so
 * this package would have to invent an identity the record does not carry.
 *
 * So {@see customerId()} answers null for a guest, every component says "sign in
 * to see your returns", and **no query runs at all**. A `where('customer_id',
 * null)` compiles to `is null`, which lists precisely the orphan rows that belong
 * to no account — the leak written as a tautology. Nothing here builds a query
 * without a customer id, so nothing here can build that one.
 *
 * The same answer is given for a return number that is real and a return number
 * that is not, so a signed-out visitor cannot use this package as an oracle for
 * which numbers exist.
 *
 * ## Rebinding it
 *
 * Configuration and the authenticated user are the default because that is what
 * most deployments have. A deployment whose customers are not its users — a CRM
 * contact keyed separately from the login, say — replaces the binding:
 *
 *     $this->app->singleton(ShopperContext::class, fn () => new HostShopperContext());
 *
 * which is why this class is not final. See `docs/adoption.md`.
 */
class ShopperContext
{
    /**
     * The signed-in shopper's customer id, or null for everybody else.
     *
     * The default is that a customer *is* a user: `customer_id` is a plain
     * indexed column the domain never resolves to a class, and in a Liberu
     * storefront the account a shopper signs into is the account a purchase is
     * filed against. A deployment where those are two different tables overrides
     * this method, and every read in the package follows — because nothing else
     * here asks who the shopper is.
     */
    public function customerId(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The storefront these returns belong to. Null means one storefront.
     *
     * Configuration, never a property and never a mount parameter. A shopper who
     * could choose their own `store_id` would be choosing which merchant's
     * records to read — and although the customer scope already stops that, a
     * value the browser sets is a value the next person to touch this code will
     * assume the server chose.
     */
    public function storeId(): ?int
    {
        $value = config('returns-livewire.store_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
