<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The storefront these returns belong to
    |--------------------------------------------------------------------------
    |
    | Returns are store-scoped, like everything in this fleet since wave 1.5. A
    | shopper who buys from two of a merchant's storefronts should see one
    | storefront's returns on that storefront, and null means the deployment has
    | only one.
    |
    | This is never a component property and never a mount parameter. The
    | customer scope already stops a shopper reading somebody else's records, but
    | a value the browser sets is a value the next person to read this code will
    | assume the server chose.
    |
    | A deployment that resolves the storefront per request — from the hostname,
    | say — rebinds `Support\ShopperContext` and overrides `storeId()`. Nothing
    | else in this package reads this key.
    |
    | Note that setting this hides returns whose own `store_id` is null. That is
    | deliberate: a return belonging to no storefront is not this storefront's.
    |
    */

    'store_id' => env('RETURNS_LIVEWIRE_STORE_ID'),

    /*
    |--------------------------------------------------------------------------
    | How many returns are on a page
    |--------------------------------------------------------------------------
    |
    | The history component fetches this many and a "show more" grows the count
    | rather than turning a page. A page number over a list that changes
    | underneath it skips rows — a return raised between two clicks pushes the
    | boundary down and page two starts one row late — and a growing count is
    | stable under an insert at the top.
    |
    */

    'per_page' => 10,

    /*
    |--------------------------------------------------------------------------
    | Requesting a return
    |--------------------------------------------------------------------------
    |
    | This is the one shopper-facing surface in this fleet that causes a domain
    | write, so the switch is here and it is honest about what it does: turning
    | it off removes the form. It changes no rule, because this package holds
    | none — every rule is the domain's, and every number the request is built
    | from is read on the server.
    |
    | A deployment that wants a human to authorise every return before a request
    | even exists turns this off and points `routes.help` at its contact page.
    |
    | `note` is the shopper's own words, and it is the only free-text field this
    | package will ever accept. The domain contains it by rule — it is absent
    | from the read model, from every event and from every log line, each pinned
    | by a test there — which means this package can put text *in* and has no way
    | at all to get it back out. That is the containment, and it is mechanical
    | rather than a promise.
    |
    | Turn it off and the field is not rendered, and anything submitted against
    | it is dropped. `max_length` is refused rather than truncated: silently
    | cutting a fault description in half loses the half that mattered, and the
    | shopper is never told.
    |
    */

    'request' => [

        'enabled' => (bool) env('RETURNS_LIVEWIRE_REQUEST', true),

        'note' => [
            'enabled' => (bool) env('RETURNS_LIVEWIRE_NOTE', true),
            'max_length' => 500,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cancelling a request
    |--------------------------------------------------------------------------
    |
    | Whether a shopper may call off a return they asked for.
    |
    | The control only ever appears where the domain's own state machine will
    | accept the move, which is `requested` and `approved` and nowhere else —
    | once goods have arrived, calling it off is not available any more, and this
    | package does not restate that rule, it asks.
    |
    | There is no reason list here, unlike order cancellation. A shopper pressing
    | "cancel this request" *is* the reason, the history row records that they
    | did it, and a list of slugs would be a second place to keep a fact the
    | actor column already holds.
    |
    */

    'cancellation' => [

        'enabled' => (bool) env('RETURNS_LIVEWIRE_CANCELLATION', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Routes this package links to
    |--------------------------------------------------------------------------
    |
    | Routes belong to the application composing this package, so these are
    | names, and an unregistered name is treated as no link at all — a `#` href
    | is a control that announces itself as a link and then does nothing.
    |
    | There are three, because this package renders exactly three links. A route
    | the host registers for its own navigation does not belong here.
    |
    | `return` receives the return's RMA `number`, and `order` the purchase's own
    | public handle. Neither ever receives a row id: an incrementing id in a
    | customer-facing URL is an enumeration of everybody's records, which is the
    | whole argument that gave both of them a handle.
    |
    | `order` is where a shopper is sent when there is nothing to send back
    | because nothing has been sent to them yet. Calling off something that has
    | not happened is a cancellation, which belongs to whoever owns the order,
    | and pointing at it is the difference between a refusal and an answer.
    |
    | `help` is where a shopper is sent when a return is closed to further goods,
    | or when a request is refused for a reason a page cannot fix.
    |
    */

    'routes' => [

        'return' => env('RETURNS_LIVEWIRE_RETURN_ROUTE'),

        'order' => env('RETURNS_LIVEWIRE_ORDER_ROUTE'),

        'help' => env('RETURNS_LIVEWIRE_HELP_ROUTE'),

    ],

];
