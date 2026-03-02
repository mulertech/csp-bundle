<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\EventSubscriber;

use MulerTech\CspBundle\CspNonceGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CspHeaderSubscriber implements EventSubscriberInterface
{
    /**
     * @param array<string, string|bool|null> $directives
     */
    public function __construct(
        private readonly CspNonceGenerator $nonceGenerator,
        private readonly array $directives,
        private readonly bool $reportOnly,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headerName = $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        if ($event->getResponse()->headers->has($headerName)) {
            return;
        }

        $headerValue = $this->buildHeaderValue();

        if ('' !== $headerValue) {
            $event->getResponse()->headers->set($headerName, $headerValue);
        }
    }

    private function buildHeaderValue(): string
    {
        $nonce = $this->nonceGenerator->getNonce();
        $parts = [];

        foreach ($this->directives as $directive => $value) {
            if (null === $value) {
                continue;
            }

            if (true === $value) {
                $parts[] = $directive;
                continue;
            }

            if (false === $value) {
                continue;
            }

            $resolvedValue = str_replace('{nonce}', $nonce, $value);
            $parts[] = $directive.' '.$resolvedValue;
        }

        return implode('; ', $parts);
    }
}
