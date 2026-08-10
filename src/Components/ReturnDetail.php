<?php

namespace Liberu\Ecommerce\Returns\Livewire\Components;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Returns\Actions\TransitionReturn;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Exceptions\IllegalReturnTransition;
use Liberu\Ecommerce\Returns\Livewire\Concerns\ShowsOwnReturns;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One of the shopper's own returns: what is going back, how far each line has
 * got, what has been refunded — and, where the domain will accept one, calling
 * the request off.
 *
 * The number is the only thing that travels, it is `#[Locked]`, and the read it
 * feeds is already narrowed to the signed-in customer before the number is
 * applied to it. See `Concerns\ShowsOwnReturns`.
 *
 * ## What a shopper may do here, and what they may not
 *
 * The domain publishes seven staff abilities on a return. Six of them are not
 * offered on this page and the choice is deliberate rather than cautious:
 *
 * - **approve** and **refuse** are the merchant deciding, for a quantity of
 *   their own choosing, whether goods may come back at all. A shopper
 *   authorising their own return is the workflow having no merchant in it.
 * - **receive** is a receiving desk saying a parcel physically arrived. Nobody
 *   can assert that from a browser; a shopper who could would be able to trigger
 *   a refund for a box nobody has.
 * - **inspect** is a judgement about the condition of goods somebody is holding.
 * - **resolve** closes the arithmetic.
 * - **refund** moves money, and there is no amount on this component at all —
 *   see below.
 *
 * **Cancel** is offered, because calling off a request you made yourself before
 * anything has moved is the one thing a shopper genuinely owns. It is drawn only
 * where the domain's own transition table will accept it — which is `requested`
 * and `approved`, and stops the moment goods arrive — and this component restates
 * none of that: it asks, and it lets the action refuse.
 *
 * ## Nothing here can set what the shopper is owed
 *
 * There is no amount property, no currency property and no refund control. What
 * was refunded is `ReturnData::$refundedMinor`, a **sum over rows** the domain
 * computes and stores nowhere, and a row exists only because money already moved.
 * There is no refund status to render because the domain publishes none — a row
 * means it went back — and no balance, because this package holds no line prices
 * and a number claiming to know what "fully refunded" would be is a number
 * somebody would trust.
 *
 * The opaque reference on a refund is not rendered either. It is whatever the
 * host calls the movement — a ledger entry, a credit note — and it is never
 * parsed anywhere; putting an unvetted internal identifier on a storefront page
 * is not a decision this package gets to make on a deployment's behalf.
 */
class ReturnDetail extends Component
{
    use ShowsOwnReturns;

    /**
     * The slug written to the domain's 64-character history column.
     *
     * A constant, decided here. The domain's telemetry copies this value into a
     * log line, and the whole argument for a closed set of slugs is that a text
     * box next to an event logger is where a shopper's email address gets typed.
     */
    private const CANCELLED_BY_SHOPPER = 'cancelled-by-shopper';

    /**
     * The return's public reference — `RMA-` and twelve hex characters, minted by
     * the domain from the CSPRNG.
     *
     * Locked because it is the handle a link hands the browser. The lock is not
     * what protects the return, though: the query is narrowed to this customer
     * before this value is applied to it, so a swapped number finds nothing even
     * without it. The lock is what stops a swap being attempted at all, and what
     * keeps the next person from adding a `#[Url]` to it.
     */
    #[Locked]
    public string $number = '';

    private ?ReturnRequest $model = null;

    private ?ReturnData $data = null;

    public function mount(string $number): void
    {
        $this->number = $number;
    }

    /**
     * The return, as the published read model.
     *
     * Called only from inside a `signedIn()` branch: a guest is shown an
     * invitation to sign in and no lookup is performed, because there is nothing
     * a guest could present that would make a return theirs.
     *
     * The read model is also what contains the shopper's note. `ReturnLineData`
     * does not carry it, by rule and by test in the domain package, so this page
     * has no way to render it back even if a future edit here wanted to.
     */
    public function returnRequest(): ReturnData
    {
        return $this->data ??= ReturnData::from($this->model());
    }

    /** Whether this deployment lets a shopper call off their own request at all. */
    public function cancellationOffered(): bool
    {
        return (bool) config('returns-livewire.cancellation.enabled', true);
    }

