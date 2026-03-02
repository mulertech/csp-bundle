# MulerTech CSP Bundle

Symfony bundle for Content Security Policy (CSP) header management with nonce support.

## Installation

```bash
composer require mulertech/csp-bundle
```

## Configuration

Add to `config/packages/csp.yaml`:

```yaml
csp:
    enabled: true          # true by default
    report_only: false     # false by default
    directives:
        script-src: "'self' 'nonce-{nonce}' data:"
        style-src: "'self' 'unsafe-inline' https://fonts.googleapis.com"
        img-src: "'self' data: https:"
        font-src: "'self' https://fonts.gstatic.com"
```

All directives have secure defaults. Only override what you need.

### Default directives

| Directive | Default |
|---|---|
| `default-src` | `'self'` |
| `script-src` | `'self' 'nonce-{nonce}'` |
| `style-src` | `'self' 'unsafe-inline'` |
| `img-src` | `'self' data:` |
| `font-src` | `'self'` |
| `connect-src` | `'self'` |
| `media-src` | `'self'` |
| `object-src` | `'none'` |
| `frame-src` | `'none'` |
| `frame-ancestors` | `'none'` |
| `base-uri` | `'self'` |
| `form-action` | `'self'` |
| `worker-src` | `'self'` |
| `manifest-src` | `'self'` |
| `upgrade-insecure-requests` | `true` |

## Usage

### In Twig templates

Use the `csp_nonce()` function to add nonces to inline scripts:

```twig
<script nonce="{{ csp_nonce() }}">
    // Your inline JavaScript
</script>
```

### Inject the nonce generator

```php
use MulerTech\CspBundle\CspNonceGenerator;

class MyService
{
    public function __construct(
        private readonly CspNonceGenerator $nonceGenerator,
    ) {}

    public function getNonce(): string
    {
        return $this->nonceGenerator->getNonce();
    }
}
```

## Requirements

- PHP >= 8.2
- Symfony 6.4 or 7.x
- Twig (optional, for the `csp_nonce()` function)

## License

MIT
