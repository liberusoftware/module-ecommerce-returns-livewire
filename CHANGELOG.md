# Changelog

All notable changes to `liberusoftware/ecommerce-returns-livewire` are documented
here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-10

First release. Livewire 4 storefront returns for
`liberusoftware/ecommerce-returns`.

### Added

- Six components under one bounded namespace, `module-ecommerce-returns::`:
  `return-history`, `return`, `start-return`, and a routable page for each.
- A shopper's own returns, newest first, with a "show more" that grows a count
  rather than turning a page.
- One return by its RMA number: what is going back, three of the domain's five
  per-line quantities, the state the domain publishes, a sentence saying what to
  do next, and what has been refunded.
- A return request: one order line, a quantity, a reason from the domain's closed
  set of seven slugs, and an optional note.
- Cancellation of a request, offered only where the domain's transition table
  will take one, and refused by the state machine rather than by a copy of its
  rules.
- `Support\ShopperContext` — who the shopper is and which storefront this is,
  swappable by a deployment whose customers are not its users.
- `Support\OrderSource` — what the shopper bought, bound by the host, because
  this package cannot see a purchase and refuses to guess.
- Translations, publishable views and a configuration file, under three separate
  publish tags.

### Security

- Every read and every write begins from the signed-in account.
  `Concerns\ShowsOwnReturns::mine()` is the only place a query starts, and
  `OrderSource::forShopper()` takes the customer id as its first argument.
- A stranger's RMA number, one nobody minted, an empty string and an unbound
  source are all the same 404 with no distinguishing message.
- A signed-out visitor is shown a sign-in prompt rather than a 404, and no query
  runs and no source is consulted, so no response can be used as an oracle for
  which handles exist.
- No incrementing id appears anywhere — not as a property, not as a mount
  parameter, not in a URL, not in the rendered markup. Both handles that travel
  are public references.
- Every public property carries `#[Locked]`, with no exceptions list asserted by
  reflection. Everything a shopper chooses or types arrives as a method argument.
- Nothing is bound to the URL.
- `returnableQuantity` is read on the server from the bound source, again inside
  the write, and is never a property or a form field.
- An order line id is matched by identity against the lines the source returned
  for this shopper; a reason is matched by identity against the domain's enum and
  dropped rather than truncated.
- A shopper's note is accepted, refused rather than truncated when too long, and
  has no path back out — the read model does not carry it, and this package reads
  through the read model.
- No amount, currency or refund control exists on any component. What was
  refunded is the domain's sum over rows.
- Store scoping uses a bound comparison, never `where(…, null)`.

### Notes

- The package ships no `extra.laravel.providers`. Installing it boots nothing;
  the module registry enables it from `module.json`.
- It requires exactly one `liberusoftware/*` package, the domain module it
  presents, and `src/` names no other commerce namespace. The whole suite runs
  with nothing that owns an order, a product, a basket or a checkout installed.
- It registers no event listener. The two places this domain's work becomes
  somebody else's are the host's to subscribe to — see `docs/adoption.md`.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-returns-livewire/releases/tag/0.1.0
