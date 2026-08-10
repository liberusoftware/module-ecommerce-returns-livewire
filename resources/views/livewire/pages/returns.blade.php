{{-- A routable page starts at <h1>; the component below starts at <h2>, so the
     heading list is the page outline. The page declares no layout: routes,
     layouts, navigation and middleware belong to the application composing this
     package.

     It takes no parameters. There is no customer id, store id or return id in a
     URL anywhere in this package. --}}
<div>
    <h1>{{ __('module-ecommerce-returns::returns.heading') }}</h1>

    <livewire:module-ecommerce-returns::return-history />
</div>
