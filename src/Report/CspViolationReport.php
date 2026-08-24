<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Report;

/**
 * One CSP violation, normalised from either wire format.
 */
final readonly class CspViolationReport
{
    public function __construct(
        public string $documentUri,
        public string $effectiveDirective,
        public string $blockedUri,
        public string $disposition,
        public string $referrer = '',
        public string $sourceFile = '',
        public int $lineNumber = 0,
        public int $columnNumber = 0,
        public string $sample = '',
        public int $statusCode = 0,
        public string $originalPolicy = '',
    ) {
    }

    /**
     * Stable identity of a violation, meant for deduplication.
     *
     * Line and column numbers are deliberately left out: the same violation drifts down the
     * page as templates evolve, and keying on them would announce it as new on every edit.
     */
    public function signature(): string
    {
        return substr(
            hash('sha256', implode('|', [$this->effectiveDirective, $this->documentPath(), $this->blockedOrigin()])),
            0,
            16,
        );
    }

    /**
     * Path of the violated page, without host, query or fragment, so the same violation on
     * two URLs differing only by a query string keeps one identity.
     */
    public function documentPath(): string
    {
        $path = parse_url($this->documentUri, PHP_URL_PATH);

        return is_string($path) && '' !== $path ? $path : $this->documentUri;
    }

    /**
     * Origin of the blocked resource, or the keyword itself for inline, eval and data violations.
     */
    public function blockedOrigin(): string
    {
        $scheme = parse_url($this->blockedUri, PHP_URL_SCHEME);
        $host = parse_url($this->blockedUri, PHP_URL_HOST);

        if (is_string($scheme) && is_string($host)) {
            return $scheme.'://'.$host;
        }

        return $this->blockedUri;
    }

    /**
     * Whether the enforced policy blocked the resource, as opposed to a report-only candidate
     * that merely observed it. This is what separates a page broken right now from a page that
     * would break once the candidate policy is enforced.
     */
    public function isEnforced(): bool
    {
        return 'enforce' === $this->disposition;
    }
}
