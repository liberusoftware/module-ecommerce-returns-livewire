<?php

namespace Liberu\Ecommerce\Returns\Livewire\Components;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Returns\Actions\RequestReturn;
use Liberu\Ecommerce\Returns\Data\ReturnLineInput;
use Liberu\Ecommerce\Returns\Data\ReturnRequestInput;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Livewire\Concerns\ShowsOwnReturns;
use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableLine;
use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableOrder;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A shopper asks to send something back. **The one surface in this package, and
 * the only shopper-facing surface in this wave, that causes a domain write.**
 *
 * Everything below follows from that. A read that gets its scoping wrong shows
 * somebody the wrong page; a write that gets it wrong files a return against a
 * stranger's purchase, and the first anybody hears of it is a refund.
 *
 * ## Four values reach the domain, and only two of them come from the browser
 *
 * | Value | Where it comes from |
 * | --- | --- |
 * | order id, currency, exponent, team | the resolved purchase — **server** |
 * | `returnableQuantity` | the resolved purchase, re-read at submit — **server** |
 * | `name`, `sku`, product and variant ids | the resolved purchase — **server** |
 * | order line id | the browser, **checked by identity** against the resolved purchase |
 * | quantity | the browser, refused by the domain's own arithmetic |
 * | reason | the browser, **checked by identity** against the domain's enum |
 * | note | the browser, length-refused, and it cannot come back out |
 *
 * The resolved purchase comes from `Support\OrderSource`, which is handed the
 * signed-in customer id **first** and returns null for anybody else's. So the
 * ownership question is answered before an order line id from the browser is
 * looked at, and a stranger's line id is simply not in the list it is checked
 * against. `tests/Feature/RequestTest.php` proves that by constructing this
 * component directly and setting a stranger's handle — something a browser cannot
 * do, because the handle is `#[Locked]`.
 *
 * ## Eligibility is an input, and it is read twice
 *
 * `returnableQuantity` is `delivered − already returned` over counters this
 * package cannot see, so it arrives rather than being computed. It is read once
 * to draw the form and **again inside {@see request()}**, and the second answer is
 * the one that is used: another return may have landed between the page being
 * drawn and the button being pressed. It is never a property, never a form field
 * and never accepted from the browser — a ceiling the client can hold still while
 * the truth moves is not a ceiling.
 *
 * What the domain then does with it is store it, as evidence of what the shopper
 * was told on the day they asked.
 *
 * ## Refused, never capped
 *
 * Asking for more than is returnable is a `ReturnQuantityExceeded` from the
 * domain, and this component surfaces that refusal **in words with both numbers
 * in it**. Quietly reducing five to three and writing the request would tell a
 * shopper they are sending five things back and a warehouse to expect three, and
 * the disagreement would first appear as a wrong refund.
 *
 * ## One line at a time
 *
 * The domain takes many lines per request and this surface offers one, which is a
 * decision rather than a limitation. A shopper sending two different things back
 * for two different reasons is two requests, each with its own authorisation, and
 * a merchant can approve one and refuse the other without either decision being
 * entangled with the other. It also means every value a shopper types is a scalar
 * method argument, which is what keeps the no-writable-property rule intact
 * instead of introducing an array the browser fills in.
 */
class StartReturn extends Component
{
    use ShowsOwnReturns;

    /**
     * The purchase's **public handle**, whatever the module that owns purchases
     * mints for a customer-facing URL.
     *
     * Locked, and it is a handle rather than a row id for the same reason a
     * return travels by its RMA number: an incrementing id on a storefront page
     * is an enumeration of everybody's orders. The lock is the second control —
     * `Support\OrderSource` is handed the signed-in customer id first, so a
     * swapped handle resolves to nothing even without it.
     */
    #[Locked]
    public string $orderNumber = '';

    private ?ReturnableOrder $resolved = null;

    public function mount(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    /** Whether this deployment offers shopper-raised returns at all. */
    public function offered(): bool
    {
        return (bool) config('returns-livewire.request.enabled', true);
    }

    /**
     * The purchase, resolved from the signed-in account, or a 404.
     *
     * A handle that names nothing, a handle that names somebody else's purchase
     * and a deployment that has bound no source are all the same answer, with no
     * distinguishing message. The first two must be identical or this page is an
     * oracle for which order numbers exist; the third is thrown in because a
     * shopper cannot act on it either way, and `docs/runbook.md` is where an
     * operator finds out which of the three it was.
     *
     * A guest never reaches here: they have no customer id to scope on and are
     * shown the invitation to sign in instead, so the answer a signed-out visitor
     * gets cannot depend on whether the handle is real.
     */
    public function purchase(): ReturnableOrder
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $customerId = $this->shopper()->customerId();

        $purchase = $customerId === null || $this->orderNumber === ''
            ? null
            : $this->orders()->forShopper($customerId, $this->orderNumber);

        if ($purchase === null) {
            abort(404);
        }

        return $this->resolved = $purchase;
    }

    /**
     * The lines with something left on them.
     *
     * @return list<ReturnableLine>
     */
    public function lines(): array
    {
        return $this->purchase()->returnableLines();
    }

