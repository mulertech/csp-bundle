<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\EventSubscriber;

use MulerTech\CspBundle\CspNonceGenerator;
use MulerTech\CspBundle\EventSubscriber\CspHeaderSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class CspHeaderSubscriberTest extends TestCase
{
    private CspNonceGenerator $nonceGenerator;

    protected function setUp(): void
    {
        $this->nonceGenerator = new CspNonceGenerator();
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
            'default-src' => "'self'",
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertTrue($event->getResponse()->headers->has('Content-Security-Policy'));
        self::assertSame("default-src 'self'", $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testIgnoresSubRequest(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => "'self'",
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::SUB_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
    }

    public function testDoesNotOverwriteExistingHeader(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => "'self'",
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $event->getResponse()->headers->set('Content-Security-Policy', 'existing-value');
        $subscriber->onKernelResponse($event);

        self::assertSame('existing-value', $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testReplacesNoncePlaceholder(): void
    {
        $subscriber = $this->createSubscriber([
            'script-src' => "'self' 'nonce-{nonce}'",
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $header = $event->getResponse()->headers->get('Content-Security-Policy');
        $nonce = $this->nonceGenerator->getNonce();

        self::assertSame("script-src 'self' 'nonce-".$nonce."'", $header);
    }

    public function testNullDirectivesAreOmitted(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => "'self'",
            'report-uri' => null,
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $header = $event->getResponse()->headers->get('Content-Security-Policy');

        self::assertSame("default-src 'self'", $header);
    }

    public function testUpgradeInsecureRequestsBoolean(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => "'self'",
            'upgrade-insecure-requests' => true,
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $header = $event->getResponse()->headers->get('Content-Security-Policy');

        self::assertSame("default-src 'self'; upgrade-insecure-requests", $header);
    }

    public function testUpgradeInsecureRequestsFalseIsOmitted(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => "'self'",
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
            ['default-src' => "'self'"],
            reportOnly: true,
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
        self::assertTrue($event->getResponse()->headers->has('Content-Security-Policy-Report-Only'));
        self::assertSame("default-src 'self'", $event->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * @param array<string, string|bool|null> $directives
     */
    private function createSubscriber(array $directives, bool $reportOnly = false): CspHeaderSubscriber
    {
        return new CspHeaderSubscriber($this->nonceGenerator, $directives, $reportOnly);
    }

    private function createResponseEvent(int $requestType): ResponseEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ResponseEvent($kernel, new Request(), $requestType, new Response());
    }
}
