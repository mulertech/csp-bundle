<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Event;

use MulerTech\CspBundle\Report\CspViolationReport;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per violation received by the collector.
 *
 * The bundle deliberately stops here: where a violation goes, whether it is stored,
 * deduplicated or notified, is the application's decision.
 */
final class CspViolationReportedEvent extends Event
{
    public const string NAME = 'mulertech_csp.violation_reported';

    public function __construct(
        private readonly CspViolationReport $report,
        private readonly Request $request,
    ) {
    }

    public function getReport(): CspViolationReport
    {
        return $this->report;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
