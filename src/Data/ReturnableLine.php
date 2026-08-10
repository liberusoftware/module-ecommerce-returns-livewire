<?php

namespace Liberu\Ecommerce\Returns\Livewire\Data;

/**
 * One line of a purchase that could be sent back, as the host describes it.
 *
 * **`returnableQuantity` is the whole boundary in one field.** Eligibility is
 * arithmetic over what was delivered and what has already come back, and both of
 * those counters live in the module that owns the lines. The domain package this
 * one presents refuses to compute it, refuses to guess it and refuses to look it
 * up: it is an input, exactly the way a tax rate is an input.
 *
 * So this package refuses too. It never derives this number, never caches it
 * across a request and never accepts it from a browser — {@see
 * \Liberu\Ecommerce\Returns\Livewire\Support\OrderSource} is asked afresh at
 * render *and* again at submit, and the value used to build a request is the one
 * the second read returned. Another return may have landed in between.
 *
 * What it becomes, once the request is written, is **evidence**: the domain
 * stores it as the number the shopper was told on the day they asked, which is
 * what an argument three months later is actually about.
 *
 * `name` and `sku` are **copied labels**. A shopper has to be able to tell which
 * of two jumpers they are sending back, and this package cannot join anything to
 * find out.
 */
final readonly class ReturnableLine
{
    public function __construct(
        public int $orderLineId,
        public string $name,
        public int $returnableQuantity,
        public ?string $sku = null,
        public ?int $productId = null,
        public ?int $variantId = null,
    ) {}

    /** Whether there is anything left on this line worth offering. */
    public function isReturnable(): bool
    {
        return $this->returnableQuantity > 0;
    }
}
