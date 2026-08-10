<?php

return [

    'heading' => 'Your returns',

    'return_heading' => 'Return :number',

    'start_heading' => 'Send something back',

    'loading' => 'Working…',

    // Currency code rather than a symbol. A symbol table is a per-locale problem
    // this package would get wrong, and "GBP 19.99" is never ambiguous about
    // which of the four dollars it means.
    'money' => ':currency :amount',

    'sign_in' => 'Sign in to see your returns.',

    /*
     * The states the domain publishes, and there is one key here for each of
     * them. `tests/Feature/ReturnTest.php` asserts that this list and
     * `Enums\ReturnStatus`'s cases are the **same set** — not that one contains
     * the other, because a missing key renders a raw translation key at the worst
     * possible moment and an extra one is this package inventing a state the
     * domain refused.
     *
     * None of these is a progress word. A return of five can be two received, one
     * rejected on inspection and two still in a van at the same moment; the
     * quantities on each line say that, and no single word can.
     */
    'status' => [
        'requested' => 'Requested',
        'approved' => 'Approved',
        'refused' => 'Declined',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'received' => 'Received',
        'inspected' => 'Checked',
        'resolved' => 'Finished',
    ],

    /*
     * What to do next, one sentence per state, and the reason a state word on its
     * own is not enough.
     *
     * `expired` and `inspected` are why this exists. Both are closed to further
     * goods — one because the window ran out, one because a disposition has been
     * recorded and the arithmetic settled — so a parcel posted now lands against a
     * return that will not take it. Saying "raise a new request" is the difference
     * between an answer and a dead end.
     */
    'next' => [
        'requested' => 'We have your request. You will hear from us when it has been looked at — do not send anything back yet.',
        'approved' => 'Send the items back. We will let you know when they arrive.',
        'refused' => 'We were not able to accept this one. Get in touch if you would like to talk it through.',
        'cancelled' => 'This request was called off, so nothing is expected.',
        'expired' => 'The items did not reach us in time and this request has closed. If you still have them, start a new request.',
        'received' => 'Your items are here and somebody is checking them.',
        'inspected' => 'Everything that arrived has been checked, so this request is closed to further items. If you still have something to send back, start a new request.',
        'resolved' => 'This return is finished.',
    ],

    'history' => [
        'empty' => 'You have not sent anything back yet.',
        'requested_on' => 'Requested :date',
        'items' => '{0}No items|{1}:count item|[2,*]:count items',
        'view' => 'View return :number',
        'more' => 'Show more returns',
        'loaded' => 'Showing :count returns.',
    ],

    /*
     * Three of the domain's five per-line quantities.
     *
     * `restockable` and `rejected` are an inspection disposition — a merchant's
     * judgement about goods they are holding — and stating a verdict on a
     * storefront without the conversation that goes with it is the wrong place
     * for it. The consequence a shopper is entitled to see is the money, and that
     * is rendered from the domain's own sum over the refund rows.
     */
    'line' => [
        'heading' => 'What is going back',
        'sku' => 'Item code',
        'reason' => 'Reason',
        'requested' => 'You asked to send back :count.',
        'approved' => ':count approved.',
        'received' => ':count arrived.',
        'outstanding' => ':count still to send back.',
    ],

    'refund' => [
        'heading' => 'Refunded',
        // No status and no balance. A row exists because money already moved,
        // and this package holds no line prices, so it cannot know what "all of
        // it" would be.
        'total' => ':amount has gone back to you so far.',
    ],

    'cancel' => [
        'heading' => 'Call this off',
        'explain' => 'You can call this request off because nothing has arrived with us yet.',
        'submit' => 'Cancel this request',
        'done' => 'This request has been cancelled.',
        'unavailable' => 'Requests cannot be cancelled here.',
        'too_late' => 'This request is now :status, so it can no longer be called off. Get in touch if something is wrong.',
    ],

    'request' => [
        'heading' => 'Send something back',
        'explain' => 'Choose what you would like to send back and tell us why.',
        'quantity' => 'How many',
        'reason' => 'Why are you sending it back?',
        'reason_placeholder' => 'Choose a reason',
        'note' => 'Anything else we should know? (optional)',
        'note_hint' => 'Up to :limit characters.',
        'submit' => 'Request this return',
        'done' => 'Return :number has been requested. We will let you know when it has been looked at.',
        'unavailable' => 'Returns cannot be requested here. Get in touch and we will sort it out.',
        'nothing' => 'There is nothing left on this order to send back.',
        // The line between the two modules, in words. Calling off something that
        // has not happened is a cancellation, and it belongs to whoever owns the
        // order rather than here.
        'not_delivered' => 'Nothing on this order has been sent yet, so there is nothing to send back. If you no longer want it, you can still cancel the order.',
        'go_to_order' => 'Go to this order',
        'line_unavailable' => 'That item is not one you can send back from this order. Choose one from the list.',
        'quantity_required' => 'Choose how many you would like to send back.',
        'reason_required' => 'Choose a reason, then try again.',
        // Both numbers, and neither is capped. Quietly reducing five to three
        // would tell the shopper one thing and the warehouse another.
        'too_many' => 'You asked to send back :wanted, but only :returnable of this item can still be returned. Change the number and try again.',
        'note_too_long' => 'Please shorten what you have written to :limit characters or fewer. We have not saved it yet.',
    ],

    /*
     * The domain's closed set of seven, spelled out. A merchant groups by these
     * to find the batch that is faulty and the courier that keeps arriving late,
     * and this is a `<select>` rather than a text box because the slug is copied
     * into a domain event and a log line.
     */
    'reason' => [
        'faulty' => 'It is faulty',
        'damaged_in_transit' => 'It arrived damaged',
        'wrong_item_sent' => 'The wrong item was sent',
        'not_as_described' => 'It is not as described',
        'arrived_late' => 'It arrived too late',
        'no_longer_wanted' => 'I no longer want it',
        'better_price_elsewhere' => 'I found it cheaper elsewhere',
    ],

    'help' => 'Get in touch about this return',

];
