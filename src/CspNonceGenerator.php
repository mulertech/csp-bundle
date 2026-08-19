<?php

declare(strict_types=1);

namespace MulerTech\CspBundle;

use Symfony\Contracts\Service\ResetInterface;

final class CspNonceGenerator implements ResetInterface
{
    /** @var array<string, string> */
    private array $nonces = [];

    public function getNonce(string $handle = 'default'): string
    {
        return $this->nonces[$handle] ??= base64_encode(random_bytes(32));
    }

    /**
     * Drops the generated nonces so the next request gets fresh ones.
     *
     * A nonce only defends against injection as long as an attacker cannot read it beforehand.
     * Persistent runtimes (FrankenPHP worker mode, RoadRunner, Swoole) keep services alive across
     * requests, so without this reset the same nonce would be served on every page.
     */
    public function reset(): void
    {
        $this->nonces = [];
    }
}
