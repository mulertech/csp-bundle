# MulerTech CSP Bundle

___
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mulertech/csp-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/csp-bundle)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/csp-bundle/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mulertech/csp-bundle/actions/workflows/tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/csp-bundle/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/mulertech/csp-bundle/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mulertech/csp-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/csp-bundle)
[![Test Coverage](https://raw.githubusercontent.com/mulertech/csp-bundle/badge/badge-coverage.svg)](https://packagist.org/packages/mulertech/csp-bundle)
___

Symfony bundle for Content Security Policy (CSP) header management with named nonce support.

## Installation

```bash
composer require mulertech/csp-bundle
```

The package is a `symfony-bundle`, so Flex generates a recipe for it and registers it in `config/bundles.php` on its own. Without Flex, add the line by hand:

```php
return [
    // ...
    MulerTech\CspBundle\MulerTechCspBundle::class => ['all' => true],
];
```

Without that line the package sits in `vendor/` doing nothing: no `Content-Security-Policy` header on responses, and `csp_nonce()` raises `Unknown "csp_nonce" function` in Twig.

## Configuration

The bundle ships with secure defaults for every directive and works with no configuration file at all. Create `config/packages/mulertech_csp.yaml` to override what differs from those defaults:

```yaml
mulertech_csp:
    directives:
        img-src:
            - "'self'"
            - "data:"
            - "https://cdn.example.com"
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
        markers: ['report-uri', 'report-to']  # Which markers the policy advertises
    report_only_directives: []       # Candidate policy observed next to the enforced one
    directives:                      # Only override what you need
        default-src:
            - "'self'"
        script-src:
            - "'self'"
            - "nonce(main)"
        style-src:
            - "'self'"
            - "nonce(main)"
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
            - "'none'"
        form-action:
            - "'self'"
        upgrade-insecure-requests: true
```

### Default directives

| Directive | Default |
|---|---|
| `default-src` | `'self'` |
| `script-src` | `'self'` + `nonce(main)` |
| `style-src` | `'self'` + `nonce(main)` |
| `img-src` | `'self' data:` |
| `font-src` | `'self'` |
| `connect-src` | `'self'` |
| `media-src` | `'self'` |
| `object-src` | `'none'` |
| `frame-src` | `'none'` |
| `frame-ancestors` | `'none'` |
| `base-uri` | `'none'` |
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

Each named nonce generates a unique 256-bit (32 bytes) cryptographically secure value, and every request gets fresh ones. Persistent runtimes (FrankenPHP worker mode, RoadRunner, Swoole) keep services alive across requests, so the generator is tagged `kernel.reset`: a nonce reused from one page to the next would be readable by an attacker before the injection.

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

Or use a Symfony route, resolved to an absolute URL:

```yaml
mulertech_csp:
    report:
        route: "app_csp_report"
        route_params: {}
```

Both forms emit the same three markers: `report-uri` and `report-to csp-endpoint` inside the policy, plus a `Reporting-Endpoints` response header defining the `csp-endpoint` group. `report-uri` is deprecated but still the only form some browsers honour, hence the pair.

#### Choosing the markers

```yaml
mulertech_csp:
    report:
        route: "app_csp_report"
        markers: ['report-uri']
```

A policy carrying `report-to` makes every browser that implements the Reporting API ignore `report-uri` entirely and queue its reports for out-of-band, batched delivery. That delivery is deferred, and browsers are free to drop it: a violation shown in the console can reach the endpoint minutes later, or never. Dropping `report-to` restores an immediate POST that every browser performs, which is what a migration needs to measure anything at all.

Keep both when reports are a background signal, keep `report-uri` alone when you need the data now. `Reporting-Endpoints` is only sent when `report-to` is among the markers, since it exists to define the group that marker names.

### Collecting violations

The bundle ships a collector that reads both wire formats and hands each violation to the application. Declare the route yourself:

```yaml
# config/routes/mulertech_csp.yaml
mulertech_csp_report:
    path: /csp-report
    controller: MulerTech\CspBundle\Controller\CspReportController
    methods: [POST]
```

The bundle declares no route on its own: the endpoint is public and unauthenticated by nature, so opening it stays an explicit gesture of the application, which is also where rate limiting belongs.

Then listen to the violations and decide where they go:

```php
use MulerTech\CspBundle\Event\CspViolationReportedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: CspViolationReportedEvent::NAME)]
class CspViolationListener
{
    public function __invoke(CspViolationReportedEvent $event): void
    {
        $report = $event->getReport();

        $this->logger->warning('CSP violation', [
            'signature' => $report->signature(),
            'directive' => $report->effectiveDirective,
            'blocked' => $report->blockedOrigin(),
            'document' => $report->documentPath(),
            'enforced' => $report->isEnforced(),
        ]);
    }
}
```

What the collector settles before dispatching:

- the legacy `application/csp-report` object and the Reporting API list are normalised into one `CspViolationReport`, since a policy advertising both markers receives the same violation twice;
- violations injected by browser extensions (`chrome-extension:`, `moz-extension:` and their kin) are dropped, as they belong to the visitor's browser and would bury the real signal;
- a body over 64 KB answers `413`, a payload that is not JSON answers `400`, anything else answers `204`.

`CspViolationReport::signature()` identifies a violation by directive, document path and blocked origin. Line and column numbers are left out on purpose: they drift with every edit of the page, and keying on them would announce the same violation as new every time. Deduplicate on the signature to notify once per distinct violation instead of once per page view.

The report carries `originalPolicy` as the browser sent it, which is long: `disposition` is the field that tells an enforced block from a candidate observation.

### Report-only mode

Test your CSP policy without enforcing it:

```yaml
mulertech_csp:
    report_only: true
```

This sets the `Content-Security-Policy-Report-Only` header instead of `Content-Security-Policy`.

### Candidate policy

Observe a stricter policy on real traffic while the current one keeps protecting the site:

```yaml
mulertech_csp:
    directives:
        style-src:
            - "'self'"
            - "'unsafe-inline'"
    report_only_directives:
        style-src:
            - "'self'"
            - "nonce(main)"
    report:
        route: "app_csp_report"
```

The response then carries two policies: `Content-Security-Policy` with the enforced one, and `Content-Security-Policy-Report-Only` with the candidate. Nothing new is blocked, and the browser reports everything the candidate would have blocked, so a policy is tightened on measurements instead of guesswork.

`report_only_directives` is a diff of the enforced policy rather than a policy of its own: every directive it does not mention is inherited, so the two differ only where the migration is happening. Both share the same nonces and the same endpoint, and a request sampled in by `chance` is sampled in for both, so the two sets of reports are always comparable.

Read `disposition` on the received reports to tell them apart: `enforce` means the resource is blocked right now, `report` means it would be blocked once the candidate is enforced.

Since `report_only: true` already sends the whole policy as report-only, it leaves nothing to compare against: combining it with `report_only_directives` raises a configuration error when the container compiles.

A listener that replaces the policy wholesale through `BuildCspHeaderEvent` leaves no configured policy to diff against, so the candidate stands down on those responses.

Directives the specification ignores in a report-only delivery, namely `upgrade-insecure-requests`, `sandbox` and `block-all-mixed-content`, are dropped from the candidate, and from the whole policy under `report_only: true`. They would change nothing there and would earn a console warning on every page load.

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

## Upgrading from v2.x

### Breaking changes

1. **`style-src` no longer allows inline styles**

```
# v2.x
style-src 'self' 'unsafe-inline'

# v3.0
style-src 'self' 'nonce-...'
```

A policy that allows any inline style lets an injection read form values through attribute
selectors and cover the page with its own interface, which is most of what a script would have
done. The two forms never cohabit: a nonce makes browsers ignore `'unsafe-inline'` entirely.

Every `<style>` element served to a browser now needs `nonce="{{ csp_nonce('main') }}"`, and every
`style="..."` attribute stops being applied, silently, with no server-side error. An attribute
carries neither a nonce nor a usable hash, so it has to be rewritten as a class or as a rule in a
nonced block.

Measure before you switch: keep the old policy enforced and put the new one under
`report_only_directives`, then read the violations for as long as it takes to see the whole site.
An application that genuinely needs inline styles opts back in explicitly:

```yaml
mulertech_csp:
    directives:
        style-src:
            - "'self'"
            - "'unsafe-inline'"
```

2. **`base-uri` is `'none'`**

An injected `<base>` rewrites how every relative URL on the page resolves. Applications using a
`<base>` tag set `base-uri: ["'self'"]` back.

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

- PHP >= 8.4
- Symfony 6.4, 7.x or 8.x
- Twig (optional, for the `csp_nonce()` function)
- symfony/routing (optional, for route-based reporting)

## License

MIT
