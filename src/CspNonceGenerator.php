<?php

declare(strict_types=1);

namespace MulerTech\CspBundle;

final class CspNonceGenerator
{
    /** @var array<string, string> */
    private array $nonces = [];

    public function getNonce(string $handle = 'default'): string
    {
        return $this->nonces[$handle] ??= base64_encode(random_bytes(32));
    }
}
