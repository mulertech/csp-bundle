# MulerTech CSP Bundle

___
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mulertech/csp-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/csp-bundle)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/csp-bundle/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mulertech/csp-bundle/actions/workflows/tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/csp-bundle/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/mulertech/csp-bundle/actions/workflows/phpstan.yml)
[![GitHub Security Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/csp-bundle/security.yml?branch=main&label=security&style=flat-square)](https://github.com/mulertech/csp-bundle/actions/workflows/security.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mulertech/csp-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/csp-bundle)
[![Test Coverage](https://raw.githubusercontent.com/mulertech/csp-bundle/badge/badge-coverage.svg)](https://packagist.org/packages/mulertech/csp-bundle)
___

Symfony bundle for Content Security Policy (CSP) header management with named nonce support.

## Installation

```bash
composer require mulertech/csp-bundle
```

## Configuration

The bundle ships with secure defaults for all directives. You only need to override what differs from the defaults.

Minimal `config/packages/mulertech_csp.yaml`:

```yaml
mulertech_csp:
    directives:
        script-src:
            - "'self'"
            - "nonce(main)"
        style-src:
            - "'self'"
            - "'unsafe-inline'"
```

### Full reference

Here is the complete list of available options with their default values:

```yaml
mulertech_csp:
    enabled: true                    # true by default
    report_only: false               # false by default
    always_add: []                   # Origins added to ALL directives
    report:
        url: ~                       # External URL for report-uri/report-to
        route: ~                     # Symfony route name (alternative to url)
        route_params: []             # Route parameters
        chance: 100                  # 0-100, % of requests with reporting
    directives:                      # Only override what you need
        default-src:
            - "'self'"
        script-src:
            - "'self'"
            - "nonce(main)"
        style-src:
            - "'self'"
            - "'unsafe-inline'"
        img-src:
            - "'self'"
            - "data:"
        font-src:
            - "'self'"
        connect-src:
            - "'self'"
        media-src:
            - "'self'"
        object-src:
            - "'none'"
        frame-src:
            - "'none'"
        frame-ancestors:
            - "'none'"
        base-uri:
            - "'self'"
        form-action:
            - "'self'"
        upgrade-insecure-requests: true
```

### Default directives

| Directive | Default |
|---|---|
| `default-src` | `'self'` |
| `script-src` | `'self'` + `nonce(main)` |
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
| `upgrade-insecure-requests` | `true` |

### Named nonces

Use `nonce(handle)` syntax in directives to create named nonces:

```yaml
mulertech_csp:
    directives:
        script-src:
            - "'self'"
            - "nonce(main)"           # For your main scripts
            - "nonce(analytics)"      # For analytics scripts
```

Each named nonce generates a unique 256-bit (32 bytes) cryptographically secure value.

### always_add

Add origins to all directives automatically (except those set to `'none'`):

```yaml
mulertech_csp:
    always_add:
        - "https://cdn.example.com"
    directives:
        default-src:
            - "'self'"
        object-src:
            - "'none'"               # always_add is NOT merged here
```

### Violation reporting

Report CSP violations to an external endpoint:

```yaml
mulertech_csp:
    report:
        url: "https://report.example.com/csp"
        chance: 50                    # Only 50% of requests
```

Or use a Symfony route:

```yaml
mulertech_csp:
    report:
        route: "app_csp_report"
        route_params: {}
```

### Report-only mode

Test your CSP policy without enforcing it:

```yaml
mulertech_csp:
    report_only: true
```

This sets the `Content-Security-Policy-Report-Only` header instead of `Content-Security-Policy`.

## Usage

### In Twig templates

Use the `csp_nonce('handle')` function with a named handle:

```twig
<script nonce="{{ csp_nonce('main') }}">
    // Your inline JavaScript
</script>

<script nonce="{{ csp_nonce('analytics') }}">
    // Analytics script
</script>
```

### Dynamic CSP customization

Listen to the `BuildCspHeaderEvent` to customize CSP per-request:

```php
use MulerTech\CspBundle\Event\BuildCspHeaderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: BuildCspHeaderEvent::NAME)]
class CspListener
{
    public function __invoke(BuildCspHeaderEvent $event): void
    {
        if ($event->getRequest()->getPathInfo() === '/admin') {
            $event->setHeaderValue("default-src 'self'; script-src 'self'");
        }
    }
}
```

### Inject the nonce generator

```php
use MulerTech\CspBundle\CspNonceGenerator;

class MyService
{
    public function __construct(
        private readonly CspNonceGenerator $nonceGenerator,
    ) {}

    public function getMainNonce(): string
    {
        return $this->nonceGenerator->getNonce('main');
    }
}
```

## Upgrading from v1.x

### Breaking changes

1. **Directives format**: Changed from scalar strings to arrays of sources

```yaml
# v1.x
mulertech_csp:
    directives:
        script-src: "'self' 'nonce-{nonce}'"

# v2.0
mulertech_csp:
    directives:
        script-src:
            - "'self'"
            - "nonce(main)"
```

2. **Twig function**: `csp_nonce()` now requires a handle argument

```twig
{# v1.x #}
<script nonce="{{ csp_nonce() }}">

{# v2.0 #}
<script nonce="{{ csp_nonce('main') }}">
```

3. **Nonce placeholder**: `{nonce}` replaced by `nonce(handle)` syntax

## Requirements

- PHP >= 8.2
- Symfony 6.4 or 7.x
- Twig (optional, for the `csp_nonce()` function)
- symfony/routing (optional, for route-based reporting)

## License

MIT
