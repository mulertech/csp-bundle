<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Controller;

use MulerTech\CspBundle\Event\CspViolationReportedEvent;
use MulerTech\CspBundle\Report\CspReportParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Receives violation reports and hands each one to the application through an event.
 *
 * The bundle registers the service but declares no route: the endpoint is public and
 * unauthenticated by nature, so opening it stays an explicit gesture of the application,
 * which is also where rate limiting belongs.
 */
final class CspReportController
{
    /**
     * A violation report is a few hundred bytes. Anything larger is not a browser, and the
     * body is read into memory before it can be inspected.
     */
    public const int MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly CspReportParser $parser,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();

        if (strlen($payload) > self::MAX_BODY_BYTES) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $reports = $this->parser->parse($payload);
        } catch (\JsonException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        foreach ($reports as $report) {
            $this->dispatcher->dispatch(new CspViolationReportedEvent($report, $request), CspViolationReportedEvent::NAME);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
