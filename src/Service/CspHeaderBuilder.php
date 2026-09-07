<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Service;

use MulerTech\CspBundle\CspNonceGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CspHeaderBuilder
{
    /**
     * Directives that keep their meaning on a resource served outside a document of ours.
     */
    private const array RESOURCE_DIRECTIVES = ['frame-ancestors', 'sandbox'];

    /**
     * @param array<string, list<string>|bool>                                                                             $directives
     * @param list<string>                                                                                                 $alwaysAdd
     * @param array{url: ?string, route: ?string, route_params: array<string, string>, chance: int, markers: list<string>} $reportConfig
     */
    public function __construct(
        private CspNonceGenerator $nonceGenerator,
        private array $directives,
        private array $alwaysAdd,
        private array $reportConfig,
        private ?UrlGeneratorInterface $urlGenerator = null,
    ) {
    }

    /**
     * @param array<string, list<string>|bool>|null $directivesOverride
     * @param list<string>|null                     $alwaysAddOverride
     * @param bool|null                             $withReporting      decided per request by shouldReport() when omitted
     */
    public function build(
        ?array $directivesOverride = null,
        ?array $alwaysAddOverride = null,
        ?bool $withReporting = null,
    ): string {
        $directives = $directivesOverride ?? $this->directives;
        $alwaysAdd = $alwaysAddOverride ?? $this->alwaysAdd;
        $parts = [];

        foreach ($directives as $directive => $value) {
            if (true === $value) {
                $parts[] = $directive;
                continue;
            }

            if (false === $value) {
                continue;
            }

            /** @var list<string> $sources */
            $sources = $value;

            $resolvedSources = array_map($this->resolveSource(...), $sources);

            if ([] !== $alwaysAdd && !$this->isNoneOnly($resolvedSources)) {
                $resolvedSources = array_values(array_unique(array_merge($resolvedSources, $alwaysAdd)));
            }

            $parts[] = $directive.' '.implode(' ', $resolvedSources);
        }

        $this->addReporting($parts, $withReporting ?? $this->drawSample());

        return implode('; ', $parts);
    }

    /**
     * Policy for a response the browser does not render as one of our documents: an image, a
     * PDF, a feed, a sitemap. Opened directly, those are displayed inside a document the
     * browser builds itself, styled with its own inline stylesheet and, for an image, its own
     * style attribute. The full policy governs that generated document, blocks the viewer's
     * own styling and reports a violation nothing in the application can act on.
     *
     * What survives is what still describes the resource rather than the viewer around it:
     * `frame-ancestors` keeps a PDF or an image out of a foreign frame, `sandbox` keeps its
     * restrictions. Reporting markers are dropped with the rest, since what remains cannot be
     * violated by the viewer.
     */
    public function buildForResource(): string
    {
        $kept = array_intersect_key($this->directives, array_flip(self::RESOURCE_DIRECTIVES));

        if ([] === $kept) {
            return '';
        }

        return $this->build($kept, withReporting: false);
    }

    /**
     * Whether this request carries reporting markers.
     *
     * The draw belongs to the request, not to the policy: a request emitting an enforced
     * policy and a report-only candidate must sample both or neither, otherwise the two
     * disagree and the collected data cannot be compared.
     */
    public function shouldReport(): bool
    {
        return null !== $this->getReportUrl() && $this->drawSample();
    }

    private function drawSample(): bool
    {
        return 100 === $this->reportConfig['chance']
            || random_int(1, 100) <= $this->reportConfig['chance'];
    }

    /**
     * Resolves the violation reporting endpoint, from the configured URL or the configured route.
     */
    public function getReportUrl(): ?string
    {
        if (null !== $this->reportConfig['url'] && '' !== $this->reportConfig['url']) {
            return $this->reportConfig['url'];
        }

        if (null !== $this->reportConfig['route'] && '' !== $this->reportConfig['route'] && null !== $this->urlGenerator) {
            return $this->urlGenerator->generate(
                $this->reportConfig['route'],
                $this->reportConfig['route_params'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        return null;
    }

    private function resolveSource(string $source): string
    {
        if (1 === preg_match('/^nonce\(([^)]+)\)$/', $source, $matches)) {
            return "'nonce-".$this->nonceGenerator->getNonce($matches[1])."'";
        }

        return $source;
    }

    /**
     * @param list<string> $sources
     */
    private function isNoneOnly(array $sources): bool
    {
        return 1 === count($sources) && "'none'" === $sources[0];
    }

    /**
     * @param list<string> $parts
     */
    private function addReporting(array &$parts, bool $withReporting): void
    {
        $reportUrl = $this->getReportUrl();

        if (!$withReporting || null === $reportUrl) {
            return;
        }

        // Both markers are advertised by default, but a policy carrying report-to makes browsers
        // that support the Reporting API ignore report-uri entirely. Dropping report-to is what
        // restores immediate, observable delivery.
        if (in_array('report-uri', $this->reportConfig['markers'], true)) {
            $parts[] = 'report-uri '.$reportUrl;
        }

        if (in_array('report-to', $this->reportConfig['markers'], true)) {
            $parts[] = 'report-to csp-endpoint';
        }
    }
}
