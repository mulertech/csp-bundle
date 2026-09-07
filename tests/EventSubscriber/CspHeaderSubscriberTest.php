<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\EventSubscriber;

use MulerTech\CspBundle\CspNonceGenerator;
use MulerTech\CspBundle\Event\BuildCspHeaderEvent;
use MulerTech\CspBundle\EventSubscriber\CspHeaderSubscriber;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
            reportConfig: ['url' => 'https://report.example.com/csp', 'route' => null, 'route_params' => [], 'chance' => 100, 'markers' => ['report-uri', 'report-to']],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertTrue($event->getResponse()->headers->has('Reporting-Endpoints'));
        self::assertSame('csp-endpoint="https://report.example.com/csp"', $event->getResponse()->headers->get('Reporting-Endpoints'));
    }

    public function testAddsReportingEndpointsHeaderWhenReportRouteSet(): void
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/csp-report');

        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"]],
            reportConfig: ['url' => null, 'route' => 'app_csp_report', 'route_params' => [], 'chance' => 100, 'markers' => ['report-uri', 'report-to']],
            urlGenerator: $urlGenerator,
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertStringContainsString('report-to csp-endpoint', (string) $event->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('csp-endpoint="https://example.com/csp-report"', $event->getResponse()->headers->get('Reporting-Endpoints'));
    }

    public function testDoesNotAddReportingEndpointsHeaderWhenNoReportUrl(): void
    {
        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"]],
            reportConfig: ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100, 'markers' => ['report-uri', 'report-to']],
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

    public function testNoReportingEndpointsHeaderWithoutTheReportToMarker(): void
    {
        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"]],
            reportConfig: [
                'url' => 'https://report.example.com/csp',
                'route' => null,
                'route_params' => [],
                'chance' => 100,
                'markers' => ['report-uri'],
            ],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $headers = $event->getResponse()->headers;

        self::assertStringContainsString('report-uri https://report.example.com/csp', (string) $headers->get('Content-Security-Policy'));
        // The header only defines the group that report-to names: without that marker it has no purpose.
        self::assertFalse($headers->has('Reporting-Endpoints'));
    }

    public function testCandidatePolicyRidesAlongAsReportOnly(): void
    {
        $subscriber = $this->createSubscriber(
            ['style-src' => ["'self'", "'unsafe-inline'"]],
            candidateDirectives: ['style-src' => ["'self'"]],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $headers = $event->getResponse()->headers;

        self::assertSame("style-src 'self' 'unsafe-inline'", $headers->get('Content-Security-Policy'));
        self::assertSame("style-src 'self'", $headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testCandidatePolicySharesTheNonceOfTheEnforcedPolicy(): void
    {
        $subscriber = $this->createSubscriber(
            ['script-src' => ["'self'", 'nonce(main)']],
            candidateDirectives: ['script-src' => ['nonce(main)']],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $nonce = $this->nonceGenerator->getNonce('main');
        $headers = $event->getResponse()->headers;

        self::assertSame("script-src 'self' 'nonce-".$nonce."'", $headers->get('Content-Security-Policy'));
        self::assertSame("script-src 'nonce-".$nonce."'", $headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testCandidatePolicyStandsDownWhenEventOverridesThePolicy(): void
    {
        $this->dispatcher->addListener(BuildCspHeaderEvent::NAME, static function (BuildCspHeaderEvent $event): void {
            $event->setHeaderValue("default-src 'none'");
        });

        $subscriber = $this->createSubscriber(
            ['style-src' => ["'self'", "'unsafe-inline'"]],
            candidateDirectives: ['style-src' => ["'self'"]],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testCandidatePolicyStandsDownInReportOnlyMode(): void
    {
        $subscriber = $this->createSubscriber(
            ['style-src' => ["'self'", "'unsafe-inline'"]],
            reportOnly: true,
            candidateDirectives: ['style-src' => ["'self'"]],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertSame("style-src 'self' 'unsafe-inline'", $event->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testCandidatePolicyDoesNotOverwriteExistingReportOnlyHeader(): void
    {
        $subscriber = $this->createSubscriber(
            ['style-src' => ["'self'", "'unsafe-inline'"]],
            candidateDirectives: ['style-src' => ["'self'"]],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $event->getResponse()->headers->set('Content-Security-Policy-Report-Only', 'existing-value');
        $subscriber->onKernelResponse($event);

        self::assertSame('existing-value', $event->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testEmptyCandidatePolicyDoesNotSetHeader(): void
    {
        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"]],
            candidateDirectives: ['default-src' => false],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testBothPoliciesCarryTheReportingMarkers(): void
    {
        $subscriber = $this->createSubscriber(
            ['style-src' => ["'self'", "'unsafe-inline'"]],
            reportConfig: ['url' => 'https://report.example.com/csp', 'route' => null, 'route_params' => [], 'chance' => 100, 'markers' => ['report-uri', 'report-to']],
            candidateDirectives: ['style-src' => ["'self'"]],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        $headers = $event->getResponse()->headers;

        self::assertStringContainsString('report-to csp-endpoint', (string) $headers->get('Content-Security-Policy'));
        self::assertStringContainsString('report-to csp-endpoint', (string) $headers->get('Content-Security-Policy-Report-Only'));
        self::assertSame('csp-endpoint="https://report.example.com/csp"', $headers->get('Reporting-Endpoints'));
    }

    /**
     * @return list<array{string}>
     */
    public static function documentTypeProvider(): array
    {
        return [
            ['text/html; charset=UTF-8'],
            ['application/xhtml+xml'],
        ];
    }

    #[DataProvider('documentTypeProvider')]
    public function testDocumentResponseCarriesTheWholePolicy(string $contentType): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST, $contentType);
        $subscriber->onKernelResponse($event);

        self::assertSame(
            "default-src 'self'; frame-ancestors 'none'",
            $event->getResponse()->headers->get('Content-Security-Policy'),
        );
    }

    /**
     * A browser opening an image or a sitemap wraps it in a document of its own making, styled
     * inline. The whole policy would block that styling and report a violation the application
     * cannot act on, so only what still describes the resource is sent.
     */
    public function testResourceResponseCarriesOnlyTheDirectivesThatSurvive(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
            'style-src' => ["'self'", 'nonce(main)'],
            'frame-ancestors' => ["'none'"],
            'upgrade-insecure-requests' => true,
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST, 'image/webp');
        $subscriber->onKernelResponse($event);

        self::assertSame(
            "frame-ancestors 'none'",
            $event->getResponse()->headers->get('Content-Security-Policy'),
        );
    }

    public function testResourceResponseCarriesNoHeaderWhenNothingSurvives(): void
    {
        $subscriber = $this->createSubscriber([
            'default-src' => ["'self'"],
            'style-src' => ["'self'", 'nonce(main)'],
        ]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST, 'application/xml');
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
    }

    /**
     * The framework settles an unstated type to HTML, so an unstated type is covered as a
     * document: the policy is never dropped on a page for want of a declaration.
     */
    public function testResponseWithoutContentTypeIsTreatedAsADocument(): void
    {
        $subscriber = $this->createSubscriber(['default-src' => ["'self'"]]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelResponse($event);

        self::assertSame("default-src 'self'", $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testResourceResponseCarriesNoCandidatePolicy(): void
    {
        $subscriber = $this->createSubscriber(
            ['default-src' => ["'self'"], 'frame-ancestors' => ["'none'"]],
            candidateDirectives: ['default-src' => ["'none'"], 'frame-ancestors' => ["'none'"]],
        );

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST, 'application/pdf');
        $subscriber->onKernelResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy-Report-Only'));
    }

    /**
     * A listener replacing the policy states what it wants sent, whatever the response carries.
     */
    public function testOverriddenPolicyIsSentOnAResourceAsWell(): void
    {
        $this->dispatcher->addListener(BuildCspHeaderEvent::NAME, static function (BuildCspHeaderEvent $event): void {
            $event->setHeaderValue("default-src 'none'");
        });

        $subscriber = $this->createSubscriber(['default-src' => ["'self'"]]);

        $event = $this->createResponseEvent(HttpKernelInterface::MAIN_REQUEST, 'application/xml');
        $subscriber->onKernelResponse($event);

        self::assertSame("default-src 'none'", $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    /**
     * @param array<string, list<string>|bool>                                                      $directives
     * @param array{url: ?string, route: ?string, route_params: array<string, string>, chance: int} $reportConfig
     * @param array<string, list<string>|bool>                                                      $candidateDirectives
     */
    private function createSubscriber(
        array $directives,
        bool $reportOnly = false,
        array $reportConfig = ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100, 'markers' => ['report-uri', 'report-to']],
        ?UrlGeneratorInterface $urlGenerator = null,
        array $candidateDirectives = [],
    ): CspHeaderSubscriber {
        $builder = new CspHeaderBuilder(
            $this->nonceGenerator,
            $directives,
            [],
            $reportConfig,
            $urlGenerator,
        );

        $subscriber = new CspHeaderSubscriber($builder, $this->dispatcher, $reportOnly, $candidateDirectives);
        $this->dispatcher->addSubscriber($subscriber);

        return $subscriber;
    }

    private function createResponseEvent(int $requestType, ?string $contentType = null): ResponseEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $response = new Response();

        if (null !== $contentType) {
            $response->headers->set('Content-Type', $contentType);
        }

        return new ResponseEvent($kernel, new Request(), $requestType, $response);
    }
}
