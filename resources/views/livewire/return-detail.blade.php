{{-- Functional and theme-ready: structure and labels only. A theme publishes
     this view and owns the classes, tokens and layout. What it must not drop is
     the accessible plumbing — the two live regions, the wire:keys, the accessible
     names — which is behaviour, not decoration.

     Three rules a published copy must keep:

     1. Every amount comes from `$this->money()`, which formats integer minor
        units by string arithmetic. `(int) (19.99 * 100)` is 1998; no float, no
        `number_format()`, no `Number::currency()`.
     2. The only state word on this page is `$this->status($return)`, keyed by the
        domain's own enum. Progress is counters, not a state.
     3. There is no shopper's note on this page and there is no way to add one.
        The read model does not carry it — by rule and by test in the domain
        package — so the text goes in and cannot come back out. A published copy
        that reaches for the model to render it has undone the containment. --}}
<div data-return>
    @if (! $this->signedIn())
        {{-- No lookup has been performed, so the answer does not depend on
             whether the number is real. --}}
        <p data-return-sign-in>{{ __('module-ecommerce-returns::returns.sign_in') }}</p>
    @else
        @php($return = $this->returnRequest())

        <h2>{{ __('module-ecommerce-returns::returns.return_heading', ['number' => $return->number]) }}</h2>

        {{-- Two regions, and the split is deliberate. A polite one can go unread
             until the shopper next moves focus, and somebody who has just pressed
             "cancel this request" and been refused needs to know before they walk
             away believing nothing is expected of them. --}}
        <p role="status" aria-live="polite">
            <span wire:loading data-return-loading>{{ __('module-ecommerce-returns::returns.loading') }}</span>
            <span data-return-announcement>{{ $announcement }}</span>
        </p>

        @if ($refusal !== '')
            <p role="alert" data-return-refusal>
                {{ $refusal }}

                @php($help = $this->link('help'))
                @if ($help)
                    <a href="{{ $help }}">{{ __('module-ecommerce-returns::returns.help') }}</a>
                @endif
            </p>
        @endif

        <p>
            <span data-return-status>{{ $this->status($return) }}</span>
        </p>

        {{-- What to do next, in a sentence. This is where the one-way door is
             said out loud: a return that has been checked, or one whose window
             ran out, will not take another parcel, and the answer is a new
             request rather than a page with no controls on it. --}}
        <p data-return-next>{{ $this->nextStep($return) }}</p>

        {{-- A return that will take no more goods gets a way out of the page.
             It is deliberately *not* a link to a new request: starting one needs
             the purchase's public handle, and a return carries only the order's
             row id — putting that in a URL is the enumeration every handle in
             this fleet exists to prevent. So the shopper is offered a human and
             their own list, and the sentence above tells them what to ask for. --}}
        @if ($this->closedToGoods())
            @php($closedHelp = $this->link('help'))
            @if ($closedHelp)
                <p><a href="{{ $closedHelp }}" data-return-closed>{{ __('module-ecommerce-returns::returns.help') }}</a></p>
            @endif
        @endif

        <h3>{{ __('module-ecommerce-returns::returns.line.heading') }}</h3>

        <ul>
            @foreach ($return->lines as $line)
                {{-- Keyed so focus survives a re-render — a cancellation rewrites
                     the page under whoever was tabbed into it. --}}
                <li wire:key="return-line-{{ $line->id }}" data-return-line="{{ $line->id }}">
                    <span data-return-line-name>{{ $line->name }}</span>

                    @if ($line->sku)
                        <span>{{ __('module-ecommerce-returns::returns.line.sku') }} {{ $line->sku }}</span>
                    @endif

                    <span data-return-line-reason>
                        {{ __('module-ecommerce-returns::returns.line.reason') }}
                        {{ $this->reasonLabel($line->reason->value) }}
                    </span>

                    {{-- The counters, said out loud. Nothing here is a state, and
                         the two inspection dispositions are deliberately not
                         here — see the language file. --}}
                    <span data-return-line-progress>{{ $this->lineProgress($line) }}</span>
                </li>
            @endforeach
        </ul>

        @php($refunded = $this->refunded())
        @if ($refunded)
            <h3>{{ __('module-ecommerce-returns::returns.refund.heading') }}</h3>

            {{-- A sum over rows the domain computes and stores nowhere. There is
                 no refund status, because a row exists only when the money moved,
                 and no balance, because this package holds no line prices. --}}
            <p data-return-refunded>{{ __('module-ecommerce-returns::returns.refund.total', ['amount' => $refunded]) }}</p>
        @endif

        {{-- Cancellation appears only where the state machine will accept it.
             `$this->cancellable()` asks the domain's own transition table, and
             pressing the button calls the domain's action, which consults it
             again before it writes.

             The button takes no arguments at all. A shopper pressing it is itself
             the reason, and the slug the history row records is a constant chosen
             on the server. --}}
        @if ($this->cancellable())
            <h3>{{ __('module-ecommerce-returns::returns.cancel.heading') }}</h3>

            <p>{{ __('module-ecommerce-returns::returns.cancel.explain') }}</p>

            {{-- A real form with a real submit button, so Enter works without a
                 line of JavaScript. --}}
            <form wire:submit="cancel" data-return-cancel>
                <button type="submit">{{ __('module-ecommerce-returns::returns.cancel.submit') }}</button>
            </form>
        @endif
    @endif
</div>