    /**
     * Whether to draw the cancel control.
     *
     * The condition is the domain's transition table and nothing else. Drawing a
     * control the state machine would refuse is worse than drawing none — a
     * shopper who presses it has been told they can call the return off, and
     * finding out otherwise afterwards is how a support ticket starts.
     */
    public function cancellable(): bool
    {
        return $this->cancellationOffered()
            && $this->returnRequest()->status->canTransitionTo(ReturnStatus::Cancelled);
    }

    /**
     * Whether this return is closed to further goods.
     *
     * **Inspecting is a one-way door.** Once a disposition is recorded the
     * merchant has priced the outcome, and a parcel arriving afterwards lands
     * against arithmetic that has already been settled — so the domain refuses to
     * adopt it, loudly. An expired return is the same shape of closed for the
     * opposite reason: the window ran out and nothing came.
     *
     * A shopper who has a parcel in their hand needs to be told that a late one
     * is a new request, not shown a page with no controls on it.
     *
     * Derived from the domain's own two answers rather than from a list of state
     * names copied over here: a return is closed to goods when it does not accept
     * them *now* and can no longer reach the state that would. A freshly
     * requested return fails the second half — nobody has authorised it yet, but
     * somebody still might — so it is not closed, it is waiting.
     */
    public function closedToGoods(): bool
    {
        $status = $this->returnRequest()->status;

        return ! $status->acceptsGoods() && ! $status->canTransitionTo(ReturnStatus::Approved);
    }

    public function refunded(): ?string
    {
        $return = $this->returnRequest();

        return $return->refundedMinor > 0
            ? $this->money($return->refundedMinor, $return->currency, $return->currencyExponent)
            : null;
    }

    /**
     * Call off a request the shopper made.
     *
     * **No arguments.** A shopper pressing "cancel this request" is itself the
     * reason, the history row the domain writes records that the customer did it,
     * and a list of reason slugs here would be a second place to keep a fact the
     * actor column already holds. The slug that is stored is a constant chosen on
     * the server, so the one value that reaches the domain's event logger from
     * this page cannot be anything a browser said.
     *
     * The state is **not** re-checked here before the call. It would be one more
     * copy of a rule the domain already owns, and letting the action refuse is
     * what tells us the refusal happened rather than assuming it — a parcel can
     * be booked in between the page being drawn and the button being pressed, and
     * that race resolves in the domain's favour. {@see cancellable()} still
     * decides whether the control is drawn; that is a rendering question, and
     * this is the writing one.
     */
    public function cancel(): void
    {
        if (! $this->cancellationOffered()) {
            $this->refuse($this->say('cancel.unavailable'));

            return;
        }

        $return = $this->model();

        try {
            new TransitionReturn()->handle($return, ReturnStatus::Cancelled, $this->shopper()->customerId(), self::CANCELLED_BY_SHOPPER);
        } catch (IllegalReturnTransition) {
            // The domain refused on what the database says, which is the case
            // this catch exists for: the return moved between the page being
            // drawn and the button being pressed. Re-read before choosing the
            // sentence, so the shopper is told where it actually got to rather
            // than a message assembled from a stale copy.
            $this->forget();

            $this->refuse($this->say('cancel.too_late', ['status' => $this->status($this->returnRequest())]));

            return;
        }

        $this->forget();

        $this->announce($this->say('cancel.done'));
    }

    public function render(): View
    {
        return view(ReturnsLivewireServiceProvider::NAMESPACE.'::livewire.return-detail');
    }

    /**
     * The return this component is presenting, or a 404.
     *
     * A number that names nothing and a number that names somebody else's return
     * are answered identically, because the difference between the two is
     * information about somebody else's return — and it is precisely the
     * information that turns a guessed number into a confirmed one.
     */
    private function model(): ReturnRequest
    {
        if ($this->model !== null) {
            return $this->model;
        }

        $return = $this->returnNumbered($this->number);

        if ($return === null) {
            abort(404);
        }

        return $this->model = $return;
    }

    /** Drop the cached return so the next read goes back to the database. */
    private function forget(): void
    {
        $this->model = null;
        $this->data = null;
    }
}
