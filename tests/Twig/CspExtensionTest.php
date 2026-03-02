<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Tests\Twig;

use MulerTech\CspBundle\CspNonceGenerator;
use MulerTech\CspBundle\Twig\CspExtension;
use PHPUnit\Framework\TestCase;

final class CspExtensionTest extends TestCase
{
    public function testRegistersCspNonceFunction(): void
    {
        $extension = new CspExtension(new CspNonceGenerator());

        $functions = $extension->getFunctions();
        $names = array_map(static fn ($function) => $function->getName(), $functions);

        self::assertContains('csp_nonce', $names);
    }

    public function testReturnsNonceFromGenerator(): void
    {
        $generator = new CspNonceGenerator();
        $extension = new CspExtension($generator);

        self::assertSame($generator->getNonce(), $extension->getNonce());
    }
}
