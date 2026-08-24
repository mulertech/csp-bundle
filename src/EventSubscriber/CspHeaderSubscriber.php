<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\EventSubscriber;

use MulerTech\CspBundle\Event\BuildCspHeaderEvent;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class CspHeaderSubscriber implements EventSubscriberInterface
{
    private const string ENFORCE_HEADER = 'Content-Security-Policy';
    private const string REPORT_ONLY_HEADER = 'Content-Security-Policy-Report-Only';

    /**
     * @param array<string, list<string>|bool> $candidateDirectives
     */
    public function __construct(
        private readonly CspHeaderBuilder $builder,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly bool $reportOnly,
        private readonly array $candidateDirectives = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headerName = $this->reportOnly ? self::REPORT_ONLY_HEADER : self::ENFORCE_HEADER;

        if ($response->headers->has($headerName)) {
            return;
        }

        $cspEvent = new BuildCspHeaderEvent($event->getRequest());
        $this->dispatcher->dispatch($cspEvent, BuildCspHeaderEvent::NAME);

        $overridden = $cspEvent->getHeaderValue();
        $withReporting = $this->builder->shouldReport();
        $headerValue = $overridden ?? $this->builder->build(withReporting: $withReporting);

        if ('' !== $headerValue) {
            $response->headers->set($headerName, $headerValue);
        }

        $candidateValue = $this->addCandidateHeader($response, $overridden, $withReporting);

        $this->addReportingEndpointsHeader($response, $headerValue.' '.$candidateValue);
    }

    /**
     * Emits the candidate policy as report-only alongside the enforced one, so a stricter
     * policy is measured against real traffic before it blocks anything. The candidate is
     * expressed as a diff of the configured policy, so a listener replacing that policy
     * wholesale leaves nothing coherent to diff against and the candidate stands down.
     */
    private function addCandidateHeader(Response $response, ?string $overridden, bool $withReporting): string
    {
        if ([] === $this->candidateDirectives || null !== $overridden || $this->reportOnly) {
            return '';
        }

        if ($response->headers->has(self::REPORT_ONLY_HEADER)) {
            return '';
        }

        $value = $this->builder->build($this->candidateDirectives, withReporting: $withReporting);

        if ('' !== $value) {
            $response->headers->set(self::REPORT_ONLY_HEADER, $value);
        }

        return $value;
    }

    private function addReportingEndpointsHeader(Response $response, string $policies): void
    {
        if (!str_contains($policies, 'report-to csp-endpoint')) {
            return;
        }

        $reportUrl = $this->builder->getReportUrl();

        if (null !== $reportUrl && '' !== $reportUrl) {
            $response->headers->set('Reporting-Endpoints', 'csp-endpoint="'.$reportUrl.'"');
        }
    }
}
