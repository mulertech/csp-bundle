<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\EventSubscriber;

use MulerTech\CspBundle\CspNonceGenerator;
use MulerTech\CspBundle\Event\BuildCspHeaderEvent;
use MulerTech\CspBundle\EventSubscriber\CspHeaderSubscriber;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class CspHeaderSubscriberTest extends TestCase
{
    private CspNonceGenerator $nonceGenerator;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->nonceGenerator = new CspNonceGenerator();
        $this->dispatcher = new EventDispatcher();
    }

    public function testSubscribedEvents(): void
    {
        $events = CspHeaderSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::RESPONSE, $events);
        self::assertSame('onKernelResponse', $events[KernelEvents::RESPONSE]);
    }

    public function testSetsHeaderOnMainRequest(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertTrue($event->getResponse()->headers->has('Content-Security-Policy'));
        self::assertSame("default-src 'self'", $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testIgnoresSubRequest(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::SUB_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
    }

    public function testDoesNotOverwriteExistingHeader(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $event->getResponse()->headers->set('Content-Security-Policy', 'existing-value');
        $subscriber->onKernelResponse($event);

        self::assertSame('existing-value', $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testReplacesNoncePlaceholder(): void
    {
        $subscriber = $this->createSubscriber([
            'script-src' => ["'self'", 'nonce(main)'],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $header = $event->getResponse()->headers->get('Content-Security-Policy');
        $nonce = $this->nonceGenerator->getNonce('main');

        self::assertSame("script-src 'self' 'nonce-".$nonce."'", $header);
    }

    public function testBooleanDirectiveTrue(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
            'upgrade-insecure-requests' => true,
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $header = $event->getResponse()->headers->get('Content-Security-Policy');

        self::assertSame("default-src 'self'; upgrade-insecure-requests", $header);
    }

    public function testBooleanDirectiveFalseIsOmitted(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
            'upgrade-insecure-requests' => false,
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $header = $event->getResponse()->headers->get('Content-Security-Policy');

        self::assertSame("default-src 'self'", $header);
    }

    public function testReportOnlyUsesCorrectHeaderName(): void
    {
        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"]],
            reportOnly: true,
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
        self::assertTrue($event->getResponse()->headers->has('Content-Security-Policy-Report-Only'));
        self::assertSame("default-src 'self'", $event->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testEventOverridesBuilderOutput(): void
    {
        $this->dispatcher->addListener(BuildCspHeaderEvent::NAME, static function (BuildCspHeaderEvent $event): void {
            $event->setHeaderValue("default-src 'none'");
        });

        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertSame("default-src 'none'", $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testEventReceivesRequest(): void
    {
        $receivedRequest = null;

        $this->dispatcher->addListener(BuildCspHeaderEvent::NAME, static function (BuildCspHeaderEvent $event) use (&$receivedRequest): void {
            $receivedRequest = $event->getRequest();
        });

        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertSame($event->getRequest(), $receivedRequest);
    }

    public function testAddsReportingEndpointsHeaderWhenReportUrlSet(): void
    {
        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"]],
            reportConfig: ['url' => 'https://report.example.com/csp', 'route' => null, 'route_params' => [], 'chance' => 100],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertTrue($event->getResponse()->headers->has('Reporting-Endpoints'));
        self::assertSame('csp-endpoint="https://report.example.com/csp"', $event->getResponse()->headers->get('Reporting-Endpoints'));
    }

    public function testDoesNotAddReportingEndpointsHeaderWhenNoReportUrl(): void
    {
        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"]],
            reportConfig: ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Reporting-Endpoints'));
    }

    public function testEmptyHeaderValueDoesNotSetHeader(): void
    {
        $subscriber = $this->createSubscriber([]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
    }

    public function testEventWithEmptyHeaderValueDoesNotSetHeader(): void
    {
        $this->dispatcher->addListener(BuildCspHeaderEvent::NAME, static function (BuildCspHeaderEvent $event): void {
            $event->setHeaderValue('');
        });

        $subscriber = $this->createSubscriber(['default-src' => ["'self'"]]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
    }

    /**
     * @param array<string, list<string>|bool>                                                      $directives
     * @param array{url: ?string, route: ?string, route_params: array<string, string>, chance: int} $reportConfig
     */
    private function createSubscriber(
        array $directives,
        bool $reportOnly = false,
        array $reportConfig = ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100],
    ): CspHeaderSubscriber {
        $builder = new CspHeaderBuilder(
            $this->nonceGenerator,
            $directives,
            [],
            $reportConfig,
        );

        $subscriber = new CspHeaderSubscriber($builder, $this->dispatcher, $reportOnly, $reportConfig);
        $this->dispatcher->addSubscriber($subscriber);

        return $subscriber;
    }

    private function createResponseEvent(int $requestType): ResponseEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ResponseEvent($kernel, new Request(), $requestType, new Response());
    }
}
