<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Service;

use MulerTech\CspBundle\CspNonceGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CspHeaderBuilder
{
    /**
     * @param array<string, list<string>|bool>                                                      $directives
     * @param list<string>                                                                          $alwaysAdd
     * @param array{url: ?string, route: ?string, route_params: array<string, string>, chance: int} $reportConfig
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

        $parts[] = 'report-uri '.$reportUrl;
        $parts[] = 'report-to csp-endpoint';
    }
}
