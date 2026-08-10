<?php

namespace Liberu\Ecommerce\Returns\Livewire\Data;

use Liberu\Ecommerce\Returns\Livewire\Support\OrderSource;

/**
 * A purchase the shopper has already been sent, described well enough to raise a
 * return against it and no further.
 *
 * This is the shape the **host** fills in — see
 * {@see OrderSource}. It is entirely
 * identifiers and values already resolved: an id, a public handle, a currency
 * code, an exponent, a tenancy id, and per line a quantity. Nothing on it can
 * only be obtained by installing another package, which is what lets this
 * package's whole suite run with nothing that owns orders present at all.
 *
 * ## `anythingDelivered` is where two modules meet, and it is a sentence not a
 * refusal
 *
 * Whoever owns orders drew the line at delivery: calling off something that has
 * not happened is a cancellation, and there is a state machine over there that
 * refuses to cancel anything already sent. Everything after delivery is a return.
 *
 * So a shopper looking at a purchase that has not moved yet should not be offered
 * a return at all — they should be pointed at cancelling it. And a shopper
 * looking at a delivered one should be pointed here rather than at a cancel
 * button that will be refused. This flag is how this package says which, in
 * words, instead of rendering an empty list and letting the shopper guess.
 *
 * ## `teamId` is not decoration
 *
 * The domain registers a policy on the return, and it reads tenancy off the
 * actor: a return whose `team_id` is null is nobody's to act on, deliberately, so
 * that an orphan can be found without being quietly claimed. A multi-tenant
 * deployment whose source leaves this null will raise returns that its own staff
 * cannot approve. Say which merchant the purchase belongs to.
 */
final readonly class ReturnableOrder
{
    /**
     * @param  list<ReturnableLine>  $lines
     */
    public function __construct(
        public int $orderId,
        public string $number,
        public string $currency,
        public array $lines,
        public int $currencyExponent = 2,
        public bool $anythingDelivered = true,
        public ?int $teamId = null,
    ) {}

    /**
     * The lines with something left on them, in the order the host listed them.
     *
     * @return list<ReturnableLine>
     */
    public function returnableLines(): array
    {
        return array_values(array_filter($this->lines, fn (ReturnableLine $line): bool => $line->isReturnable()));
    }

    /**
     * One line by its id, or null — **by identity, over the lines the host just
     * returned for this shopper.**
     *
     * This is the whole authorization of the write. The order was resolved from
     * the signed-in account before this list existed, so an id belonging to
     * somebody else is simply not in it, and gets the same answer as an id that
     * names nothing at all.
     */
    public function line(int $orderLineId): ?ReturnableLine
    {
        foreach ($this->lines as $line) {
            if ($line->orderLineId === $orderLineId) {
                return $line;
            }
        }

        return null;
    }
}
