<?php

use Liberu\Ecommerce\Returns\Actions\RecordRefund;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnDetail;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Livewire\Livewire;

/*
 * One return, and what a shopper may do to it.
 */

it('renders only the states the domain publishes, and all of them', function () {
    $translated = array_keys((array) __(ReturnsLivewireServiceProvider::NAMESPACE.'::returns.status'));
    $published = array_map(fn (ReturnStatus $status): string => $status->value, ReturnStatus::cases());

    sort($translated);
    sort($published);

    // Equal, not a subset in either direction. A missing key renders a raw
    // translation key at the worst possible moment; an extra one is this package
    // publishing a state the domain refused, which puts a disagreement in front
    // of the customer instead of in a column.
    expect($translated)->toBe($published);
});

it('has something to say about every state, not only the interesting ones', function () {
    $translated = array_keys((array) __(ReturnsLivewireServiceProvider::NAMESPACE.'::returns.next'));
    $published = array_map(fn (ReturnStatus $status): string => $status->value, ReturnStatus::cases());

    sort($translated);
    sort($published);

    expect($translated)->toBe($published);
});

it('shows what is going back and how far it has got', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return, 'Merino Crew', quantity: 2);

    Livewire::test(ReturnDetail::class, ['number' => $return->number])
        ->assertSee('Merino Crew')
        ->assertSee('GHOST-1')
        ->assertSee(__('module-ecommerce-returns::returns.line.requested', ['count' => 2]))
        ->assertSee(__('module-ecommerce-returns::returns.reason.faulty'))
        ->assertSee(__('module-ecommerce-returns::returns.status.requested'))
        ->assertSee(__('module-ecommerce-returns::returns.next.requested'));
});

it('says what to do about a return that will take no more goods', function () {
    $user = shopper($this);

    // Inspecting is a one-way door: the disposition is recorded, the outcome is
    // priced, and a parcel arriving now lands against settled arithmetic. A
    // shopper holding one needs to be told that, not shown a page with nothing
    // on it.
    $return = returnFor($user->getKey(), state: fn ($factory) => $factory->inspected());
    lineOn($return);

    Livewire::test(ReturnDetail::class, ['number' => $return->number])
        ->assertSee(__('module-ecommerce-returns::returns.next.inspected'))
        ->assertDontSee(__('module-ecommerce-returns::returns.cancel.submit'));
});

it('offers a cancellation exactly where the state machine will take one', function () {
    $user = shopper($this);

    $open = returnFor($user->getKey());
    lineOn($open);

    $arrived = returnFor($user->getKey(), state: fn ($factory) => $factory->received());
    lineOn($arrived);

    // The condition is the domain's own transition table and nothing else.
    // Drawing a control the state machine would refuse is worse than drawing
    // none.
    Livewire::test(ReturnDetail::class, ['number' => $open->number])
        ->assertSee(__('module-ecommerce-returns::returns.cancel.submit'));

    Livewire::test(ReturnDetail::class, ['number' => $arrived->number])
        ->assertDontSee(__('module-ecommerce-returns::returns.cancel.submit'));
});

it('cancels a request the shopper made, and records that they did it', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return);

    Livewire::test(ReturnDetail::class, ['number' => $return->number])
        ->call('cancel')
        ->assertSee(__('module-ecommerce-returns::returns.cancel.done'))
        ->assertSee(__('module-ecommerce-returns::returns.status.cancelled'));

    $return->refresh()->load('statusChanges');

    $change = $return->statusChanges->firstWhere('to_status', ReturnStatus::Cancelled);

    expect($return->status)->toBe(ReturnStatus::Cancelled)
        // The actor is the shopper — that is the whole value of the row. And the
        // reason is a slug the server chose, because the domain's event logger
        // copies it into a log line.
        ->and($change?->actor_id)->toBe($user->getKey())
        ->and($change?->reason)->toBe('cancelled-by-shopper');
});

it('lets the domain refuse a cancellation that has been overtaken', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return);

    $component = Livewire::test(ReturnDetail::class, ['number' => $return->number]);

    // A parcel is booked in between the page being drawn and the button being
    // pressed. The state is not re-checked before the call, so the refusal comes
    // from the state machine rather than from a copy of its rules.
    ReturnRequest::query()->whereKey($return->getKey())->update(['status' => ReturnStatus::Received->value]);

    $component->call('cancel')
        ->assertSeeHtml('role="alert"')
        ->assertSee(__('module-ecommerce-returns::returns.cancel.too_late', [
            'status' => __('module-ecommerce-returns::returns.status.received'),
        ]));

    expect($return->refresh()->status)->toBe(ReturnStatus::Received);
});

it('refuses a cancellation the deployment has turned off, even when it is called anyway', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return);
    config()->set('returns-livewire.cancellation.enabled', false);

    Livewire::test(ReturnDetail::class, ['number' => $return->number])
        ->assertDontSee(__('module-ecommerce-returns::returns.cancel.submit'))
        ->call('cancel')
        ->assertSee(__('module-ecommerce-returns::returns.cancel.unavailable'));

    expect($return->refresh()->status)->toBe(ReturnStatus::Requested);
});

it('shows what has gone back without publishing a status or a balance for it', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey(), state: fn ($factory) => $factory->received());
    $line = lineOn($return);
    $line->forceFill(['quantity_approved' => 1, 'quantity_received' => 1])->save();

    new RecordRefund()->handle($return->refresh(), amountMinor: 1999, reference: 'credit-note-8812');

    $html = Livewire::test(ReturnDetail::class, ['number' => $return->number])->html();

    // A sum over rows, rendered by string arithmetic from integer minor units.
    // `(int) (19.99 * 100)` is 1998, and the same class of error on the way out
    // is a penny of drift per line.
    expect($html)->toContain('GBP 19.99')
        // No opaque reference on a storefront page: it is whatever the host calls
        // the movement and is never parsed anywhere.
        ->and($html)->not->toContain('credit-note-8812');
});

it('has no way for a shopper to say what they are owed', function () {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        new ReflectionClass(ReturnDetail::class)->getProperties(),
    );

    // Not "the amount is validated" — *there is no amount*. A refund is an amount,
    // a currency, an opaque reference and a kind, and every one of them is decided
    // by whoever moved the money.
    expect(array_filter($properties, fn (string $name): bool => preg_match('/(amount|minor|refund|currency|price)/i', $name) === 1))->toBe([])
        ->and(method_exists(ReturnDetail::class, 'refund'))->toBeFalse();
});
