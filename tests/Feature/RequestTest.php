<?php

use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnDetail;
use Liberu\Ecommerce\Returns\Livewire\Components\StartReturn;
use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableLine;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Livewire\Livewire;

/*
 * The one surface in this package that writes.
 *
 * Everything here is about the same question asked six ways: which of the values
 * that reach the domain came from the browser, and what happened to each of them
 * on the way.
 */

it('writes a return whose every number the server chose', function () {
    $user = shopper($this);
    storefront(OTHER_STORE);
    sellTo($user->getKey(), purchase());

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '2', ReturnReason::Faulty->value)
        ->assertSee(__('module-ecommerce-returns::returns.request.done', ['number' => ReturnRequest::query()->sole()->number]));

    $return = ReturnRequest::query()->sole();
    $line = ReturnLine::query()->sole();

    // The order id, the currency, the exponent, the merchant, the storefront and
    // the customer are all values the browser never sent. The three it did send
    // are the line, the quantity and the reason.
    expect($return->order_id)->toBe(ORDER_ID)
        ->and($return->currency)->toBe('GBP')
        ->and($return->currency_exponent)->toBe(2)
        ->and($return->team_id)->toBe(MERCHANT_TEAM)
        ->and($return->store_id)->toBe(OTHER_STORE)
        ->and($return->customer_id)->toBe($user->getKey())
        ->and($return->status)->toBe(ReturnStatus::Requested)
        ->and($line->order_line_id)->toBe(ORDER_LINE_ID)
        ->and($line->quantity_requested)->toBe(2)
        ->and($line->reason)->toBe(ReturnReason::Faulty)
        // Copied labels, from the source and not from the form.
        ->and($line->name)->toBe('Merino Crew')
        ->and($line->sku)->toBe('GHOST-1')
        ->and($line->product_id)->toBe(PRODUCT_ID);
});

it('stores the eligibility it was told, as evidence, and reads it on the server', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value);

    // Three is what the source said, and three is what was written down. Nothing
    // on the form carried it, and `IdentityTest` asserts there is no property it
    // could have arrived on.
    expect(ReturnLine::query()->sole()->returnable_quantity)->toBe(3);
});

it('reads the ceiling again inside the write rather than trusting the drawn form', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $component = Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])->assertSee('Merino Crew');

    // Something else came back between the page being drawn and the button being
    // pressed, and the source now says one. The request must be measured against
    // the new answer, not against the one the form was built from.
    sellTo($user->getKey(), purchase([new ReturnableLine(ORDER_LINE_ID, 'Merino Crew', 1, 'GHOST-1', PRODUCT_ID)]));

    $component->call('request', (string) ORDER_LINE_ID, '2', ReturnReason::Faulty->value)
        ->assertSee(__('module-ecommerce-returns::returns.request.too_many', ['wanted' => 2, 'returnable' => 1]));

    expect(ReturnRequest::query()->count())->toBe(0);
});

it('surfaces the domain\'s refusal in words instead of quietly capping it', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '9', ReturnReason::Faulty->value)
        ->assertSeeHtml('role="alert"')
        ->assertSee(__('module-ecommerce-returns::returns.request.too_many', ['wanted' => 9, 'returnable' => 3]));

    // Nothing written. Reducing nine to three would tell the shopper one thing
    // and the receiving desk another, and the disagreement would first appear as
    // a refund.
    expect(ReturnRequest::query()->count())->toBe(0)
        ->and(ReturnLine::query()->count())->toBe(0);
});

it('answers an order line that is not the shopper\'s exactly as one that does not exist', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $component = Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE]);

    foreach ([(string) OTHER_ORDER_LINE_ID, '1', 'not-a-number', ''] as $line) {
        $component->call('request', $line, '1', ReturnReason::Faulty->value)
            ->assertSee(__('module-ecommerce-returns::returns.request.line_unavailable'));
    }

    // And the one that is on the purchase writes, so the refusals above are about
    // the line and not about the component being broken.
    $component->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value);

    expect(ReturnLine::query()->sole()->order_line_id)->toBe(ORDER_LINE_ID);
});

it('validates the reason by identity against the enum and drops anything else', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $component = Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE]);

    // Uppercase, a near miss, a sentence somebody typed, and nothing at all. None
    // of these is truncated into something that happens to match; each is
    // refused, and none is stored.
    foreach (['FAULTY', 'faulty ', 'it broke when I opened the box', ''] as $reason) {
        $component->call('request', (string) ORDER_LINE_ID, '1', $reason)
            ->assertSee(__('module-ecommerce-returns::returns.request.reason_required'));
    }

    expect(ReturnRequest::query()->count())->toBe(0);

    $component->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::NoLongerWanted->value);

    expect(ReturnLine::query()->sole()->reason)->toBe(ReturnReason::NoLongerWanted);
});

