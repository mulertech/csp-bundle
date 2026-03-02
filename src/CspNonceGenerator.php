<?php

declare(strict_types=1);

namespace MulerTech\CspBundle;

final class CspNonceGenerator
{
    private ?string $nonce = null;

    public function getNonce(): string
    {
        if (null === $this->nonce) {
            $this->nonce = base64_encode(random_bytes(32));
        }

        return $this->nonce;
    }
}
