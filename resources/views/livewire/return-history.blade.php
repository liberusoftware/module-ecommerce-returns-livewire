{{-- Functional and theme-ready: structure and labels only. A theme publishes
     this view and owns the classes, tokens and layout. What it must not drop is
     the accessible plumbing — the live region, the wire:keys, the accessible name
     on each link and the <time datetime> — which is behaviour, not decoration.

     There is no return id anywhere in this file, and there is no customer id. The
     list is whoever is signed in; each link carries the return's RMA number. --}}
<div data-returns-history>
    <h2>{{ __('module-ecommerce-returns::returns.heading') }}</h2>

    {{-- One live region per component: what it is doing now, and what just
         changed. A list that grows under a "show more" is invisible to a screen
         reader, and so is a spinner. --}}
    <p role="status" aria-live="polite">
        <span wire:loading data-returns-loading>{{ __('module-ecommerce-returns::returns.loading') }}</span>
        <span data-returns-announcement>{{ $announcement }}</span>
    </p>

    @if (! $this->signedIn())
        {{-- No query has run. A return with no customer is nobody's, and there is
             nothing a guest could present that would make one theirs. --}}
        <p data-returns-sign-in>{{ __('module-ecommerce-returns::returns.sign_in') }}</p>
    @elseif ($this->returns() === [])
        <p data-returns-empty>{{ __('module-ecommerce-returns::returns.history.empty') }}</p>
    @else
        <ul>
            @foreach ($this->returns() as $return)
                @php($items = $this->requestedQuantity($return))
                <li wire:key="return-{{ $return->number }}" data-return="{{ $return->number }}">
                    @php($href = $this->link('return', ['number' => $return->number]))

                    {{-- The accessible name carries the number, because a page of
                         links all called "View return" is a page nobody can use
                         without looking at it. Unset route, plain text: a `#`
                         href announces itself as a link and does nothing. --}}
                    @if ($href)
                        <a href="{{ $href }}">
                            {{ __('module-ecommerce-returns::returns.history.view', ['number' => $return->number]) }}
                        </a>
                    @else
                        <span data-return-number>{{ $return->number }}</span>
                    @endif

                    {{-- The state the domain publishes, and nothing else. --}}
                    <span data-return-status>{{ $this->status($return) }}</span>

                    <span data-return-items>
                        {{ trans_choice('module-ecommerce-returns::returns.history.items', $items, ['count' => $items]) }}
                    </span>

                    @if ($return->refundedMinor > 0)
                        <span data-return-refunded>
                            {{ __('module-ecommerce-returns::returns.refund.heading') }}
                            {{ $this->money($return->refundedMinor, $return->currency, $return->currencyExponent) }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($this->hasMore())
            <button type="button" wire:click="loadMore" data-returns-more>
                {{ __('module-ecommerce-returns::returns.history.more') }}
            </button>
        @endif
    @endif
</div>
