<?php

namespace Liberu\Ecommerce\Returns\Livewire\Pages;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Returns\Livewire\Concerns\ShowsOwnReturns;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The routable page for one return: one `<h1>`, then the return.
 *
 * The route parameter is untrusted input, and this page is the thing that refuses
 * it — not the route definition, and not the component it composes. It resolves
 * the number itself, because a page that renders its heading before its child
 * 404s has already told somebody that a number names something.
 *
 * A signed-out visitor is *not* 404'd. Nothing is looked up for them at all, so
 * the answer cannot depend on whether the number is real; they are shown the
 * invitation to sign in that the page below would show them anyway.
 */
class ReturnPage extends Component
{
    use ShowsOwnReturns;

    /** @see ShowsOwnReturns for why this is locked and why the lock is the second control. */
    #[Locked]
    public string $number = '';

    public function mount(string $number): void
    {
        $this->number = $number;

        if ($this->signedIn() && $this->returnNumbered($number) === null) {
            abort(404);
        }
    }

    #[Title('Your return')]
    public function render(): View
    {
        return view(ReturnsLivewireServiceProvider::NAMESPACE.'::livewire.pages.return');
    }
}
