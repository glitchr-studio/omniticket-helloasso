# omniticket/helloasso

HelloAsso for [omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket): an
association's events - its forms of type Event - their tiers, their registrations, and a ticket
code checked - through HelloAsso's API v5 (`https://api.helloasso.com`, documented at
`https://dev.helloasso.com`; the sandbox `https://api.helloasso-sandbox.com`), with a bearer token
from client credentials. An event's id is its form's slug. Reading only.

| Operation | HelloAsso API v5 |
|---|---|
| token | `POST /oauth2/token` (client credentials) |
| `events()` | `GET /v5/organizations/{slug}/forms?formTypes=Event` |
| `event($formSlug)` | `GET /v5/organizations/{slug}/forms/Event/{formSlug}/public` |
| `attendees($formSlug)` | `GET /v5/organizations/{slug}/forms/Event/{formSlug}/items?withDetails=true` |
| `check($formSlug, $code)` | the items, read whole |

Lists come in pages of 100 by continuation token.

HelloAsso publishes a PHP SDK, `helloasso/helloasso-php`; it is not wrapped: it is
GPL-3.0-or-later (this family is MIT), it brings its own HTTP stack where the family injects the
application's client, and its generic `OpenAPI\Client` namespace collides with other generated
clients. Its model documentation is what the tests' answers follow.

## Installation

```sh
composer require omniticket/helloasso symfony/http-client
```

Requires PHP 8.2, `omnisense/omniticket` (1.x) and `symfony/http-client-contracts` (^3.0).

## Credentials

The **API client** (client id and secret) of the association's back office, "Intégrations et
API", and the association's **slug**, as in `https://www.helloasso.com/associations/<slug>`.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | |
| `client_secret` | string | - | yes | |
| `organization` | string | - | yes | the association's slug |
| `url` | string | `https://api.helloasso.com` | no | the sandbox: `https://api.helloasso-sandbox.com` |

## Capabilities

| Contract | Supported |
|---|---|
| `EventCatalogInterface` | yes |
| `AttendeeListInterface` | yes, not incremental: `$updatedSince` is a `NotSupportedException` |
| `TicketCheckInterface` | yes, among the items; never "already used" |

`capabilities()`: catalog, attendees, check and widget true, incremental false; terms
`https://dev.helloasso.com`, `https://www.helloasso.com/cgu`; limits: no rate figure in what was
read: keep calls moderate, cache the catalogue, renew the token only when it expires (30 minutes).

## Behaviour

**Token.** Asked for on the first call (a form with `grant_type=client_credentials`, the id and
the secret), kept until a minute before `expires_in` (1800 seconds when not said; at least 60).
No token in the answer: `ProviderException` `HelloAsso gave no access token.`

**Events.** Each form: `formSlug` as the id, `title`, `startDate`, `endDate`, `url`,
`widgetFullUrl` as the widget, the place's name and city. Status: `Draft` draft; `Disabled`,
`Deleted` sales closed; ended when its end (else start) is past; upcoming before `saleStartDate`;
sales closed after `saleEndDate`; `Public` on sale; else unknown. No places left.

**One event.** The public form, its tiers as rates: `id`, `label`, the price in EUR cents - a
price of 0 with a `minAmount` is the buyer's to choose: no price, `minAmount` as the minimum -,
`saleStartDate`, `saleEndDate`.

**Attendees.** One item per ticket: `id`, `qrCode` as the code, the user's first and last names,
the payer's e-mail, `name` as the rate, `order.id`, `ticketUrl`. Status: a payment `Refunded` -
refunded; `Processed`, `Registered` - valid; `Waiting`, `Unknown` or none - pending; anything else
- cancelled. HelloAsso's API, as read, does not say whether a ticket was scanned.

**Check.** The event's items read, the code looked for among their QR codes: valid, void or
unknown, never already used.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omniticket\HelloAsso\HelloAssoFactory;
use Omniticket\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new HelloAssoFactory(HttpClient::create(['timeout' => 15]))], [
    'asso' => ['factory' => 'helloasso', 'options' => [
        'client_id' => getenv('HELLOASSO_CLIENT_ID'),
        'client_secret' => getenv('HELLOASSO_CLIENT_SECRET'),
        'organization' => 'my-association',
    ]],
]);

foreach ($registry->catalog('asso')->events() as $event) {
    echo $event->title, ': ', $event->widgetUrl, "\n";     // the module to put in a frame
}
```

Not run live (no account). The incremental refusal was run on 2026-10-26: `The "helloasso"
provider (Omniticket\HelloAsso\HelloAssoProvider) does not read attendees changed since a moment
(its items have no such filter).`

## Errors

- 401, 403 (the client refused, at the token or a call): `InvalidKeyException`; 404:
  `NotFoundException`; another status of 400 or more, or an answer that is not JSON:
  `ProviderException`, the status as its code.
- `attendees()` with a date: `NotSupportedException`, before any call.
- A network failure: `UnreachableException`.

## Verified, and not

`Tests/HelloAssoProviderTest.php`, against answers composed from the field names of HelloAsso's
OpenAPI (as its SDK documents its models), not recorded: the token (asked once, kept), events and
their status, one event whole (tiers, free choice, free), registrations (valid, refunded through a
payment, cancelled), checks, pages by continuation token, a refused client; `BareTest`. Green in
the family's harness on 2026-10-26. Not run live: no HelloAsso account or sandbox was used.

## Responsibility

This package is an independent, unofficial client, affiliated with HelloAsso in no way. Whoever
runs it keeps HelloAsso's terms ([helloasso.com/cgu](https://www.helloasso.com/cgu),
[dev.helloasso.com](https://dev.helloasso.com)); see the README.
