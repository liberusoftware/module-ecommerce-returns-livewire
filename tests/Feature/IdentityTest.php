<?php

use Liberu\Ecommerce\Returns\Livewire\Components\ReturnDetail;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnHistory;
use Liberu\Ecommerce\Returns\Livewire\Components\StartReturn;
use Liberu\Ecommerce\Returns\Livewire\Pages\ReturnPage;
use Liberu\Ecommerce\Returns\Livewire\Pages\StartReturnPage;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Liberu\Ecommerce\Returns\Livewire\Support\ShopperContext;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Whose returns these are, and what stops them being somebody else's.
 *
 * This is a storefront: every component property travels to the browser and back
 * on every request, which makes every one of them an input written by a client.
 * The inventory below is asserted by reflection rather than described, because a
 * description is the thing that rots — a property added next year without an
 * attribute fails here rather than never.
 */

$components = fn (): array => array_values(new ReturnsLivewireServiceProvider(app())->aliases());

/**
 * This package's own properties, not Livewire's.
 *
 * `getProperties()` walks the inheritance chain, and what the framework's base
 * class carries is the framework's business. The declaring class is what tells
 * the two apart, and traits are flattened into the component, so
 * `Concerns\ShowsOwnReturns::$announcement` is correctly this package's.
 *
 * @return array<int, ReflectionProperty>
 */
$ours = function (string $component): array {
    return array_values(array_filter(
        new ReflectionClass($component)->getProperties(),
        fn (ReflectionProperty $property): bool => ! str_starts_with($property->getDeclaringClass()->getName(), 'Livewire\\'),
    ));
};

/** @return array<string, string> every PHP file this package ships, by name. */
$sources = function (): array {
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getFilename()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
};

it('carries no record identifier in any public surface', function () use ($components, $ours) {
    $found = [];

    foreach ($components() as $component) {
        foreach ($ours($component) as $property) {
            if (preg_match('/(^id$|Id$|customer|store|team)/i', $property->getName()) === 1) {
                $found[] = $component.'::$'.$property->getName();
            }
        }

        foreach (new ReflectionClass($component)->getMethod('mount')->getParameters() as $parameter) {
            if (preg_match('/(^id$|Id$|customer|store|team)/i', $parameter->getName()) === 1) {
                $found[] = $component.'::mount($'.$parameter->getName().')';
            }
        }
    }

    // Not "locked" — *absent*. A return's row id, the order line it names, the
    // customer it is filed against, the storefront and the merchant are all
    // resolved by the server on every request, and none of them has anywhere to
    // arrive from. The two handles that travel are public references minted for
    // exactly this, and both are asserted locked below.
    expect($found)->toBe([]);
});

