# omniticket/helloasso

[omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket)'s HelloAsso, under any PHP
host: an association's event forms with their tiers, the registrations of an event, and a ticket's
QR code checked among them. Reading only.

```php
use Omniticket\HelloAsso\HelloAssoFactory;
use Omniticket\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new HelloAssoFactory(HttpClient::create())], [
    'asso' => ['factory' => 'helloasso', 'options' => ['client_id' => '...', 'client_secret' => '...', 'organization' => 'my-association']],
]);
```

Options: `client_id`, `client_secret` (the association's back office, API section),
`organization` (its slug), `url` (`https://api.helloasso.com`; `https://api.helloasso-sandbox.com`
for the sandbox).

## The API, as read on 2026-10-24

HelloAsso's API v5 (<https://dev.helloasso.com>): a bearer token from `POST /oauth2/token`
(client credentials, 30 minutes, kept until then), `GET /v5/organizations/{slug}/forms?formTypes=Event`
(an event's id is its form slug; `widgetFullUrl` is its embeddable module),
`.../forms/Event/{formSlug}/public` (tiers: prices in cents, a price of 0 with `minAmount` is the
buyer's to choose), `.../forms/Event/{formSlug}/items` (one item per ticket: `qrCode`, `state`,
payments), pages by continuation token. Limits: none stated in what was read - keep calls
moderate and cache the catalogue.

What it cannot say: the items have no "changed since" filter (`updatedSince` throws
`NotSupportedException`; `Capabilities::$incremental` is false), and no scan state - a check is
valid, void or unknown, never "already used". `check()` reads the event's items and looks the code
up among their QR codes.

**Reuse**: HelloAsso publishes a PHP SDK, `helloasso/helloasso-php` (generated from its OpenAPI,
maintained: last update 2026-09-16). It is not wrapped: it is GPL-3.0-or-later (this family is
MIT), it brings its own HTTP stack (Guzzle, ext-curl) where the family injects the application's
client, and its namespace is the generator's generic `OpenAPI\Client`, which collides with any
other generated client. Its model documentation is what the tests' answers follow.

## Verified, and not

- Verified (the core's harness, PHP 8.4): the token (asked once, kept), events and their status,
  one event whole (tiers, free choice, free), registrations (valid, refunded through a payment,
  cancelled), checks, pages by continuation token, a refused client - against answers composed from
  the field names of HelloAsso's OpenAPI (as its SDK documents its models), not recorded; every
  class loading with the package's requirements alone (`BareTest`).
- Not verified live: no HelloAsso account or sandbox was used.

## Licence

MIT. An unofficial client, affiliated with HelloAsso in no way.
