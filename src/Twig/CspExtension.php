<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Twig;

use MulerTech\CspBundle\CspNonceGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CspExtension extends AbstractExtension
{
    public function __construct(
        private readonly CspNonceGenerator $nonceGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->getNonce(...)),
        ];
    }

    public function getNonce(string $handle): string
    {
        return $this->nonceGenerator->getNonce($handle);
    }
}
