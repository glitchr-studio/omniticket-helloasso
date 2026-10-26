<?php

namespace Omniticket\HelloAsso\Tests;

use Omniticket\HelloAsso\HelloAssoProvider;
use Omniticket\Testing\LoadCheck;
use PHPUnit\Framework\TestCase;

/**
 * omniticket/helloasso with no framework: every class of the package loaded in a
 * PHP process of its own, with nothing from outside but what it requires -
 * omnisense/omniticket and Symfony's HTTP client contracts (interfaces
 * only): no framework, no HTTP client, no application, even in the
 * harness where they are installed beside it.
 */
final class BareTest extends TestCase
{
    private const ALLOWED = ['/vendor/omnisense/omniticket/', '/omniticket/core/', '/vendor/symfony/http-client-contracts/', '/vendor/symfony/service-contracts/'];

    public function testEveryClassLoadsWithTheRequiredPackagesAlone(): void
    {
        $autoload = LoadCheck::autoload(HelloAssoProvider::class, self::class, \dirname(__DIR__)) ?? self::markTestSkipped('omniticket/helloasso is installed in an application here, or no autoloader knows its tests.');
        $report = LoadCheck::run(\dirname(__DIR__), 'Omniticket\\HelloAsso\\', $autoload);

        self::assertContains(HelloAssoProvider::class, $report['loaded']);
        self::assertSame([], $report['foreign'], 'no class of a framework, an HTTP client or an application');
        self::assertSame([], LoadCheck::outside($report['files'], \dirname(__DIR__), self::ALLOWED), 'no file but the package and what it requires');
    }
}
