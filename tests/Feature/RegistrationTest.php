<?php

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Liberu\Ecommerce\Returns\Livewire\Support\OrderSource;
use Liberu\Ecommerce\Returns\Livewire\Support\ShopperContext;
use Livewire\Livewire;

/**
 * The aliases are this package's public interface, so they are asserted by name.
 * Renaming one is a breaking change, and this is where it stops being a silent
 * one.
 *
 * Resolving a `namespace::name` at all is the half that costs an afternoon:
 * Livewire 4's finder answers null for a namespaced name before it consults the
 * explicit registry, so `Livewire::component()` alone would leave every one of
 * these unresolvable while every direct `Livewire::test(SomeClass::class)` carried
 * on passing.
 */
it('resolves every documented component alias by its namespaced name', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    $return = returnFor($user->getKey());
    lineOn($return);

    // Folded into one test rather than a dataset. A dataset row is built before
    // the application boots, so anything in it that touches Eloquent — or, here,
    // a factory — fails with a null connection long before the assertion runs.
    foreach ([
        'return-history' => [],
        'return' => ['number' => $return->number],
        'start-return' => ['orderNumber' => ORDER_HANDLE],
        'returns-page' => [],
        'return-page' => ['number' => $return->number],
        'start-return-page' => ['orderNumber' => ORDER_HANDLE],
    ] as $alias => $parameters) {
        Livewire::test('module-ecommerce-returns::'.$alias, $parameters)->assertOk();
    }
});

it('registers exactly the components it documents and no others', function () {
    expect(array_keys(new ReturnsLivewireServiceProvider(app())->aliases()))->toBe([
        'module-ecommerce-returns::return-history',
        'module-ecommerce-returns::return',
        'module-ecommerce-returns::start-return',
        'module-ecommerce-returns::returns-page',
        'module-ecommerce-returns::return-page',
        'module-ecommerce-returns::start-return-page',
    ]);
});

it('serves its views and translations from its own namespace', function () {
    expect(view()->exists('module-ecommerce-returns::livewire.return-history'))->toBeTrue()
        ->and(view()->exists('module-ecommerce-returns::livewire.return-detail'))->toBeTrue()
        ->and(view()->exists('module-ecommerce-returns::livewire.start-return'))->toBeTrue()
        ->and(view()->exists('module-ecommerce-returns::livewire.pages.returns'))->toBeTrue()
        ->and(view()->exists('module-ecommerce-returns::livewire.pages.return'))->toBeTrue()
        ->and(view()->exists('module-ecommerce-returns::livewire.pages.start-return'))->toBeTrue()
        ->and(__('module-ecommerce-returns::returns.heading'))->toBe('Your returns')
        ->and(__('module-ecommerce-returns::returns.request.submit'))->toBe('Request this return');
});

it('drops the -livewire suffix from the namespace it owns', function () {
    // The namespace names the bounded context, not the technology presenting it —
    // the Filament and API packages for this domain answer to the same ownership
    // prefix.
    expect(ReturnsLivewireServiceProvider::NAMESPACE)->toBe('module-ecommerce-returns');
});

it('boots nothing on install: the module registry is the only thing that enables it', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true);

    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([])
        ->and($composer['extra']['liberu']['name'])->toBe('ecommerce-returns-livewire')
        ->and($manifest['name'])->toBe($composer['extra']['liberu']['name'])
        ->and($manifest['version'])->toBe($composer['version'])
        ->and($manifest['category'])->toBe('presentation');
});

it('publishes its views, translations and configuration under its own tags', function () {
    // A theme overrides a view by publishing it rather than by forking the
    // package, and a deployment that wants its own wording rarely wants its own
    // markup as well — so the three are separate tags.
    $groups = ServiceProvider::$publishGroups;

    expect($groups)->toHaveKeys([
        'module-ecommerce-returns-views',
        'module-ecommerce-returns-translations',
        'module-ecommerce-returns-config',
    ]);
});

it('binds the two questions it cannot answer itself, and lets a deployment replace either', function () {
    // A singleton each, and both swappable. Nothing else in this package asks who
    // the shopper is or what they bought, so replacing one of these replaces the
    // answer everywhere.
    expect(app()->bound(ShopperContext::class))->toBeTrue()
        ->and(app()->bound(OrderSource::class))->toBeTrue()
        // And the default source answers null, so an unconfigured deployment shows
        // a shopper their returns and nothing it would have had to invent.
        ->and(new OrderSource()->forShopper(1, ORDER_HANDLE))->toBeNull();
});
