<?php

use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnDetail;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnHistory;
use Liberu\Ecommerce\Returns\Livewire\Components\StartReturn;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Livewire\Livewire;

/*
 * The wave-5 boundary, proved rather than asserted in a README.
 *
 * The domain module this package presents imports nothing from a sibling, and
 * neither does this. Everything that crosses is an identifier or a value already
 * resolved: an order id, an order line id, a quantity, a currency code, an amount
 * in integer minor units.
 *
 * A presentation package is where that rule is most tempting to break, and this
 * one is the most tempting of all: it is a form about a purchase, and a form about
 * a purchase wants to fetch the purchase. It does not. It is handed one.
 */

/** @return array<string, string> every PHP file this package ships, by path. */
function sources(): array
{
    $files = [];

    $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'));

    /** @var SplFileInfo $file */
    foreach ($directory as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('reaches for no sibling ecommerce module', function () {
    $offenders = [];

    foreach (sources() as $path => $source) {
        // Asserted as "every commerce namespace this package mentions is its own"
        // rather than as a list of the ones it may not mention. A test that spells
        // out a forbidden name puts that name in the repository in order to go
        // looking for it, and the grep that enforces this rule cannot tell a
        // prohibition from the thing prohibited — which is how a package fails its
        // own boundary check on a docblock explaining the boundary.
        preg_match_all('/Liberu\\\\Ecommerce\\\\(?!Returns\\\\)([A-Za-z]+)/', $source, $matches);

        foreach ($matches[1] as $module) {
            $offenders[] = basename($path).' → '.$module;
        }
    }

    expect($offenders)->toBe([]);
});

it('never reaches for an application class', function () {
    foreach (sources() as $source) {
        expect($source)->not->toMatch('/(?:use|new|extends|implements)\s+App\\\\/');
    }
});

it('names no payment provider, carrier or catalogue concept anywhere', function () {
    $offenders = [];

    foreach (sources() as $path => $source) {
        // A return records what is coming back and what the merchant decided. It
        // has no tender, no carrier and no product — each belongs to a module that
        // is somebody else's, and a name here would be this package holding an
        // opinion about one of them.
        if (preg_match('/\b(stripe|paypal|braintree|adyen|klarna|dhl|royalmail|tracking_number)\b/i', $source) === 1) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});

it('runs its whole suite with nothing that owns an order, a product or a basket', function () {
    $user = shopper($this);
    sellTo($user->getKey(), purchase());

    Livewire::test(StartReturn::class, ['orderNumber' => ORDER_HANDLE])
        ->call('request', (string) ORDER_LINE_ID, '1', ReturnReason::Faulty->value);

    $line = ReturnLine::query()->sole();

    Livewire::test(ReturnHistory::class)->assertSee($line->returnRequest->number);

    Livewire::test(ReturnDetail::class, ['number' => $line->returnRequest->number])
        ->assertSee('Merino Crew')
        ->assertSee('GHOST-1');

    // Every id in this suite is one nothing in the world has heard of, and this
    // is the assertion that says so on purpose: there are no orders, no order
    // lines, no products, no carts and no checkout sessions anywhere in this test
    // run, and a return is still raised, listed and rendered.
    expect(Schema::hasTable('orders'))->toBeFalse()
        ->and(Schema::hasTable('ecommerce_orders_orders'))->toBeFalse()
        ->and(Schema::hasTable('products'))->toBeFalse()
        ->and(Schema::hasTable('carts'))->toBeFalse()
        ->and($line->order_line_id)->toBe(ORDER_LINE_ID)
        ->and($line->product_id)->toBe(PRODUCT_ID);
});

it('subscribes to nothing, because the listeners that cross belong to the host', function () {
    $offenders = [];

    foreach (sources() as $path => $source) {
        // Two of the domain's events have to become somebody else's work, and both
        // of those listeners are the host's — it is the only party entitled to
        // know that two modules exist. Written as "this package registers no
        // subscription at all" rather than by naming the call it must not make.
        if (preg_match('/Event::(listen|subscribe)/', $source) === 1) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});

it('ships no laravel provider discovery', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    // Composer install must boot nothing. The module registry boots this package
    // from `module.json` when a deployment names it in `MODULES_ENABLED`, and
    // installing a module is not enabling it.
    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([]);
});

it('declares in its manifest exactly the sibling packages Composer requires', function () {
    $root = dirname(__DIR__, 2);

    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
    $manifest = json_decode((string) file_get_contents($root.'/module.json'), true);

    $required = array_filter(
        $composer['require'],
        fn (string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($manifest['requires']['packages'])->toBe($required)
        ->and($required)->toBe(['liberusoftware/ecommerce-returns' => '^0.1'])
        // The same package in `require-dev` too, because a package's own CI is
        // the one place its `repositories` entry is honoured — Composer reads
        // that only from the root manifest, and here this package is root.
        ->and($composer['require-dev'])->toHaveKey('liberusoftware/ecommerce-returns');
});

it('registers a provider that boots without the host application', function () {
    expect(app()->getProviders(ReturnsLivewireServiceProvider::class))->not->toBeEmpty()
        ->and(ReturnsLivewireServiceProvider::NAMESPACE)->toBe('module-ecommerce-returns');
});
