<?php

namespace Liberu\Ecommerce\Returns\Livewire\Pages;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The routable "your returns" page: one `<h1>`, then the history.
 *
 * It declares no layout. Routes, layouts, navigation and middleware belong to the
 * application composing this package, and a package naming a layout view would
 * only install into an application that happens to have one by that name.
 *
 * It takes no parameters. There is no customer id, store id or return id in a URL
 * anywhere in this package; the list is resolved from the signed-in account on
 * every request.
 *
 * **Put `auth` on this route.** The page works without it — a guest is shown an
 * invitation to sign in and no query runs — but a shopper following a bookmark
 * after their session expired would rather be sent to the sign-in form than to a
 * page telling them to find one.
 */
class ReturnHistoryPage extends Component
{
    public function mount(): void {}

    #[Title('Your returns')]
    public function render(): View
    {
        return view(ReturnsLivewireServiceProvider::NAMESPACE.'::livewire.pages.returns');
    }
}
