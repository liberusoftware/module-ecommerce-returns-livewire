<?php

namespace Liberu\Ecommerce\Returns\Livewire\Components;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Data\ReturnLineData;
use Liberu\Ecommerce\Returns\Livewire\Concerns\ShowsOwnReturns;
use Liberu\Ecommerce\Returns\Livewire\ReturnsLivewireServiceProvider;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The shopper's own returns, newest first.
 *
 * **There is no identifier on this component at all** — not a customer id, not a
 * store id, not a return id, and no mount parameter for any of them. The list is
 * re-resolved from the signed-in account on every request: a property that is
 * absent cannot be tampered with, and cannot acquire a `#[Url]` next year either.
 *
 * A guest sees an invitation to sign in and **no query runs**. A return with no
 * customer is nobody's, and there is no handle a guest could present that would
 * prove otherwise — see `Support\ShopperContext`.
 */
class ReturnHistory extends Component
{
    use ShowsOwnReturns;

    /**
     * How many returns are on the page.
     *
     * Locked, and grown only by {@see loadMore()}. It is a count rather than a
     * page number because a page number over a list that changes underneath it
     * skips rows: a return raised between two clicks pushes the boundary down,
     * and page two starts one row late. Asking for "the first N" and taking a
     * longer N is stable under an insert at the top.
     *
     * Locked rather than free even though a large number costs only a slow query
     * — a shopper who could set this could set it to a million, and the cheapest
     * denial of service is the one the server was told to perform.
     */
    #[Locked]
    public int $showing = 0;

    /** @var list<ReturnData>|null */
    private ?array $loaded = null;

    private bool $more = false;

    public function mount(): void
    {
        $this->showing = $this->pageSize();
    }

    public function loadMore(): void
    {
        $this->showing += $this->pageSize();
        $this->loaded = null;

        $this->announce($this->say('history.loaded', ['count' => count($this->returns())]));
    }

    /**
     * The returns on the page, as the published read model.
     *
     * A plain method with a per-request cache rather than a `#[Computed]`
     * property: {@see loadMore()} changes the answer inside the request that
     * reads it, and clearing a computed cache needs an `unset($this->…)` that
     * static analysis cannot see.
     *
     * One more row than the page is fetched, and the extra one is what answers
     * {@see hasMore()}. A `count()` query would be a second trip to the database
     * to learn something the first trip could have told us.
     *
     * @return list<ReturnData>
     */
    public function returns(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $query = $this->mine();

        if ($query === null) {
            $this->more = false;

            return $this->loaded = [];
        }

        $records = $query->limit($this->showing + 1)->get();

        $this->more = $records->count() > $this->showing;

        return $this->loaded = $records
            ->take($this->showing)
            ->map(fn (ReturnRequest $return): ReturnData => ReturnData::from($return))
            ->values()
            ->all();
    }

    public function hasMore(): bool
    {
        $this->returns();

        return $this->more;
    }

    /** How many units this return covers, counted off its lines rather than stored. */
    public function requestedQuantity(ReturnData $return): int
    {
        return array_sum(array_map(fn (ReturnLineData $line): int => $line->requestedQuantity, $return->lines));
    }

    public function render(): View
    {
        return view(ReturnsLivewireServiceProvider::NAMESPACE.'::livewire.return-history');
    }

    /** At least one, whatever a deployment puts in the configuration file. */
    private function pageSize(): int
    {
        $size = config('returns-livewire.per_page');

        return is_numeric($size) && (int) $size > 0 ? (int) $size : 10;
    }
}