it('locks every public property, with no exceptions list', function () use ($components, $ours) {
    $unlocked = [];

    foreach ($components() as $component) {
        foreach ($ours($component) as $property) {
            if (! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            if ($property->getAttributes(Locked::class) === []) {
                $unlocked[] = $component.'::$'.$property->getName();
            }
        }
    }

    // Every one, on the surface that writes as well as the ones that read. The
    // three things a shopper genuinely chooses — which line, how many, and why —
    // travel as method arguments straight into checks against values the server
    // read, and the one thing they type travels the same way and stops there.
    expect($unlocked)->toBe([]);
});

it('binds nothing at all to the URL', function () use ($components, $ours) {
    $bound = [];

    foreach ($components() as $component) {
        foreach ($ours($component) as $property) {
            if ($property->getAttributes(Url::class) !== []) {
                $bound[] = $component.'::$'.$property->getName();
            }
        }
    }

    // Returns have no filter, no sort and no search, so there is nothing a query
    // string should carry here — and a `#[Url]` on either handle would put it in
    // a referrer header and a shared screenshot on top of the path it already
    // travels in.
    expect($bound)->toBe([]);
});

it('asks the domain query object for one thing only, and that thing takes the actor', function () use ($sources) {
    preg_match_all('/ReturnQuery::class\)\s*->\s*(\w+)\(/', implode("\n", $sources()), $calls);

    // Written as "there is exactly one entry point and it is the one keyed on the
    // actor", rather than as a list of the reads this package must not perform.
    // Spelling out a forbidden name in an assertion puts that name in the
    // repository in order to go looking for it, and the next reader cannot tell
    // the prohibition from the thing prohibited.
    expect($calls[1])->toBe(['forCustomer']);
});

it('asks who the shopper is before it asks what they bought', function () use ($sources) {
    // The source's signature is the guard: there is no way to resolve a purchase
    // without an actor, so this asserts that every call site passes one that came
    // from the context object rather than from anywhere else.
    preg_match_all('/forShopper\(\s*(\$\w+)/', implode("\n", $sources()), $calls);

    expect($calls[1])->not->toBeEmpty()
        ->and(array_unique($calls[1]))->toBe(['$customerId']);
});

it('answers a stranger\'s return exactly as it answers one that does not exist', function () {
    $user = shopper($this);

    $theirs = returnFor(STRANGER);
    lineOn($theirs, 'Somebody Else\'s Coat');

    $mine = returnFor($user->getKey());
    lineOn($mine);

    // All three the same 404, and the same one. Livewire 4 answers with a
    // Testable carrying that status rather than by throwing, so the assertion is
    // on the status.
    Livewire::test(ReturnDetail::class, ['number' => $theirs->number])->assertStatus(404);
    Livewire::test(ReturnDetail::class, ['number' => 'RMA-000000000000'])->assertStatus(404);
    Livewire::test(ReturnDetail::class, ['number' => ''])->assertStatus(404);

    // And the one that is mine renders, so the refusals above are about ownership
    // and not about the component being broken.
    Livewire::test(ReturnDetail::class, ['number' => $mine->number])->assertOk();
});

it('gives a page the same answer as the component it composes', function () {
    $user = shopper($this);

    $theirs = returnFor(STRANGER);
    $mine = returnFor($user->getKey());
    lineOn($mine);

    // The page resolves the number itself in `mount()`. A page that renders its
    // heading and lets the child refuse has already told somebody that a number
    // names something.
    Livewire::test(ReturnPage::class, ['number' => $theirs->number])->assertStatus(404);
    Livewire::test(ReturnPage::class, ['number' => 'RMA-000000000000'])->assertStatus(404);
    Livewire::test(ReturnPage::class, ['number' => $mine->number])->assertOk();
});

it('answers a stranger\'s purchase exactly as it answers one that does not exist', function () {
    $user = shopper($this);

    sellTo(STRANGER, purchase());

    // The handle is real and belongs to somebody. The source is handed this
    // shopper's id first and answers null, which is the same answer a handle
    // nobody minted gets and the same answer an empty string gets.
    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])->assertStatus(404);
    Livewire::test(StartReturn::class, ['orderNumber' => 'ORD-000000000000'])->assertStatus(404);
    Livewire::test(StartReturn::class, ['orderNumber' => ''])->assertStatus(404);
    Livewire::test(StartReturnPage::class, ['orderNumber' => ORDER_HANDLE])->assertStatus(404);

    sellTo($user->getKey(), purchase());

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])->assertOk();
    Livewire::test(StartReturnPage::class, ['orderNumber' => ORDER_HANDLE])->assertOk();
});

it('will not let the browser swap either handle it was handed', function () {
    $user = shopper($this);

    $mine = returnFor($user->getKey());
    lineOn($mine);
    $theirs = returnFor(STRANGER);

    // Asserted on the message rather than the class: Livewire has moved that
    // exception between namespaces across majors, and what this is about is the
    // refusal, not where the class lives.
    expect(fn () => Livewire::test(ReturnDetail::class, ['number' => $mine->number])->set('number', $theirs->number))
        ->toThrow(Exception::class, 'Cannot update locked property: [number]');

    sellTo($user->getKey(), purchase());

    expect(fn () => Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])->set('orderNumber', 'ORD-000000000000'))
        ->toThrow(Exception::class, 'Cannot update locked property: [orderNumber]');
});

