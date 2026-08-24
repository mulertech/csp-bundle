<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Report;

/**
 * Turns a violation payload into normalised reports.
 *
 * Two wire formats reach the endpoint, because a policy advertises both markers: the legacy
 * `report-uri` form posts an object under a `csp-report` key with kebab-case fields, the
 * Reporting API posts a list of reports with camelCase fields. The same violation can therefore
 * arrive twice, which is what CspViolationReport::signature() is for.
 */
final class CspReportParser
{
    /**
     * Sources that say nothing about the site's own policy. Browser extensions inject scripts
     * and styles into every page they open, and the browser reports those injections against
     * the page's policy. Left in, they drown the real signal.
     */
    private const array NOISE_PREFIXES = [
        'chrome-extension:',
        'moz-extension:',
        'safari-extension:',
        'safari-web-extension:',
        'ms-browser-extension:',
        'webkit-masked-url:',
        'resource:',
        'chrome:',
    ];

    private const array NOISE_DOCUMENTS = [
        'about:blank',
        'about:srcdoc',
    ];

    /**
     * @return list<CspViolationReport>
     *
     * @throws \JsonException when the payload is not valid JSON
     */
    public function parse(string $payload): array
    {
        $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            return [];
        }

        $violations = array_key_exists('csp-report', $decoded)
            ? [$this->fromViolationBody($decoded['csp-report'])]
            : $this->fromReportingApi($decoded);

        $reports = [];

        foreach ($violations as $violation) {
            if (null !== $violation && !$this->isNoise($violation)) {
                $reports[] = $violation;
            }
        }

        return $reports;
    }

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return list<CspViolationReport|null>
     */
    private function fromReportingApi(array $decoded): array
    {
        $violations = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry) || 'csp-violation' !== ($entry['type'] ?? null)) {
                continue;
            }

            $violations[] = $this->fromViolationBody($entry['body'] ?? null);
        }

        return $violations;
    }

    private function fromViolationBody(mixed $body): ?CspViolationReport
    {
        if (!is_array($body)) {
            return null;
        }

        $effectiveDirective = $this->stringValue($body, 'effective-directive', 'effectiveDirective', 'violated-directive');

        if ('' === $effectiveDirective) {
            return null;
        }

        return new CspViolationReport(
            documentUri: $this->stringValue($body, 'document-uri', 'documentURL'),
            effectiveDirective: $effectiveDirective,
            blockedUri: $this->stringValue($body, 'blocked-uri', 'blockedURL'),
            // Browsers that predate CSP3 omit the field, and an unattributed violation is
            // better treated as a live breakage than dismissed as a candidate observation.
            disposition: $this->stringValue($body, 'disposition') ?: 'enforce',
            referrer: $this->stringValue($body, 'referrer'),
            sourceFile: $this->stringValue($body, 'source-file', 'sourceFile'),
            lineNumber: $this->intValue($body, 'line-number', 'lineNumber'),
            columnNumber: $this->intValue($body, 'column-number', 'columnNumber'),
            sample: $this->stringValue($body, 'script-sample', 'sample'),
            statusCode: $this->intValue($body, 'status-code', 'statusCode'),
            originalPolicy: $this->stringValue($body, 'original-policy', 'originalPolicy'),
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function stringValue(array $body, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (is_string($body[$key] ?? null)) {
                /** @var string $value */
                $value = $body[$key];

                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function intValue(array $body, string ...$keys): int
    {
        foreach ($keys as $key) {
            if (is_int($body[$key] ?? null)) {
                /** @var int $value */
                $value = $body[$key];

                return $value;
            }
        }

        return 0;
    }

    private function isNoise(CspViolationReport $report): bool
    {
        if (in_array($report->documentUri, self::NOISE_DOCUMENTS, true)) {
            return true;
        }

        foreach ([$report->blockedUri, $report->sourceFile] as $source) {
            foreach (self::NOISE_PREFIXES as $prefix) {
                if (str_starts_with($source, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
