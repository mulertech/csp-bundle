<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\Report;

use MulerTech\CspBundle\Report\CspViolationReport;
use PHPUnit\Framework\TestCase;

final class CspViolationReportTest extends TestCase
{
    public function testSignatureIgnoresLineAndColumnNumbers(): void
    {
        $first = $this->createReport(lineNumber: 12, columnNumber: 4);
        $second = $this->createReport(lineNumber: 340, columnNumber: 91);

        self::assertSame($first->signature(), $second->signature());
    }

    public function testSignatureIgnoresQueryString(): void
    {
        $first = $this->createReport(documentUri: 'https://example.com/blog?page=2');
        $second = $this->createReport(documentUri: 'https://example.com/blog?page=7');

        self::assertSame($first->signature(), $second->signature());
    }

    public function testSignatureSeparatesDirectives(): void
    {
        $first = $this->createReport(effectiveDirective: 'style-src');
        $second = $this->createReport(effectiveDirective: 'script-src');

        self::assertNotSame($first->signature(), $second->signature());
    }

    public function testSignatureSeparatesBlockedOrigins(): void
    {
        $first = $this->createReport(blockedUri: 'https://evil.example.com/a.js');
        $second = $this->createReport(blockedUri: 'https://other.example.com/a.js');

        self::assertNotSame($first->signature(), $second->signature());
    }

    public function testSignatureIgnoresPathOfTheBlockedResource(): void
    {
        $first = $this->createReport(blockedUri: 'https://evil.example.com/a.js');
        $second = $this->createReport(blockedUri: 'https://evil.example.com/b.js');

        self::assertSame($first->signature(), $second->signature());
    }

    public function testDocumentPathFallsBackToTheRawValue(): void
    {
        $report = $this->createReport(documentUri: 'inline');

        self::assertSame('inline', $report->documentPath());
    }

    public function testBlockedOriginKeepsKeywords(): void
    {
        $report = $this->createReport(blockedUri: 'inline');

        self::assertSame('inline', $report->blockedOrigin());
    }

    public function testBlockedOriginReducesUrlToItsOrigin(): void
    {
        $report = $this->createReport(blockedUri: 'https://evil.example.com:8443/payload.js?v=2');

        self::assertSame('https://evil.example.com', $report->blockedOrigin());
    }

    public function testIsEnforced(): void
    {
        self::assertTrue($this->createReport(disposition: 'enforce')->isEnforced());
        self::assertFalse($this->createReport(disposition: 'report')->isEnforced());
    }

    private function createReport(
        string $documentUri = 'https://example.com/blog',
        string $effectiveDirective = 'style-src',
        string $blockedUri = 'inline',
        string $disposition = 'enforce',
        int $lineNumber = 0,
        int $columnNumber = 0,
    ): CspViolationReport {
        return new CspViolationReport(
            documentUri: $documentUri,
            effectiveDirective: $effectiveDirective,
            blockedUri: $blockedUri,
            disposition: $disposition,
            lineNumber: $lineNumber,
            columnNumber: $columnNumber,
        );
    }
}
