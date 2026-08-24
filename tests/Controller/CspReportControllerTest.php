<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\Controller;

use MulerTech\CspBundle\Controller\CspReportController;
use MulerTech\CspBundle\Event\CspViolationReportedEvent;
use MulerTech\CspBundle\Report\CspReportParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CspReportControllerTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private CspReportController $controller;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->controller = new CspReportController(new CspReportParser(), $this->dispatcher);
    }

    public function testDispatchesOneEventPerViolation(): void
    {
        $received = [];
        $this->dispatcher->addListener(
            CspViolationReportedEvent::NAME,
            static function (CspViolationReportedEvent $event) use (&$received): void {
                $received[] = $event->getReport()->effectiveDirective;
            },
        );

        $response = $this->controller->__invoke($this->createRequest((string) json_encode([
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'style-src', 'documentURL' => 'https://example.com/']],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'script-src', 'documentURL' => 'https://example.com/']],
        ])));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame(['style-src', 'script-src'], $received);
    }

    public function testHandsTheRequestToListeners(): void
    {
        $received = null;
        $this->dispatcher->addListener(
            CspViolationReportedEvent::NAME,
            static function (CspViolationReportedEvent $event) use (&$received): void {
                $received = $event->getRequest();
            },
        );

        $request = $this->createRequest((string) json_encode([
            'csp-report' => ['document-uri' => 'https://example.com/', 'violated-directive' => 'style-src'],
        ]));
        $this->controller->__invoke($request);

        self::assertSame($request, $received);
    }

    public function testDispatchesNothingWhenEverythingIsNoise(): void
    {
        $dispatched = 0;
        $this->dispatcher->addListener(
            CspViolationReportedEvent::NAME,
            static function () use (&$dispatched): void {
                ++$dispatched;
            },
        );

        $response = $this->controller->__invoke($this->createRequest((string) json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'chrome-extension://abcdefgh/inject.js',
            ],
        ])));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame(0, $dispatched);
    }

    public function testRejectsInvalidJson(): void
    {
        $response = $this->controller->__invoke($this->createRequest('{not json'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRejectsOversizedPayloadWithoutParsingIt(): void
    {
        $payload = str_repeat('a', CspReportController::MAX_BODY_BYTES + 1);

        $response = $this->controller->__invoke($this->createRequest($payload));

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    }

    private function createRequest(string $payload): Request
    {
        return Request::create(
            '/csp-report',
            'POST',
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: $payload,
        );
    }
}