it('refuses a quantity that is not a positive whole number', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $component = Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE]);

    foreach (['0', '-1', '1.5', 'two', ''] as $quantity) {
        $component->call('request', (string) ORDER_LINE_ID, $quantity, ReturnReason::Faulty->value)
            ->assertSee(__('module-ecommerce-returns::returns.request.quantity_required'));
    }

    expect(ReturnRequest::query()->count())->toBe(0);
});

it('takes the shopper\'s own words, and gives them nowhere to travel', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $note = 'It arrived split along the seam, please post to 4 Hill Road';

    $component = Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value, $note);

    $line = ReturnLine::query()->sole();
    $return = ReturnRequest::query()->sole();

    // The positive half first, so nothing below can pass by there being no note
    // at all: it went in, and the domain is holding it behind its policy.
    expect($line->note)->toBe($note);

    // And now the four places it must not be. The announcement is read verbatim
    // into a screen reader, the markup is what a shared screenshot carries, and
    // the read model is what every event and every log line is built from — the
    // domain package pins those two with its own tests, and this pins that this
    // package never reaches round them.
    expect($component->get('announcement'))->not->toContain('Hill Road')
        ->and($component->get('announcement'))->toContain($return->number)
        ->and($component->get('refusal'))->toBe('')
        ->and($component->html())->not->toContain('Hill Road')
        ->and(json_encode(ReturnData::from($return->load(['lines', 'refunds']))) ?: '')->not->toContain('Hill Road');

    // Nor on the page that renders the return afterwards. The read model does not
    // carry it, so there is no path back out at all.
    $detail = Livewire::test(ReturnDetail::class, ['number' => $return->number]);

    expect($detail->html())->not->toContain('Hill Road')
        ->and($detail->html())->toContain('Merino Crew');
});

it('refuses a note that is too long rather than cutting it in half', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());
    config()->set('returns-livewire.request.note.max_length', 20);

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value, str_repeat('a', 21))
        ->assertSee(__('module-ecommerce-returns::returns.request.note_too_long', ['limit' => 20]));

    // Not truncated, not stored, and the shopper was told. The half a truncation
    // cuts is the half describing the fault.
    expect(ReturnRequest::query()->count())->toBe(0);
});

it('drops a note entirely when the deployment does not accept one', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());
    config()->set('returns-livewire.request.note.enabled', false);

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->assertDontSeeHtml('<textarea')
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value, 'typed anyway');

    expect(ReturnLine::query()->sole()->note)->toBeNull();
});

it('points a shopper at cancellation when nothing has been sent to them yet', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase(delivered: false));

    // Returns begin after delivery, because the module that owns orders drew the
    // line there. A shopper looking at something that has not moved is told what
    // they can do instead, in words, rather than shown an empty list.
    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->assertSee(__('module-ecommerce-returns::returns.request.not_delivered'))
        ->assertDontSeeHtml('<form');
});

it('says so when everything has already been sent back', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase([new ReturnableLine(ORDER_LINE_ID, 'Merino Crew', 0)]));

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->assertSee(__('module-ecommerce-returns::returns.request.nothing'))
        ->assertDontSeeHtml('<form');
});

it('offers nothing at all where the deployment has turned requests off', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());
    config()->set('returns-livewire.request.enabled', false);

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->assertSee(__('module-ecommerce-returns::returns.request.unavailable'))
        ->assertDontSeeHtml('<form')
        // And the switch is on the write as well as on the render. A control that
        // is not drawn is not a control that cannot be called.
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value)
        ->assertSee(__('module-ecommerce-returns::returns.request.unavailable'));

    expect(ReturnRequest::query()->count())->toBe(0);
});

it('draws a fresh form from the source after a request is written', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $component = Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE]);

    // The source is the truth and it is asked again. A component caching the
    // purchase across the write would keep offering three long after one of them
    // was spoken for.
    sellTo($user->getKey(), purchase([new ReturnableLine(ORDER_LINE_ID, 'Merino Crew', 0)]));

    $component->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value)
        ->assertSee(__('module-ecommerce-returns::returns.request.line_unavailable'));
});
