{{-- Functional and theme-ready: structure and labels only. A theme publishes
     this view and owns the classes, tokens and layout. What it must not drop is
     the accessible plumbing — the live regions, the label on every control, the
     error association, the wire:keys — which is behaviour, not decoration.

     This is the one form in this package, and it is the one place in this wave
     where a shopper causes a domain write. Two things a published copy must keep:

     1. **No quantity ceiling, product name, price or eligibility number is ever
        posted back.** The only three values that travel are the order line id,
        the quantity and the reason slug, plus the optional note. Everything else
        is read on the server inside the write, from the source the host bound.
        Adding a hidden field for a "returnable" number would hand the browser the
        one value the whole design keeps away from it.
     2. **The reason is a `<select>` over the domain's closed set, never a text
        box.** The slug is copied into a domain event and a log line. The note is
        the one free-text field, it is refused rather than truncated, and nothing
        on this page ever renders it back. --}}
<div data-start-return>
    <h2>{{ __('module-ecommerce-returns::returns.start_heading') }}</h2>

    <p role="status" aria-live="polite">
        <span wire:loading data-start-loading>{{ __('module-ecommerce-returns::returns.loading') }}</span>
        <span data-start-announcement>{{ $announcement }}</span>
    </p>

    @if ($refusal !== '')
        <p role="alert" data-start-refusal>
            {{ $refusal }}

            @php($help = $this->link('help'))
            @if ($help)
                <a href="{{ $help }}">{{ __('module-ecommerce-returns::returns.help') }}</a>
            @endif
        </p>
    @endif

    @if (! $this->signedIn())
        {{-- No source has been consulted, so the answer does not depend on
             whether the handle is real. --}}
        <p data-start-sign-in>{{ __('module-ecommerce-returns::returns.sign_in') }}</p>
    @elseif (! $this->offered())
        <p data-start-unavailable>{{ __('module-ecommerce-returns::returns.request.unavailable') }}</p>
    @elseif (! $this->delivered())
        {{-- The line between the two modules, said in words rather than shown as
             an empty list. Calling off something that has not happened is a
             cancellation, and it belongs to whoever owns the order. --}}
        <p data-start-not-delivered>{{ __('module-ecommerce-returns::returns.request.not_delivered') }}</p>

        @php($order = $this->link('order', ['number' => $this->purchase()->number]))
        @if ($order)
            <p><a href="{{ $order }}">{{ __('module-ecommerce-returns::returns.request.go_to_order') }}</a></p>
        @endif
    @elseif ($this->lines() === [])
        <p data-start-nothing>{{ __('module-ecommerce-returns::returns.request.nothing') }}</p>
    @else
        <p>{{ __('module-ecommerce-returns::returns.request.explain') }}</p>

        @foreach ($this->lines() as $line)
            {{-- One form per line, and one line per request. The domain accepts
                 many, and offering one is a decision: two things going back for
                 two reasons are two requests a merchant can answer separately,
                 and it keeps every value a shopper types a scalar method argument
                 rather than an array the browser fills in. --}}
            @php($submit = $this->noteOffered()
                ? 'request($event.target.line.value, $event.target.quantity.value, $event.target.reason.value, $event.target.note.value)'
                : 'request($event.target.line.value, $event.target.quantity.value, $event.target.reason.value)')
            <form
                wire:key="start-line-{{ $line->orderLineId }}"
                wire:submit="{{ $submit }}"
                data-start-line="{{ $line->orderLineId }}"
            >
                <h3 data-start-line-name>{{ $line->name }}</h3>

                @if ($line->sku)
                    <p>{{ __('module-ecommerce-returns::returns.line.sku') }} {{ $line->sku }}</p>
                @endif

                {{-- The line id, and only the line id. It is checked by identity
                     against the lines the source returned for this shopper inside
                     the write, so one that is not theirs gets the same words as
                     one that does not exist. --}}
                <input type="hidden" name="line" value="{{ $line->orderLineId }}">

                {{-- A select bounded by the number the server just read. The
                     bound is a courtesy, not a control: the ceiling is checked
                     again on the server, against a value read again, and the
                     domain refuses rather than caps. --}}
                <label for="start-quantity-{{ $line->orderLineId }}">
                    {{ __('module-ecommerce-returns::returns.request.quantity') }}
                </label>
                <select id="start-quantity-{{ $line->orderLineId }}" name="quantity" required>
                    @for ($n = 1; $n <= $line->returnableQuantity; $n++)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endfor
                </select>

                <label for="start-reason-{{ $line->orderLineId }}">
                    {{ __('module-ecommerce-returns::returns.request.reason') }}
                </label>
                <select id="start-reason-{{ $line->orderLineId }}" name="reason" required>
                    <option value="">{{ __('module-ecommerce-returns::returns.request.reason_placeholder') }}</option>
                    @foreach ($this->reasons() as $reason)
                        <option value="{{ $reason->value }}">{{ $this->reasonLabel($reason->value) }}</option>
                    @endforeach
                </select>

                @if ($this->noteOffered())
                    <label for="start-note-{{ $line->orderLineId }}">
                        {{ __('module-ecommerce-returns::returns.request.note') }}
                    </label>
                    {{-- The hint is associated with the field rather than left
                         floating beside it, so the limit is read out with the
                         label instead of being discovered by being refused. --}}
                    <textarea
                        id="start-note-{{ $line->orderLineId }}"
                        name="note"
                        maxlength="{{ $this->noteLimit() }}"
                        aria-describedby="start-note-hint-{{ $line->orderLineId }}"
                    ></textarea>
                    <span id="start-note-hint-{{ $line->orderLineId }}">
                        {{ __('module-ecommerce-returns::returns.request.note_hint', ['limit' => $this->noteLimit()]) }}
                    </span>
                @endif

                {{-- A real submit button, so Enter works without a line of
                     JavaScript. --}}
                <button type="submit">{{ __('module-ecommerce-returns::returns.request.submit') }}</button>
            </form>
        @endforeach
    @endif
</div>
