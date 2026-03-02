<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\Event;

use MulerTech\CspBundle\Event\BuildCspHeaderEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BuildCspHeaderEventTest extends TestCase
{
    public function testEventName(): void
    {
        self::assertSame('mulertech_csp.build_header', BuildCspHeaderEvent::NAME);
    }

    public function testGetRequest(): void
    {
        $request = Request::create('/test');
        $event = new BuildCspHeaderEvent($request);

        self::assertSame($request, $event->getRequest());
    }

    public function testHeaderValueIsNullByDefault(): void
    {
        $event = new BuildCspHeaderEvent(Request::create('/'));

        self::assertNull($event->getHeaderValue());
    }

    public function testSetAndGetHeaderValue(): void
    {
        $event = new BuildCspHeaderEvent(Request::create('/'));

        $event->setHeaderValue("default-src 'self'");

        self::assertSame("default-src 'self'", $event->getHeaderValue());
    }
}
