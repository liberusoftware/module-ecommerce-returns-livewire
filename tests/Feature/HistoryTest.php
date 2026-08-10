<?php

use Liberu\Ecommerce\Returns\Livewire\Components\ReturnHistory;
use Livewire\Livewire;

it('lists the shopper\'s own returns and nobody else\'s', function () {
    $user = shopper($this);

    $mine = returnFor($user->getKey());
    lineOn($mine);

    $theirs = returnFor(STRANGER);
    lineOn($theirs);

    $orphan = returnFor(null);
    lineOn($orphan);

    Livewire::test(ReturnHistory::class)
        ->assertSee($mine->number)
        ->assertDontSee($theirs->number)
        // A return with no customer is nobody's, and it must not appear either.
        // `where('customer_id', null)` would list exactly these.
        ->assertDontSee($orphan->number);
});

it('narrows to one storefront with a bound comparison rather than a null one', function () {
    $user = shopper($this);

    $here = returnFor($user->getKey(), storeId: 1);
    lineOn($here);

    $elsewhere = returnFor($user->getKey(), storeId: OTHER_STORE);
    lineOn($elsewhere);

    $nowhere = returnFor($user->getKey());
    lineOn($nowhere);

    storefront(1);

    // A return belonging to no storefront is not this storefront's, and a scope
    // written `where('store_id', $storeId)` with a null would have listed exactly
    // that one and nothing else.
    Livewire::test(ReturnHistory::class)
        ->assertSee($here->number)
        ->assertDontSee($elsewhere->number)
        ->assertDontSee($nowhere->number);
});

it('lists everything when the deployment has one storefront', function () {
    $user = shopper($this);

    $nowhere = returnFor($user->getKey());
    lineOn($nowhere);

    $somewhere = returnFor($user->getKey(), storeId: OTHER_STORE);
    lineOn($somewhere);

    storefront(null);

    Livewire::test(ReturnHistory::class)
        ->assertSee($nowhere->number)
        ->assertSee($somewhere->number);
});

it('grows the page rather than turning it', function () {
    $user = shopper($this);

    config()->set('returns-livewire.per_page', 2);

    $numbers = [];

    foreach (range(1, 5) as $ignored) {
        $return = returnFor($user->getKey());
        lineOn($return);
        $numbers[] = $return->number;
    }

    $component = Livewire::test(ReturnHistory::class)->assertSet('showing', 2);

    expect(count($component->instance()->returns()))->toBe(2)
        ->and($component->instance()->hasMore())->toBeTrue();

    $component->call('loadMore')->assertSet('showing', 4);

    $component->call('loadMore')->assertSet('showing', 6);

    // Everything is on the page now, and there is nothing left to ask for.
    expect($component->instance()->hasMore())->toBeFalse()
        ->and($numbers)->toHaveCount(5);
});

it('falls back to a sane page size when a deployment configures nonsense', function () {
    $user = shopper($this);

    config()->set('returns-livewire.per_page', 0);

    returnFor($user->getKey());

    Livewire::test(ReturnHistory::class)->assertSet('showing', 10);
});

it('says a history is empty rather than rendering nothing at all', function () {
    shopper($this);

    Livewire::test(ReturnHistory::class)->assertSee(__('module-ecommerce-returns::returns.history.empty'));
});

it('counts the units a return covers off its lines', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return, 'Merino Crew', quantity: 2);
    lineOn($return, 'Lambswool Scarf', quantity: 1, orderLineId: OTHER_ORDER_LINE_ID);

    // "3" beside an icon is a number with no subject.
    Livewire::test(ReturnHistory::class)
        ->assertSee(trans_choice('module-ecommerce-returns::returns.history.items', 3, ['count' => 3]));
});
