<?php

namespace Omniticket\HelloAsso;

use Omniticket\Config;
use Omniticket\Exception\InvalidConfigException;
use Omniticket\ProviderFactory;
use Omniticket\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HelloAsso, from its options:
 *
 *   client_id, client_secret  the API client of the association's back office ("Intégrations et API")
 *   organization  the association's slug, as in https://www.helloasso.com/associations/<slug>
 *   url           https://api.helloasso.com (https://api.helloasso-sandbox.com for the sandbox)
 */
final class HelloAssoFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniticket.factory_name' => 'helloasso',
            'omniticket.factory_title' => 'HelloAsso',
            'omniticket.required_options' => ['client_id', 'client_secret', 'organization'],
            'url' => 'https://api.helloasso.com',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new HelloAssoProvider(
            $this->http ?? throw new InvalidConfigException('The "helloasso" provider needs an HTTP client: give one to HelloAssoFactory.'),
            (string) $c->string('client_id'),
            (string) $c->string('client_secret'),
            (string) $c->string('organization'),
            rtrim($c->string('url') ?? 'https://api.helloasso.com', '/'),
        );
    }
}
