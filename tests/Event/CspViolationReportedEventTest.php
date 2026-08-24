<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\Event;

use MulerTech\CspBundle\Event\CspViolationReportedEvent;
use MulerTech\CspBundle\Report\CspViolationReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CspViolationReportedEventTest extends TestCase
{
    public function testCarriesReportAndRequest(): void
    {
        $report = new CspViolationReport(
            documentUri: 'https://example.com/',
            effectiveDirective: 'style-src',
            blockedUri: 'inline',
            disposition: 'report',
        );
        $request = new Request();

        $event = new CspViolationReportedEvent($report, $request);

        self::assertSame($report, $event->getReport());
        self::assertSame($request, $event->getRequest());
    }

    public function testEventName(): void
    {
        self::assertSame('mulertech_csp.violation_reported', CspViolationReportedEvent::NAME);
    }
}
