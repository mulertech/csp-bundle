<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests;

use MulerTech\CspBundle\CspNonceGenerator;
use PHPUnit\Framework\TestCase;

final class CspNonceGeneratorTest extends TestCase
{
    public function testReturnsNonEmptyString(): void
    {
        $generator = new CspNonceGenerator();

        self::assertNotEmpty($generator->getNonce());
    }

    public function testSameHandleReturnsSameNonce(): void
    {
        $generator = new CspNonceGenerator();

        $first = $generator->getNonce('main');
        $second = $generator->getNonce('main');

        self::assertSame($first, $second);
    }

    public function testDifferentHandlesReturnDifferentNonces(): void
    {
        $generator = new CspNonceGenerator();

        self::assertNotSame($generator->getNonce('main'), $generator->getNonce('analytics'));
    }

    public function testDefaultHandleReturnsSameNonce(): void
    {
        $generator = new CspNonceGenerator();

        $first = $generator->getNonce();
        $second = $generator->getNonce('default');

        self::assertSame($first, $second);
    }

    public function testReturnsDifferentValueBetweenInstances(): void
    {
        $generatorA = new CspNonceGenerator();
        $generatorB = new CspNonceGenerator();

        self::assertNotSame($generatorA->getNonce('main'), $generatorB->getNonce('main'));
    }

    public function testReturnsValidBase64Of32Bytes(): void
    {
        $generator = new CspNonceGenerator();

        $nonce = $generator->getNonce('test');

        self::assertSame(44, strlen($nonce));
        self::assertNotFalse(base64_decode($nonce, true));
        self::assertSame(32, strlen(base64_decode($nonce, true)));
    }
}