it('would still find nothing if the lock were removed, because the scope is the control', function () {
    $user = shopper($this);

    $theirs = returnFor(STRANGER);

    // The lock stops the swap being attempted. This is the assertion that the
    // swap would not have worked anyway: the query is narrowed to this customer
    // before the number is applied to it, so a stranger's number resolves to
    // nothing whichever way it arrives — including this way, which is not a way a
    // browser has.
    $component = new ReturnDetail();
    $component->number = $theirs->number;

    expect(fn (): mixed => $component->returnRequest())->toThrow(NotFoundHttpException::class)
        ->and($user->getKey())->not->toBe(STRANGER);
});

it('would still resolve no purchase if the lock were removed', function () {
    $user = shopper($this);

    sellTo(STRANGER, purchase());

    // Same shape, on the surface that writes. The source is handed this shopper's
    // id before the handle is looked at, so setting a stranger's handle directly
    // — which a browser cannot do — still resolves nothing.
    $component = new StartReturn();
    $component->orderNumber = ORDER_HANDLE;

    expect(fn (): mixed => $component->purchase())->toThrow(NotFoundHttpException::class)
        ->and($user->getKey())->not->toBe(STRANGER);
});

it('lists nothing at all for a guest, and runs no query to find that out', function () {
    returnFor(null);
    returnFor(STRANGER);

    // A return with `customer_id` null is nobody's. `where('customer_id', null)`
    // compiles to `is null`, which would list precisely those orphan rows — the
    // leak written as a tautology. Nothing here builds a query without a customer
    // id, so nothing here can build that one.
    Livewire::test(ReturnHistory::class)
        ->assertSee(__('module-ecommerce-returns::returns.sign_in'))
        ->assertDontSee(__('module-ecommerce-returns::returns.history.empty'));

    expect(app(ShopperContext::class)->customerId())->toBeNull();
});

it('shows a guest the sign-in invitation rather than a 404 for a real handle', function () {
    $return = returnFor(STRANGER);
    sellTo(STRANGER, purchase());

    // No lookup runs for a guest, so the answer cannot depend on whether the
    // handle is real — which is the property that matters. A 404 for a real
    // number and a sign-in prompt for a made-up one would be an oracle.
    foreach ([$return->number, 'RMA-000000000000'] as $number) {
        Livewire::test(ReturnDetail::class, ['number' => $number])
            ->assertOk()
            ->assertSee(__('module-ecommerce-returns::returns.sign_in'));
    }

    foreach ([ORDER_HANDLE, 'ORD-000000000000'] as $handle) {
        Livewire::test(StartReturn::class, ['orderNumber' => $handle])
            ->assertOk()
            ->assertSee(__('module-ecommerce-returns::returns.sign_in'));

        Livewire::test(StartReturnPage::class, ['orderNumber' => $handle])->assertOk();
    }
});

it('addresses a return in its markup by the number and never by the row id', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return);

    preg_match_all('/data-return="([^"]+)"/', Livewire::test(ReturnHistory::class)->html(), $handles);

    // The handle a link and a key are built from is the public reference. An
    // incrementing id on a customer-facing page is an enumeration of everybody's
    // returns, which is the entire argument that gave the return a number.
    expect($handles[1])->toBe([$return->number])
        ->and($return->number)->not->toBe((string) $return->id);
});

it('renders nothing about the customer or the merchant it scoped on', function () {
    $user = shopper($this);

    $return = returnFor($user->getKey());
    lineOn($return);

    $history = Livewire::test(ReturnHistory::class)->html();
    $detail = Livewire::test(ReturnDetail::class, ['number' => $return->number])->html();

    expect($history)->not->toContain('customer')
        ->and($detail)->not->toContain('customer')
        ->and($detail)->not->toContain($user->email)
        ->and($detail)->not->toContain((string) MERCHANT_TEAM)
        ->and($detail)->toContain($return->number);
});

it('lets a deployment answer the whole identity question for itself', function () {
    $return = returnFor(STRANGER);
    lineOn($return, 'Contract Coat');

    // A deployment whose customers are not its users — a CRM contact keyed
    // separately from the login — rebinds one class and every read follows,
    // because nothing else in this package asks who the shopper is.
    app()->singleton(ShopperContext::class, fn (): ShopperContext => new class() extends ShopperContext
    {
        public function customerId(): ?int
        {
            return STRANGER;
        }
    });

    Livewire::test(ReturnHistory::class)->assertSee($return->number);
});
