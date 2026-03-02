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

        $nonce = $generator->getNonce();

        self::assertNotEmpty($nonce);
    }

    public function testReturnsSameValueOnMultipleCalls(): void
    {
        $generator = new CspNonceGenerator();

        $first = $generator->getNonce();
        $second = $generator->getNonce();

        self::assertSame($first, $second);
    }

    public function testReturnsDifferentValueBetweenInstances(): void
    {
        $generatorA = new CspNonceGenerator();
        $generatorB = new CspNonceGenerator();

        self::assertNotSame($generatorA->getNonce(), $generatorB->getNonce());
    }

    public function testReturnsValidBase64Of32Bytes(): void
    {
        $generator = new CspNonceGenerator();

        $nonce = $generator->getNonce();

        self::assertSame(44, strlen($nonce));
        self::assertNotFalse(base64_decode($nonce, true));
        self::assertSame(32, strlen(base64_decode($nonce, true)));
    }
}
