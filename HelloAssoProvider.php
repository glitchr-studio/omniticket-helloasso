<?php

namespace Omniticket\HelloAsso;

use Omniticket\AttendeeListInterface;
use Omniticket\Capabilities;
use Omniticket\EventCatalogInterface;
use Omniticket\Exception\NotSupportedException;
use Omniticket\Exception\ProviderException;
use Omniticket\Http\JsonApi;
use Omniticket\Model\Attendee;
use Omniticket\Model\AttendeeStatus;
use Omniticket\Model\Check;
use Omniticket\Model\Event;
use Omniticket\Model\EventQuery;
use Omniticket\Model\EventStatus;
use Omniticket\Model\Money;
use Omniticket\Model\Rate;
use Omniticket\Support\Data;
use Omniticket\TicketCheckInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HelloAsso's API v5 (https://dev.helloasso.com): an association's forms of
 * type Event are its events (GET /v5/organizations/{slug}/forms), their
 * tiers its rates (.../forms/Event/{formSlug}/public), the items sold its
 * tickets (.../forms/Event/{formSlug}/items), with a bearer token from
 * POST /oauth2/token (client credentials). An event's id is its form slug.
 * Reading only.
 */
final class HelloAssoProvider implements EventCatalogInterface, AttendeeListInterface, TicketCheckInterface
{
    private const PAGE = 100;

    private readonly JsonApi $api;

    private ?string $token = null;

    private int $expires = 0;

    public function __construct(
        HttpClientInterface $http,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $organization,
        private readonly string $url = 'https://api.helloasso.com',
    ) {
        $this->api = new JsonApi($http, 'HelloAsso');
    }

    public function getName(): string
    {
        return 'helloasso';
    }

    public function getTitle(): string
    {
        return 'HelloAsso';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            catalog: true,
            attendees: true,
            check: true,
            incremental: false,
            widget: true,
            terms: ['https://dev.helloasso.com', 'https://www.helloasso.com/cgu'],
            limits: ['no rate figure in what was read: keep calls moderate, cache the catalogue, renew the token only when it expires (30 minutes)'],
        );
    }

    public function events(EventQuery $query = new EventQuery()): iterable
    {
        return $query->filter($this->paged('/forms', ['formTypes' => 'Event'], 'the events', fn (Data $form) => $this->toEvent($form)));
    }

    public function event(string $id): Event
    {
        $form = $this->get('/forms/Event/'.rawurlencode($id).'/public', [], 'event "'.$id.'"');
        $rates = array_map(static function (Data $tier): Rate {
            $price = $tier->int('price');
            $minimum = $tier->int('minAmount');

            return new Rate(
                (string) $tier->string('id'),
                $tier->string('label') ?? '',
                null !== $price && !(0 === $price && null !== $minimum) ? new Money($price, 'EUR') : null,
                salesStart: $tier->date('saleStartDate'),
                salesEnd: $tier->date('saleEndDate'),
                minimum: null !== $minimum ? new Money($minimum, 'EUR') : null,
            );
        }, $form->list('tiers'));

        return $this->toEvent($form, $rates);
    }

    public function attendees(string $eventId, ?\DateTimeImmutable $updatedSince = null): iterable
    {
        if (null !== $updatedSince) {
            throw NotSupportedException::capability($this, 'helloasso', 'read attendees changed since a moment (its items have no such filter)');
        }

        return $this->paged('/forms/Event/'.rawurlencode($eventId).'/items', ['withDetails' => 'true'], 'the registrations of event "'.$eventId.'"', static fn (Data $item) => new Attendee(
            (string) $item->string('id'),
            $eventId,
            $item->string('qrCode'),
            $item->string('user.firstName'),
            $item->string('user.lastName'),
            $item->string('payer.email'),
            $item->string('name'),
            self::status($item),
            null,
            $item->string('order.id'),
            null,
            $item->string('ticketUrl'),
        ));
    }

    /**
     * HelloAsso has no lookup by code: the event's items are read and the
     * code looked for among their QR codes. Its API, as read, does not say
     * whether a ticket was scanned: a check is never "already used".
     */
    public function check(string $eventId, string $code): Check
    {
        return Check::among($this->attendees($eventId), $code);
    }

    /** @param list<Rate> $rates */
    private function toEvent(Data $form, array $rates = []): Event
    {
        $start = $form->date('startDate');
        $end = $form->date('endDate');
        $salesStart = $form->date('saleStartDate');
        $salesEnd = $form->date('saleEndDate');
        $now = new \DateTimeImmutable();
        $last = $end ?? $start;
        $venue = implode(', ', array_filter([$form->string('place.name'), $form->string('place.city')]));

        return new Event(
            (string) $form->string('formSlug'),
            $form->string('title') ?? '',
            $start,
            $end,
            match (true) {
                'Draft' === $form->string('state') => EventStatus::Draft,
                'Disabled' === $form->string('state') || 'Deleted' === $form->string('state') => EventStatus::SalesClosed,
                null !== $last && $last < $now => EventStatus::Ended,
                null !== $salesStart && $now < $salesStart => EventStatus::Upcoming,
                null !== $salesEnd && $now > $salesEnd => EventStatus::SalesClosed,
                'Public' === $form->string('state') => EventStatus::OnSale,
                default => EventStatus::Unknown,
            },
            $form->string('url'),
            '' !== $venue ? $venue : null,
            rates: $rates,
            widgetUrl: $form->string('widgetFullUrl'),
            provider: 'helloasso',
        );
    }

    private static function status(Data $item): AttendeeStatus
    {
        foreach ($item->list('payments') as $payment) {
            if ('Refunded' === $payment->string('state')) {
                return AttendeeStatus::Refunded;
            }
        }

        return match ($item->string('state')) {
            'Processed', 'Registered' => AttendeeStatus::Valid,
            'Waiting', 'Unknown', null => AttendeeStatus::Pending,
            default => AttendeeStatus::Cancelled,
        };
    }

    /**
     * Every page of a list, by continuation token.
     *
     * @template T
     *
     * @param array<string, string>  $query
     * @param \Closure(Data): T $map
     *
     * @return \Generator<int, T>
     */
    private function paged(string $path, array $query, string $what, \Closure $map): \Generator
    {
        $token = null;
        do {
            $page = $this->get($path, $query + ['pageSize' => (string) self::PAGE] + (null !== $token ? ['continuationToken' => $token] : []), $what);
            $data = $page->list('data');
            foreach ($data as $line) {
                yield $map($line);
            }
            $token = $page->string('pagination.continuationToken');
            $more = \count($data) >= self::PAGE && null !== $token && ($page->int('pagination.pageIndex') ?? 0) < ($page->int('pagination.totalPages') ?? \PHP_INT_MAX);
        } while ($more);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query, string $what): Data
    {
        return $this->api->request('GET', $this->url.'/v5/organizations/'.rawurlencode($this->organization).$path, ['query' => $query, 'auth_bearer' => $this->token()], $what);
    }

    private function token(): string
    {
        if (null === $this->token || time() >= $this->expires) {
            $answer = $this->api->request('POST', $this->url.'/oauth2/token', ['body' => ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]], 'an access token');
            $this->token = $answer->string('access_token') ?? throw new ProviderException('HelloAsso gave no access token.');
            $this->expires = time() + max(60, ($answer->int('expires_in') ?? 1800) - 60);
        }

        return $this->token;
    }
}