    /**
     * Whether returns have started at all for this purchase.
     *
     * **The line between the two modules is drawn at delivery**, so a shopper
     * looking at something that has not been sent yet is not offered a return —
     * they are told that calling it off is a cancellation and pointed at the
     * purchase. The reverse case is the same courtesy from the other side: a
     * cancel button on a delivered order would be refused by that module's own
     * state machine, so this page is where the shopper is sent instead.
     */
    public function delivered(): bool
    {
        return $this->purchase()->anythingDelivered;
    }

    /**
     * The reasons a shopper may choose from — **the domain's closed set, in
     * full.**
     *
     * Seven slugs, enumerated from the enum itself rather than listed in
     * configuration, so a deployment cannot add an eighth and a release that adds
     * one cannot leave this page behind. A merchant groups by these to find the
     * batch that is faulty and the courier that keeps arriving late, and nobody
     * types the same sentence twice.
     *
     * It is a `<select>` and never a text box, because the value is copied into a
     * domain event and a log line: free text next to an event logger is where a
     * shopper's email address, or the name of the person a gift was for, ends up
     * in somebody's log retention.
     *
     * @return list<ReturnReason>
     */
    public function reasons(): array
    {
        return ReturnReason::cases();
    }

    /** Whether this deployment accepts the shopper's own words at all. */
    public function noteOffered(): bool
    {
        return (bool) config('returns-livewire.request.note.enabled', true);
    }

    /** At least one character, whatever a deployment puts in the configuration file. */
    public function noteLimit(): int
    {
        $limit = config('returns-livewire.request.note.max_length');

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 500;
    }

    /**
     * Ask to send one line back.
     *
     * Every argument is a **method argument**, not a bound property, so this
     * component has no writable public property at all — the same shape order
     * cancellation reached, and the reason a no-exceptions lock rule survives a
     * surface that takes real input.
     *
     * They arrive as strings because that is what a browser sends, and each is
     * turned into something the domain will accept or refused before it gets
     * near one:
     *
     * - the **line** is matched by identity against the lines the source just
     *   returned for this shopper's purchase, so one that is not theirs and one
     *   that does not exist get the same words;
     * - the **reason** is `ReturnReason::tryFrom()`, which is identity against
     *   the enum's cases — an unrecognised slug is **dropped**, never stored and
     *   never truncated into something that happens to match;
     * - the **quantity** has to be a positive integer here, and its ceiling is
     *   the domain's, which is why the refusal below carries both numbers;
     * - the **note** is refused when it is too long rather than cut in half,
     *   because the half that gets cut is the half describing the fault.
     */
    public function request(string $line = '', string $quantity = '', string $reason = '', string $note = ''): void
    {
        if (! $this->offered()) {
            $this->refuse($this->say('request.unavailable'));

            return;
        }

        // Re-read, inside the write. The form was drawn from an earlier answer
        // and another return may have landed since.
        $purchase = $this->purchase();

        $chosen = ctype_digit($line) ? $purchase->line((int) $line) : null;

        if ($chosen === null || ! $chosen->isReturnable()) {
            $this->refuse($this->say('request.line_unavailable'));

            return;
        }

        $wanted = ctype_digit($quantity) ? (int) $quantity : 0;

        if ($wanted < 1) {
            $this->refuse($this->say('request.quantity_required'));

            return;
        }

        $why = ReturnReason::tryFrom($reason);

        if ($why === null) {
            $this->refuse($this->say('request.reason_required'));

            return;
        }

        $words = $this->noteOffered() ? trim($note) : '';

        if (mb_strlen($words) > $this->noteLimit()) {
            $this->refuse($this->say('request.note_too_long', ['limit' => $this->noteLimit()]));

            return;
        }

        try {
            $return = new RequestReturn()->handle(
                new ReturnRequestInput(
                    orderId: $purchase->orderId,
                    lines: [new ReturnLineInput(
                        orderLineId: $chosen->orderLineId,
                        quantity: $wanted,
                        reason: $why,
                        // Read on the server, this request, from the source —
                        // never a property, never a field, never the browser's.
                        returnableQuantity: $chosen->returnableQuantity,
                        name: $chosen->name,
                        sku: $chosen->sku,
                        productId: $chosen->productId,
                        variantId: $chosen->variantId,
                        note: $words === '' ? null : $words,
                    )],
                    currency: $purchase->currency,
                    currencyExponent: $purchase->currencyExponent,
                    teamId: $purchase->teamId,
                    storeId: $this->shopper()->storeId(),
                    customerId: $this->shopper()->customerId(),
                ),
                $this->shopper()->customerId(),
            );
        } catch (ReturnQuantityExceeded) {
            // Surfaced, not capped. Both numbers are the server's: the one the
            // shopper asked for and the one the source said was left.
            $this->refuse($this->say('request.too_many', [
                'wanted' => $wanted,
                'returnable' => $chosen->returnableQuantity,
            ]));

            return;
        }

        // The forget below matters: the source is asked again on the next render
        // and the line's returnable quantity has just dropped.
        $this->forget();

        // The number, and nothing else. The shopper's own words went into the
        // domain and there is no path by which they come back out — not here,
        // not into a query string, not into a log line.
        $this->announce($this->say('request.done', ['number' => $return->number]));
    }

    public function render(): View
    {
        return view(ReturnsLivewireServiceProvider::NAMESPACE.'::livewire.start-return');
    }

    /** Drop the resolved purchase so the next read goes back to the source. */
    private function forget(): void
    {
        $this->resolved = null;
    }
}
