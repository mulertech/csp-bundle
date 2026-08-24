<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\Report;

use JsonException;
use MulerTech\CspBundle\Report\CspReportParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CspReportParserTest extends TestCase
{
    private CspReportParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CspReportParser();
    }

    public function testParsesLegacyReportUriPayload(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/blog',
                'referrer' => 'https://example.com/',
                'violated-directive' => 'style-src-elem',
                'effective-directive' => 'style-src-elem',
                'original-policy' => "default-src 'self'",
                'disposition' => 'report',
                'blocked-uri' => 'inline',
                'line-number' => 42,
                'column-number' => 7,
                'source-file' => 'https://example.com/blog',
                'status-code' => 200,
                'script-sample' => 'body { color: red }',
            ],
        ]));

        self::assertCount(1, $reports);
        self::assertSame('https://example.com/blog', $reports[0]->documentUri);
        self::assertSame('style-src-elem', $reports[0]->effectiveDirective);
        self::assertSame('inline', $reports[0]->blockedUri);
        self::assertSame('report', $reports[0]->disposition);
        self::assertSame('https://example.com/', $reports[0]->referrer);
        self::assertSame(42, $reports[0]->lineNumber);
        self::assertSame(7, $reports[0]->columnNumber);
        self::assertSame(200, $reports[0]->statusCode);
        self::assertSame('body { color: red }', $reports[0]->sample);
        self::assertSame("default-src 'self'", $reports[0]->originalPolicy);
        self::assertFalse($reports[0]->isEnforced());
    }

    public function testParsesReportingApiPayload(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            [
                'age' => 0,
                'type' => 'csp-violation',
                'url' => 'https://example.com/blog',
                'user_agent' => 'Mozilla/5.0',
                'body' => [
                    'documentURL' => 'https://example.com/blog',
                    'effectiveDirective' => 'script-src-elem',
                    'blockedURL' => 'https://evil.example.com/a.js',
                    'disposition' => 'enforce',
                    'sourceFile' => 'https://example.com/blog',
                    'lineNumber' => 11,
                    'columnNumber' => 3,
                    'statusCode' => 200,
                    'sample' => '',
                    'originalPolicy' => "default-src 'self'",
                ],
            ],
        ]));

        self::assertCount(1, $reports);
        self::assertSame('script-src-elem', $reports[0]->effectiveDirective);
        self::assertSame('https://evil.example.com/a.js', $reports[0]->blockedUri);
        self::assertTrue($reports[0]->isEnforced());
    }

    public function testParsesSeveralReportsAtOnce(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'style-src', 'documentURL' => 'https://example.com/']],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'script-src', 'documentURL' => 'https://example.com/']],
        ]));

        self::assertCount(2, $reports);
    }

    public function testIgnoresReportsOfAnotherType(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            ['type' => 'deprecation', 'body' => ['id' => 'PrefixedStorageInfo']],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'style-src', 'documentURL' => 'https://example.com/']],
        ]));

        self::assertCount(1, $reports);
        self::assertSame('style-src', $reports[0]->effectiveDirective);
    }

    public function testDefaultsDispositionToEnforceWhenAbsent(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/',
                'violated-directive' => 'style-src',
                'blocked-uri' => 'inline',
            ],
        ]));

        self::assertCount(1, $reports);
        self::assertTrue($reports[0]->isEnforced());
    }

    public function testSkipsPayloadWithoutDirective(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            'csp-report' => ['document-uri' => 'https://example.com/'],
        ]));

        self::assertSame([], $reports);
    }

    public function testSkipsMalformedBody(): void
    {
        self::assertSame([], $this->parser->parse((string) json_encode(['csp-report' => 'not-an-object'])));
        self::assertSame([], $this->parser->parse((string) json_encode([['type' => 'csp-violation', 'body' => 'nope']])));
        self::assertSame([], $this->parser->parse((string) json_encode([['type' => 'csp-violation']])));
        self::assertSame([], $this->parser->parse((string) json_encode(['not', 'reports'])));
    }

    public function testIgnoresFieldsOfTheWrongType(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/',
                'violated-directive' => 'style-src',
                'blocked-uri' => null,
                'line-number' => '42',
            ],
        ]));

        self::assertCount(1, $reports);
        self::assertSame('', $reports[0]->blockedUri);
        self::assertSame(0, $reports[0]->lineNumber);
    }

    public function testReturnsNothingForScalarJson(): void
    {
        self::assertSame([], $this->parser->parse('"a string"'));
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);

        $this->parser->parse('{not json');
    }

    /**
     * @return list<array{string}>
     */
    public static function noiseSourceProvider(): array
    {
        return [
            ['chrome-extension://abcdefgh/inject.js'],
            ['moz-extension://abcdefgh/inject.js'],
            ['safari-extension://abcdefgh/inject.js'],
            ['safari-web-extension://abcdefgh/inject.js'],
            ['ms-browser-extension://abcdefgh/inject.js'],
            ['webkit-masked-url://hidden/'],
            ['resource://gre/modules/thing.js'],
            ['chrome://global/content/thing.js'],
        ];
    }

    #[DataProvider('noiseSourceProvider')]
    public function testDropsViolationsBlockedOnBrowserExtensions(string $blockedUri): void
    {
        $reports = $this->parser->parse((string) json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/',
                'violated-directive' => 'script-src',
                'blocked-uri' => $blockedUri,
            ],
        ]));

        self::assertSame([], $reports);
    }

    public function testDropsViolationsRaisedFromAnExtensionSourceFile(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'inline',
                'source-file' => 'chrome-extension://abcdefgh/inject.js',
            ],
        ]));

        self::assertSame([], $reports);
    }

    public function testDropsViolationsRaisedOnBlankDocuments(): void
    {
        foreach (['about:blank', 'about:srcdoc'] as $documentUri) {
            $reports = $this->parser->parse((string) json_encode([
                'csp-report' => [
                    'document-uri' => $documentUri,
                    'violated-directive' => 'script-src',
                    'blocked-uri' => 'inline',
                ],
            ]));

            self::assertSame([], $reports);
        }
    }

    public function testKeepsGenuineExternalViolations(): void
    {
        $reports = $this->parser->parse((string) json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/blog',
                'violated-directive' => 'img-src',
                'blocked-uri' => 'https://tracker.example.net/pixel.gif',
            ],
        ]));

        self::assertCount(1, $reports);
        self::assertSame('https://tracker.example.net', $reports[0]->blockedOrigin());
    }
}
