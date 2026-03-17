<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\Service;

use MulerTech\CspBundle\CspNonceGenerator;
use MulerTech\CspBundle\Service\CspHeaderBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CspHeaderBuilderTest extends TestCase
{
    private CspNonceGenerator $nonceGenerator;

    protected function setUp(): void
    {
        $this->nonceGenerator = new CspNonceGenerator();
    }

    public function testBuildBasicDirectives(): void
    {
        $builder = $this->createBuilder([
            'default-src' => ["'self'"],
            'img-src' => ["'self'", 'data:'],
        ]);

        $header = $builder->build();

        self::assertSame("default-src 'self'; img-src 'self' data:", $header);
    }

    public function testBuildWithBooleanDirective(): void
    {
        $builder = $this->createBuilder([
            'default-src' => ["'self'"],
            'upgrade-insecure-requests' => true,
        ]);

        self::assertSame("default-src 'self'; upgrade-insecure-requests", $builder->build());
    }

    public function testBuildWithFalseDirectiveIsOmitted(): void
    {
        $builder = $this->createBuilder([
            'default-src' => ["'self'"],
            'upgrade-insecure-requests' => false,
        ]);

        self::assertSame("default-src 'self'", $builder->build());
    }

    public function testNonceParsing(): void
    {
        $builder = $this->createBuilder([
            'script-src' => ["'self'", 'nonce(main)'],
        ]);

        $header = $builder->build();
        $nonce = $this->nonceGenerator->getNonce('main');

        self::assertSame("script-src 'self' 'nonce-".$nonce."'", $header);
    }

    public function testMultipleNamedNonces(): void
    {
        $builder = $this->createBuilder([
            'script-src' => ["'self'", 'nonce(main)', 'nonce(analytics)'],
        ]);

        $header = $builder->build();
        $nonceMain = $this->nonceGenerator->getNonce('main');
        $nonceAnalytics = $this->nonceGenerator->getNonce('analytics');

        self::assertStringContainsString("'nonce-".$nonceMain."'", $header);
        self::assertStringContainsString("'nonce-".$nonceAnalytics."'", $header);
        self::assertNotSame($nonceMain, $nonceAnalytics);
    }

    public function testAlwaysAddMergedToDirectives(): void
    {
        $builder = $this->createBuilder(
            directives: [
                'default-src' => ["'self'"],
                'script-src' => ["'self'"],
            ],
            alwaysAdd: ['https://cdn.example.com'],
        );

        $header = $builder->build();

        self::assertStringContainsString("default-src 'self' https://cdn.example.com", $header);
        self::assertStringContainsString("script-src 'self' https://cdn.example.com", $header);
    }

    public function testAlwaysAddNotMergedWithNone(): void
    {
        $builder = $this->createBuilder(
            directives: [
                'default-src' => ["'self'"],
                'object-src' => ["'none'"],
            ],
            alwaysAdd: ['https://cdn.example.com'],
        );

        $header = $builder->build();

        self::assertStringContainsString("default-src 'self' https://cdn.example.com", $header);
        self::assertStringContainsString("object-src 'none'", $header);
        self::assertStringNotContainsString("object-src 'none' https://cdn.example.com", $header);
    }

    public function testAlwaysAddNotDuplicated(): void
    {
        $builder = $this->createBuilder(
            directives: [
                'default-src' => ["'self'", 'https://cdn.example.com'],
            ],
            alwaysAdd: ['https://cdn.example.com'],
        );

        $header = $builder->build();

        self::assertSame("default-src 'self' https://cdn.example.com", $header);
    }

    public function testReportingWithUrl(): void
    {
        $builder = $this->createBuilder(
            directives: ['default-src' => ["'self'"]],
            reportConfig: ['url' => 'https://report.example.com/csp', 'route' => null, 'route_params' => [], 'chance' => 100],
        );

        $header = $builder->build();

        self::assertStringContainsString('report-uri https://report.example.com/csp', $header);
        self::assertStringContainsString('report-to csp-endpoint', $header);
    }

    public function testReportingWithRouteAndUrlGenerator(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('csp_report', ['_format' => 'json'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.com/csp-report');

        $builder = new CspHeaderBuilder(
            $this->nonceGenerator,
            ['default-src' => ["'self'"]],
            [],
            ['url' => null, 'route' => 'csp_report', 'route_params' => ['_format' => 'json'], 'chance' => 100],
            $urlGenerator,
        );

        $header = $builder->build();

        self::assertStringContainsString('report-uri https://example.com/csp-report', $header);
        self::assertStringContainsString('report-to csp-endpoint', $header);
    }

    public function testReportingWithChanceZero(): void
    {
        $builder = $this->createBuilder(
            directives: ['default-src' => ["'self'"]],
            reportConfig: ['url' => 'https://report.example.com/csp', 'route' => null, 'route_params' => [], 'chance' => 0],
        );

        $header = $builder->build();

        self::assertStringNotContainsString('report-uri', $header);
        self::assertStringNotContainsString('report-to', $header);
    }

    public function testNoReportingByDefault(): void
    {
        $builder = $this->createBuilder([
            'default-src' => ["'self'"],
        ]);

        $header = $builder->build();

        self::assertStringNotContainsString('report-uri', $header);
        self::assertStringNotContainsString('report-to', $header);
    }

    public function testDirectivesOverride(): void
    {
        $builder = $this->createBuilder([
            'default-src' => ["'self'"],
        ]);

        $header = $builder->build(directivesOverride: [
            'default-src' => ["'self'", 'https://override.com'],
        ]);

        self::assertSame("default-src 'self' https://override.com", $header);
    }

    public function testAlwaysAddOverride(): void
    {
        $builder = $this->createBuilder(
            directives: ['default-src' => ["'self'"]],
            alwaysAdd: ['https://original.com'],
        );

        $header = $builder->build(alwaysAddOverride: ['https://override.com']);

        self::assertStringContainsString('https://override.com', $header);
        self::assertStringNotContainsString('https://original.com', $header);
    }

    public function testBuildWithEmptyDirectivesReturnsEmptyString(): void
    {
        $builder = $this->createBuilder([]);

        $header = $builder->build();

        self::assertSame('', $header);
    }

    /**
     * @param array<string, list<string>|bool>                                                      $directives
     * @param list<string>                                                                          $alwaysAdd
     * @param array{url: ?string, route: ?string, route_params: array<string, string>, chance: int} $reportConfig
     */
    private function createBuilder(
        array $directives = [],
        array $alwaysAdd = [],
        array $reportConfig = ['url' => null, 'route' => null, 'route_params' => [], 'chance' => 100],
    ): CspHeaderBuilder {
        return new CspHeaderBuilder(
            $this->nonceGenerator,
            $directives,
            $alwaysAdd,
            $reportConfig,
        );
    }
}
