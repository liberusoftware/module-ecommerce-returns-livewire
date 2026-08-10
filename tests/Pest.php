<?php

use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableLine;
use Liberu\Ecommerce\Returns\Livewire\Data\ReturnableOrder;
use Liberu\Ecommerce\Returns\Livewire\Support\OrderSource;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;

/*
 * `UsesTestUser`, which brings `RefreshDatabase` with it.
 *
 * Every surface in this package is about "your own returns", and there is no
 * guest half of that question — a return with no customer is nobody's, and a
 * return carries a shopper's own account of what went wrong.
 */
uses(PackageTestCase::class, UsesTestUser::class)->in(__DIR__);

/**
 * A customer id belonging to somebody who is not, and cannot be, the actor.
 *
 * Nine million, and it matters. `TestUser::factory()` issues ids from 1 upwards,
 * and a "stranger's" return filed against id 2 becomes the actor's own the moment
 * a second user is created in the same test — which makes an authorization
 * assertion pass for a reason that has nothing to do with authorization. This
 * range cannot collide with one.
 */
const STRANGER = 9000001;

/** A second stranger, for the same reason. */
const ANOTHER_STRANGER = 9000002;

/** A storefront that is not the one under test, in the same range. */
const OTHER_STORE = 9000003;

/** A team that owns the purchases in this suite. */
const MERCHANT_TEAM = 9000004;

/**
 * Ids nothing in this database has heard of.
 *
 * There is no module that owns orders installed in this test run and there never
 * will be — what crosses the boundary is an identifier, and it has to work with
 * nothing at all behind it. `BoundaryTest` says so on purpose.
 */
const ORDER_ID = 9000101;

const ORDER_LINE_ID = 9000201;

const OTHER_ORDER_LINE_ID = 9000202;

const PRODUCT_ID = 9000301;

/** The purchase's public handle. Never a row id — see `Support\OrderSource`. */
const ORDER_HANDLE = 'ORD-A1B2C3D4E5F6';

/**
 * Point the package at a storefront.
 *
 * Through configuration, because that is the only way a component can receive one:
 * there is no `storeId` mount parameter anywhere in this package, and
 * `IdentityTest` asserts there never will be.
 */
function storefront(?int $storeId = null): void
{
    config()->set('returns-livewire.store_id', $storeId);
}

/** Somebody to be, signed in the way the framework does it. */
function shopper(PackageTestCase $test): TestUser
{
    $user = TestUser::factory()->create();

    $test->actingAs($user);

    return $user;
}

/**
 * A return belonging to somebody.
 *
 * `customer_id` is passed explicitly on every call in this suite. A default of
 * "the signed-in user" would make it possible to write a test about somebody
 * else's return without noticing that it is about your own.
 */
function returnFor(?int $customerId, ?int $storeId = null, ?callable $state = null): ReturnRequest
{
    $factory = ReturnRequest::factory()->forOrder(ORDER_ID);

    if ($state !== null) {
        $factory = $state($factory);
    }

    return $factory->create([
        'customer_id' => $customerId,
        'store_id' => $storeId,
        'team_id' => MERCHANT_TEAM,
        'currency' => 'GBP',
        'currency_exponent' => 2,
    ]);
}

/**
 * A line on a return.
 *
 * The returned model is what a caller holds on to. `$return->lines` is a
 * `hasMany` collection and indexing into one is a test asserting against whatever
 * the database felt like returning.
 */
function lineOn(ReturnRequest $return, string $name = 'Merino Crew', int $quantity = 1, int $orderLineId = ORDER_LINE_ID, ReturnReason $reason = ReturnReason::Faulty): ReturnLine
{
    return ReturnLine::factory()->of($return)->forOrderLine($orderLineId)->requested($quantity)->create([
        'name' => $name,
        'reason' => $reason,
        'returnable_quantity' => 5,
    ]);
}

/**
 * A purchase the host would describe, built out of nothing but values.
 *
 * Every id on it is one nothing in this database has heard of, which is the whole
 * point: this package cannot see a purchase and its suite runs with no module
 * that owns one installed.
 *
 * @param  list<ReturnableLine>|null  $lines
 */
function purchase(?array $lines = null, bool $delivered = true, string $handle = ORDER_HANDLE, ?int $teamId = MERCHANT_TEAM): ReturnableOrder
{
    return new ReturnableOrder(
        orderId: ORDER_ID,
        number: $handle,
        currency: 'GBP',
        lines: $lines ?? [new ReturnableLine(ORDER_LINE_ID, 'Merino Crew', 3, 'GHOST-1', PRODUCT_ID)],
        currencyExponent: 2,
        anythingDelivered: $delivered,
        teamId: $teamId,
    );
}

/**
 * Bind a source that answers for exactly one shopper and one handle.
 *
 * Deliberately written the way the contract demands rather than the way that
 * would be convenient: it returns null for anybody but the customer it was told
 * about, so a test that reaches a stranger's purchase has found a real hole
 * rather than a lenient fixture.
 */
function sellTo(int $customerId, ReturnableOrder $order): void
{
    app()->singleton(OrderSource::class, fn (): OrderSource => new class($customerId, $order) extends OrderSource
    {
        public function __construct(private readonly int $owner, private readonly ReturnableOrder $order) {}

        public function forShopper(int $customerId, string $handle): ?ReturnableOrder
        {
            return $customerId === $this->owner && $handle === $this->order->number ? $this->order : null;
        }
    });
}

/**
 * Every field a component renders has a real label pointing at it.
 *
 * A placeholder is not a label: it disappears on the first keystroke and screen
 * readers are not obliged to read it. This walks the rendered markup rather than
 * trusting a per-view assertion, so a field added later without a label fails here
 * rather than never.
 *
 * Hidden inputs are skipped, because a control nobody can see or focus is not a
 * control anybody labels.
 */
function expectEveryFieldToBeLabelled(string $html): void
{
    preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $html, $fields);

    $visible = array_values(array_filter($fields[0], fn (string $field): bool => preg_match('/type="hidden"/i', $field) !== 1));

    expect($visible)->not->toBeEmpty();

    foreach ($visible as $field) {
        expect($field)->toMatch('/\sid="[^"]+"/');

        preg_match('/\sid="([^"]+)"/', $field, $id);

        expect($html)->toContain('for="'.$id[1].'"');
    }
}
