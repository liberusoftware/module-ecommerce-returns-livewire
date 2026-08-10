<?php

use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnDetail;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnHistory;
use Liberu\Ecommerce\Returns\Livewire\Components\StartReturn;
use Liberu\Ecommerce\Returns\Livewire\Pages\ReturnHistoryPage;
use Liberu\Ecommerce\Returns\Livewire\Pages\ReturnPage;
use Liberu\Ecommerce\Returns\Livewire\Pages\StartReturnPage;
use Livewire\Livewire;

/*
 * Accessibility that is assertable from rendered output.
 *
 * These are the parts a theme can break by accident when it publishes a view — a
 * reason select with no label, a page of links all called "View return", a request
 * that succeeds with nothing said about it. They are behaviour, not decoration,
 * and a published view that drops them fails here rather than in production.
 *
 * This is also the one surface in the fleet where a shopper posts a parcel because
 * of what a page told them. A shopper who cannot see the page has no other way to
 * know whether the request took.
 */

it('gives every field it renders a label of its own', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    // A placeholder is not a label: it disappears on the first keystroke and
    // screen readers are not obliged to read it. Hidden inputs are skipped, which
    // is the only reason the line id is allowed to be one.
    expectEveryFieldToBeLabelled(Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])->html());
});

it('associates the length limit with the field it limits', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $html = Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])->html();

    // The limit is read out with the label rather than discovered by being
    // refused after a paragraph has been typed.
    preg_match('/aria-describedby="([^"]+)"/', $html, $described);

    expect($described)->not->toBeEmpty()
        ->and($html)->toContain('id="'.$described[1].'"')
        ->and($html)->toContain(__('module-ecommerce-returns::returns.request.note_hint', ['limit' => 500]));
});

it('names each link after the return it opens', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return);

    Route::get('/returns/{number}', fn (): string => 'ok')->name('storefront.return');
    config()->set('returns-livewire.routes.return', 'storefront.return');

    // A page of links all called "View return" is a page nobody can use without
    // looking at it.
    Livewire::test(ReturnHistory::class)
        ->assertSee(__('module-ecommerce-returns::returns.history.view', ['number' => $return->number]));
});

it('puts the loading state inside a live region rather than beside it', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $return = returnFor($user->getKey());
    lineOn($return);

    foreach ([
        [ReturnHistory::class, []],
        [ReturnDetail::class, ['number' => $return->number]],
        [StartReturn::class, ['orderNumber' => ORDER_HANDLE]],
    ] as [$component, $parameters]) {
        expect(Livewire::test($component, $parameters)->html())
            ->toMatch('/<p role="status" aria-live="polite">\s*<span wire:loading/');
    }
});

it('says out loud that a return was requested, and names it', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    // A form disappearing is invisible to a screen reader, and so is a spinner.
    // The RMA number is the thing the shopper writes on the box.
    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value)
        ->assertSeeHtml('data-start-announcement')
        ->assertSee('RMA-');
});

it('announces for exactly one render and then stops', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return);

    // A live region announces changes. Carrying the sentence into the next
    // request either says nothing new or says it again at a moment when it is no
    // longer true.
    Livewire::test(ReturnDetail::class, ['number' => $return->number])
        ->call('cancel')
        ->assertSee(__('module-ecommerce-returns::returns.cancel.done'))
        ->call('$refresh')
        ->assertSet('announcement', '')
        ->assertSet('refusal', '');
});

it('interrupts for bad news and does not for good', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    // A polite region can go unread until the shopper next moves focus, and a
    // shopper who has just asked to send something back and been refused needs to
    // know before they walk away and post it.
    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value)
        ->assertSeeHtml('role="status"')
        ->assertDontSeeHtml('role="alert"');

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '99', ReturnReason::Faulty->value)
        ->assertSeeHtml('role="alert"');
});

it('keys every line so focus survives a re-render', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $return = returnFor($user->getKey());
    lineOn($return, 'Merino Crew');
    lineOn($return, 'Lambswool Scarf', orderLineId: OTHER_ORDER_LINE_ID);

    // Without a key, a change that rewrites every line at once reorders the DOM
    // under whoever was tabbed into it.
    expect(Livewire::test(ReturnDetail::class, ['number' => $return->number])->html())
        ->toContain('wire:key="return-line-')
        ->and(Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])->html())
        ->toContain('wire:key="start-line-');
});

it('submits through a real form so Enter works without a line of JavaScript', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $return = returnFor($user->getKey());
    lineOn($return);

    foreach ([
        [StartReturn::class, ['orderNumber' => ORDER_HANDLE]],
        [ReturnDetail::class, ['number' => $return->number]],
    ] as [$component, $parameters]) {
        Livewire::test($component, $parameters)
            ->assertSeeHtml('<form')
            ->assertSeeHtml('type="submit"');
    }
});

it('starts a routable page at h1 and its components at h2', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $return = returnFor($user->getKey());
    lineOn($return);

    // The heading list is the page outline. A page whose first heading is an h2
    // has no outline at all.
    expect(Livewire::test(ReturnHistoryPage::class)->html())->toContain('<h1>')->toContain('<h2>');

    expect(Livewire::test(ReturnPage::class, ['number' => $return->number])->html())
        ->toContain('<h1>')->toContain('<h2>')->toContain('<h3>');

    expect(Livewire::test(StartReturnPage::class, ['orderNumber' => ORDER_HANDLE])->html())
        ->toContain('<h1>')->toContain('<h2>');
});

it('spells a count into a sentence rather than a bare number', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return, 'Merino Crew', quantity: 3);

    Livewire::test(ReturnHistory::class)
        ->assertSee(trans_choice('module-ecommerce-returns::returns.history.items', 3, ['count' => 3]))
        ->assertSee($return->number);
});

it('offers a way out of a page it has refused something on', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    Route::get('/help', fn (): string => 'ok')->name('storefront.help');
    config()->set('returns-livewire.routes.help', 'storefront.help');

    // A refusal with no next step is a dead end, and this one is next to a parcel
    // somebody is holding.
    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '99', ReturnReason::Faulty->value)
        ->assertSee(__('module-ecommerce-returns::returns.help'))
        ->assertSeeHtml('href="http://localhost/help"');
});
