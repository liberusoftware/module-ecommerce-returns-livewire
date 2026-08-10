<?php

namespace Liberu\Ecommerce\Returns\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnDetail;
use Liberu\Ecommerce\Returns\Livewire\Components\ReturnHistory;
use Liberu\Ecommerce\Returns\Livewire\Components\StartReturn;
use Liberu\Ecommerce\Returns\Livewire\Pages\ReturnHistoryPage;
use Liberu\Ecommerce\Returns\Livewire\Pages\ReturnPage;
use Liberu\Ecommerce\Returns\Livewire\Pages\StartReturnPage;
use Liberu\Ecommerce\Returns\Livewire\Support\OrderSource;
use Liberu\Ecommerce\Returns\Livewire\Support\ShopperContext;
use Livewire\Livewire;

/**
 * Registers this package's bounded Livewire namespace and the two classes that
 * answer who the shopper is and what they bought.
 *
 * Registered by `ModuleManagerServiceProvider` from `module.json`, never by
 * Composer discovery — the package ships no `extra.laravel.providers`, so
 * installing it boots nothing until a deployment names the module in
 * `MODULES_ENABLED`.
 *
 * **Nothing here subscribes to anything.** The domain module this package
 * presents publishes five events, and the two that have to become somebody
 * else's work — goods coming back, and an inspection saying what is saleable —
 * are the **host's** to subscribe to, because the host is the only party
 * entitled to know that two modules exist. A presentation package registering
 * one of those listeners would be a second place that decision lives, and the
 * first time it would show up is a counter moved twice.
 *
 * `BoundaryTest` reads every file in `src/` and asserts that every commerce
 * namespace any of them mentions is this one.
 *
 * Aliases are explicit rather than discovered. A directory scan resolves whatever
 * happens to be on disk, so moving a class or adding one would silently change a
 * public interface; this list *is* the interface, and changing it is a diff
 * somebody reviews.
 */
class ReturnsLivewireServiceProvider extends ServiceProvider
{
    /**
     * The one namespace this package owns, for components, views and
     * translations alike. It drops the `-livewire` suffix and keeps the
     * ownership prefix: it names the bounded context, not the technology
     * presenting it, and the Filament and API packages for this domain answer to
     * the same one.
     */
    public const NAMESPACE = 'module-ecommerce-returns';

    /**
     * The package's public component surface.
     *
     * @var array<string, class-string>
     */
    private const COMPONENTS = [
        'return-history' => ReturnHistory::class,
        'return' => ReturnDetail::class,
        'start-return' => StartReturn::class,
        'returns-page' => ReturnHistoryPage::class,
        'return-page' => ReturnPage::class,
        'start-return-page' => StartReturnPage::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/returns-livewire.php', 'returns-livewire');

        // Two singletons, and both swappable. A deployment whose customers are
        // not its users rebinds the first and every read in the package follows;
        // a deployment binds the second to tell this package what a shopper
        // bought, because this package cannot see a purchase and refuses to
        // guess. Nothing else here asks either question.
        $this->app->singleton(ShopperContext::class);
        $this->app->singleton(OrderSource::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::NAMESPACE);
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', self::NAMESPACE);

        $aliases = $this->aliases();

        // Two halves of the same registration, and both are needed.
        //
        // `component()` is the name a class reports as — what a rendered
        // component calls itself, and what `Livewire::test(SomeClass::class)`
        // resolves back to.
        //
        // `resolveMissingComponent()` is the other direction, and it is the one
        // that costs an afternoon if it is missing. Livewire 4's
        // `Finder::resolveClassComponentClassName()` returns null for a
        // `namespace::name` *before* it consults the explicit registry, so
        // `component()` alone never answers one. `addNamespace()` does answer,
        // but it maps one Livewire namespace onto exactly one class namespace —
        // and this package deliberately has two, `Components\` and `Pages\`,
        // because a reusable component and a routable page are different things.
        foreach ($aliases as $alias => $component) {
            Livewire::component($alias, $component);
        }

        Livewire::resolveMissingComponent(
            static fn (string $name): ?string => $aliases[$name] ?? null,
        );

        // Publishing views is how a theme overrides one without forking the
        // package. Translations publish separately, because a deployment that
        // wants its own wording rarely wants its own markup as well.
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-translations');

        $this->publishes([
            __DIR__.'/../config/returns-livewire.php' => config_path('returns-livewire.php'),
        ], self::NAMESPACE.'-config');
    }

    /**
     * The component table, keyed by the fully qualified alias.
     *
     * @return array<string, class-string>
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach (self::COMPONENTS as $alias => $component) {
            $aliases[self::NAMESPACE.'::'.$alias] = $component;
        }

        return $aliases;
    }
}
