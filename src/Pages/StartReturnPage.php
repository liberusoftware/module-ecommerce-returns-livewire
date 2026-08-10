<?php

namespace Liberu\Ecommerce\Returns\Livewire\Pages;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Returns\Livewire\Components\StartReturn;
use Liberu\Ecommerce\Returns\Livewire\Concerns\ShowsOwnReturns;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The routable "send something back" page: one `<h1>`, then the request form.
 *
 * The route parameter is the purchase's public handle, it is untrusted, and this
 * page refuses it rather than leaving that to the component below — for the same
 * reason the return page does: a heading rendered above a child that is about to
 * 404 has already told somebody that a handle names something.
 *
 * A signed-out visitor is *not* 404'd and **no source is consulted for them**, so
 * the answer cannot depend on whether the handle is real.
 */
class StartReturnPage extends Component
{
    use ShowsOwnReturns;

    /** @see StartReturn for why this is locked. */
    #[Locked]
    public string $orderNumber = '';

    public function mount(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;

        $customerId = $this->shopper()->customerId();

        if ($customerId !== null && ($orderNumber === '' || $this->orders()->forShopper($customerId, $orderNumber) === null)) {
            abort(404);
        }
    }

    #[Title('Send something back')]
    public function render(): View
    {
        return view(ReturnsLivewireServiceProvider::NAMESPACE.'::livewire.pages.start-return');
    }
}
