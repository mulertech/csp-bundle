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
     */
    public function build(?array $directivesOverride = null, ?array $alwaysAddOverride = null): string
    {
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
                $resolvedSources = array_unique(array_merge($resolvedSources, $alwaysAdd));
            }

            $parts[] = $directive.' '.implode(' ', $resolvedSources);
        }

        $this->addReporting($parts);

        return implode('; ', $parts);
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
    private function addReporting(array &$parts): void
    {
        $reportUrl = $this->resolveReportUrl();

        if (null === $reportUrl) {
            return;
        }

        if ($this->reportConfig['chance'] < 100 && random_int(1, 100) > $this->reportConfig['chance']) {
            return;
        }

        $parts[] = 'report-uri '.$reportUrl;
        $parts[] = 'report-to csp-endpoint';
    }

    private function resolveReportUrl(): ?string
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
}
