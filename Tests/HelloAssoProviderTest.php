<?php

namespace Omniticket\HelloAsso\Tests;

use Omniticket\Exception\InvalidKeyException;
use Omniticket\Exception\NotFoundException;
use Omniticket\Exception\NotSupportedException;
use Omniticket\HelloAsso\HelloAssoFactory;
use Omniticket\HelloAsso\HelloAssoProvider;
use Omniticket\Model\Attendee;
use Omniticket\Model\CheckResult;
use Omniticket\Model\Event;
use Omniticket\Model\Rate;
use Omniticket\Registry;
use Omniticket\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * HelloAsso's answers are composed for these tests from the field names of
 * its API v5 (its OpenAPI description, as HelloAsso's generated PHP SDK
 * documents its models: FormLightModel, FormPublicModel, TierPublicModel,
 * Item, PaginationModel - read on 2026-10-24); they were not recorded from
 * an account. Every identifier is an example.
 */
final class HelloAssoProviderTest extends TestCase
{
    public function testEvents(): void
    {
        $http = self::http();
        $events = [...self::provider($http)->events()];

        self::assertSame([['bal-de-printemps', 'Bal de printemps', 'on_sale'], ['kermesse', 'Kermesse', 'draft']], array_map(static fn (Event $e) => [$e->id, $e->title, $e->status->value], $events));
        self::assertSame('https://www.helloasso.com/associations/example-association/evenements/bal-de-printemps/widget', $events[0]->widgetUrl);
        self::assertSame(['POST', 'https://api.helloasso.com/oauth2/token'], [$http->requests[0]['method'], $http->requests[0]['url']]);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'example-client', 'client_secret' => 'example-secret-not-real'], $http->requests[0]['options']['body']);
        self::assertSame('https://api.helloasso.com/v5/organizations/example-association/forms', $http->requests[1]['url']);
        self::assertSame(['formTypes' => 'Event', 'pageSize' => '100'], $http->requests[1]['options']['query']);
        self::assertSame('example-access-token-not-real', $http->requests[1]['options']['auth_bearer']);
        self::assertCount(2, $http->requests, 'one page (2 forms < 100): no second call');
    }

    public function testAnEventWhole(): void
    {
        $event = self::provider(self::http())->event('bal-de-printemps');

        self::assertSame(['Bal de printemps', 'Salle des fêtes, Colmar', '2099-04-18T20:00:00+02:00'], [$event->title, $event->venue, $event->start?->format(\DATE_ATOM)]);
        self::assertSame([['1001', 'Adulte', '15.00', null], ['1002', 'Prix libre', null, '5.00'], ['1003', 'Enfant', '0.00', null]], array_map(static fn (Rate $r) => [$r->id, $r->label, $r->price?->major(), $r->minimum?->major()], $event->rates), 'a price of 0 with a minimum is the buyer\'s to choose; without one, free');
        self::assertSame('0.00', $event->lowestPrice()?->major());
    }

    public function testRegistrationsAndChecks(): void
    {
        $provider = self::provider(self::http());
        $attendees = [...$provider->attendees('bal-de-printemps')];

        self::assertSame([['60001', 'EXAMPLE-QR-0001', 'Jeanne Dupont', 'jeanne@example.org', 'valid'], ['60002', 'EXAMPLE-QR-0002', 'Paul Martin', 'paul@example.org', 'refunded'], ['60003', 'EXAMPLE-QR-0003', 'Ana Lima', 'ana@example.org', 'cancelled']], array_map(static fn (Attendee $a) => [$a->id, $a->code, $a->name(), $a->email, $a->status->value], $attendees));
        self::assertSame([CheckResult::Valid, CheckResult::Void, CheckResult::Void, CheckResult::Unknown], [$provider->check('bal-de-printemps', 'EXAMPLE-QR-0001')->result, $provider->check('bal-de-printemps', 'EXAMPLE-QR-0002')->result, $provider->check('bal-de-printemps', 'EXAMPLE-QR-0003')->result, $provider->check('bal-de-printemps', 'nope')->result]);

        $this->expectException(NotSupportedException::class);
        [...$provider->attendees('bal-de-printemps', new \DateTimeImmutable())];
    }

    public function testTheTokenIsKeptUntilItExpires(): void
    {
        $http = self::http();
        $provider = self::provider($http);
        [...$provider->events()];
        $provider->event('bal-de-printemps');

        self::assertCount(1, array_filter($http->requests, static fn (array $r) => str_ends_with($r['url'], '/oauth2/token')));
    }

    public function testPagesFollowTheContinuationToken(): void
    {
        $page = static fn (int $from, int $count, int $index): string => (string) json_encode(['data' => array_map(static fn (int $i) => ['id' => $i, 'qrCode' => 'Q'.$i, 'state' => 'Processed'], range($from, $from + $count - 1)), 'pagination' => ['pageSize' => 100, 'pageIndex' => $index, 'totalPages' => 2, 'continuationToken' => 'next-'.$index]]);
        $tokens = [];
        $http = new RecordedHttpClient(static function (string $method, string $url, array $options) use ($page, &$tokens): array {
            if (str_ends_with($url, '/oauth2/token')) {
                return [200, (string) file_get_contents(__DIR__.'/Fixtures/token.json')];
            }
            $query = \is_array($options['query'] ?? null) ? $options['query'] : [];
            $tokens[] = $query['continuationToken'] ?? null;

            return [200, isset($query['continuationToken']) ? $page(101, 20, 2) : $page(1, 100, 1)];
        });

        self::assertCount(120, [...self::provider($http)->attendees('x')]);
        self::assertSame([null, 'next-1'], $tokens);
    }

    public function testFailures(): void
    {
        try {
            [...self::provider(RecordedHttpClient::routes(['POST /oauth2/token' => [401, '{"error":"unauthorized_client","error_description":"Invalid client"}']]))->events()];
            self::fail('Refused.');
        } catch (InvalidKeyException $e) {
            self::assertSame('HelloAsso refused the credentials (401) for an access token: Invalid client', $e->getMessage());
        }
        $this->expectException(NotFoundException::class);
        self::provider(self::http())->event('nothing-here');
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new HelloAssoFactory(self::http())], ['asso' => ['factory' => 'helloasso', 'options' => ['client_id' => 'example-client', 'client_secret' => 'example-secret-not-real', 'organization' => 'example-association']]]);

        self::assertSame(['catalog', 'attendees', 'check'], $registry->get('asso')->capabilities()->contracts());
        self::assertSame('bal-de-printemps', [...$registry->catalog('asso')->events()][0]->id);
    }

    private static function provider(RecordedHttpClient $http): HelloAssoProvider
    {
        return new HelloAssoProvider($http, 'example-client', 'example-secret-not-real', 'example-association');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            'POST /oauth2/token' => self::fixture('token'),
            'GET /organizations/example-association/forms' => self::fixture('forms'),
            'GET /forms/Event/bal-de-printemps/public' => self::fixture('form-public'),
            'GET /forms/Event/bal-de-printemps/items' => self::fixture('items'),
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');
    }
}
