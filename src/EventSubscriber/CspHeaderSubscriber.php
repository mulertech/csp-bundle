<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\EventSubscriber;

use MulerTech\CspBundle\Event\BuildCspHeaderEvent;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class CspHeaderSubscriber implements EventSubscriberInterface
{
    /**
     * @param array{url: ?string, route: ?string, route_params: array<string, string>, chance: int} $reportConfig
     */
    public function __construct(
        private readonly CspHeaderBuilder $builder,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly bool $reportOnly,
        private readonly array $reportConfig,
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

        $headerName = $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        if ($event->getResponse()->headers->has($headerName)) {
            return;
        }

        $cspEvent = new BuildCspHeaderEvent($event->getRequest());
        $this->dispatcher->dispatch($cspEvent, BuildCspHeaderEvent::NAME);

        $headerValue = $cspEvent->getHeaderValue() ?? $this->builder->build();

        if ('' !== $headerValue) {
            $event->getResponse()->headers->set($headerName, $headerValue);
        }

        $this->addReportingEndpointsHeader($event, $headerValue);
    }

    private function addReportingEndpointsHeader(ResponseEvent $event, string $headerValue): void
    {
        if (!str_contains($headerValue, 'report-to csp-endpoint')) {
            return;
        }

        $reportUrl = $this->reportConfig['url'] ?? null;

        if (null !== $reportUrl && '' !== $reportUrl) {
            $event->getResponse()->headers->set(
                'Reporting-Endpoints',
                'csp-endpoint="'.$reportUrl.'"',
            );
        }
    }
}
