{{-- The page owns the <h1>, so the heading list is the page outline: one h1,
     then the return at h2 and its sections at h3.

     The page has already resolved the number in `mount()` and 404'd if it names
     nothing of this shopper's, so this heading is never rendered above a child
     that is about to refuse — a page that draws its heading first has already
     told somebody that a number names something. --}}
<div>
    <h1>{{ __('module-ecommerce-returns::returns.heading') }}</h1>

    <livewire:module-ecommerce-returns::return :number="$number" />
</div>
