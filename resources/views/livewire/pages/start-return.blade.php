{{-- The page owns the <h1> and has already resolved the purchase in `mount()`,
     so this heading is never drawn above a child that is about to 404.

     A signed-out visitor is not 404'd and no source is consulted for them, so the
     answer cannot depend on whether the handle is real. --}}
<div>
    <h1>{{ __('module-ecommerce-returns::returns.start_heading') }}</h1>

    <livewire:module-ecommerce-returns::start-return :orderNumber="$orderNumber" />
</div>
