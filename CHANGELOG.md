# Release notes for csp-bundle

## v3.0.0 - 2026-08-24

BREAKING CHANGES:

- `style-src` defaults to `'self' nonce(main)` instead of `'self' 'unsafe-inline'`. A policy that
  allows any inline style lets an injection read form values through attribute selectors and cover
  the page with its own interface, which is most of what a script would have done. The two forms
  never cohabit: a nonce makes browsers ignore `'unsafe-inline'` entirely. Every `<style>` element
  served to a browser now needs `nonce="{{ csp_nonce('main') }}"`, and every `style="..."` attribute
  stops being applied, silently, with no server-side error. An attribute carries neither a nonce nor
  a usable hash, so it has to be rewritten as a class or as a rule inside a nonced block.
- `base-uri` defaults to `'none'` instead of `'self'`. An injected `<base>` rewrites how every
  relative URL on the page resolves, and virtually no application uses that tag.

Applications needing the previous behaviour opt back in explicitly through `directives`. The upgrade
notes in the README describe how to measure the impact first: keep the old policy enforced, put the
new one under `report_only_directives`, and read the violations until the whole site has been seen.

## v2.3.0 - 2026-08-24

New features:

- `report.markers` chooses which markers the policy advertises: `report-uri`, `report-to`, or both,
  which stays the default. A policy carrying `report-to` makes every browser implementing the
  Reporting API ignore `report-uri` and queue its reports for out-of-band, batched delivery. That
  delivery is deferred and browsers may drop it, so a violation shown in the console can reach the
  endpoint minutes later, or never. Dropping `report-to` restores an immediate POST that every
  browser performs, which is what a migration needs to measure anything at all.
- `Reporting-Endpoints` is only sent when `report-to` is among the markers, since the header exists
  to define the group that marker names.

Fixes:

- Report-only policies no longer carry the directives the specification ignores there, namely
  `upgrade-insecure-requests`, `sandbox` and `block-all-mixed-content`. They changed nothing and
  earned a browser console warning on every page load. This covers the candidate policy and the
  whole policy under `report_only: true`.

Changes:

- `CspHeaderBuilder` takes the advertised markers in the `markers` key of its `$reportConfig`

## v2.2.0 - 2026-08-24

New features:

- Candidate policy: `report_only_directives` emits a stricter policy as `Content-Security-Policy-Report-Only`
  next to the enforced one, so a policy is tightened on measurements instead of guesswork. It is a diff of the
  enforced policy: every directive it does not mention is inherited. Both policies share the same nonces and the
  same endpoint, and one sampling draw covers both, so their reports are always comparable. Read `disposition`
  on the received reports to tell an enforced block from a candidate observation.
- Violation collector: `CspReportController` reads the legacy `application/csp-report` object and the Reporting
  API list alike, normalises them into a single `CspViolationReport` and dispatches one
  `CspViolationReportedEvent` per violation. Violations injected by browser extensions are dropped, a body over
  64 KB answers `413`, a payload that is not JSON answers `400`. The bundle declares no route: opening a public
  unauthenticated endpoint stays an explicit gesture of the application, which is also where rate limiting belongs.
- `CspViolationReport::signature()` identifies a violation by directive, document path and blocked origin, leaving
  out line and column numbers, which drift with every edit of the page. Deduplicate on it to notify once per
  distinct violation instead of once per page view.

Changes:

- `report_only_directives` combined with `report_only: true` raises a configuration error when the container
  compiles: a policy sent entirely as report-only has nothing to compare against
- `CspHeaderBuilder::build()` accepts a `withReporting` argument, so both policies of a request share one
  sampling decision
- `CspHeaderBuilder::shouldReport()` is public: it resolves the endpoint and draws the sample
- `CspHeaderSubscriber` takes the candidate directives as a fourth argument, empty by default
- `build()` resolves the reporting route once per policy instead of twice

Documentation:

- Candidate policy and violation collection documented, including how `disposition` separates the two

## v2.1.0 - 2026-08-19

Fixes:

- Route-based violation reporting: `report.route` produced no reporting at all. The router was resolved with `$builder->has('router')` while the bundle extension compiles against an isolated container, where the router is never registered — the check was always false and no report URL could be built. The router is now an optional reference resolved when the real container compiles.
- `Reporting-Endpoints` response header is emitted for route-based reporting too, not only when `report.url` is set
- Nonces are regenerated per request in persistent runtimes: `CspNonceGenerator` implements `ResetInterface` and its service is tagged `kernel.reset`. Under FrankenPHP worker mode, RoadRunner or Swoole, the same nonce was served on every request handled by a worker, which an attacker can read on a previous page.

Changes:

- `CspHeaderBuilder::getReportUrl()` is public: it resolves the `url` form and the `route` form alike
- `CspHeaderSubscriber` no longer takes `$reportConfig`; the endpoint comes from the builder
- `symfony/service-contracts: ^3.0` added as an explicit dependency

Documentation:

- Nonce lifetime documented, including persistent runtimes
- Violation reporting documents the emitted markers: `report-uri`, `report-to csp-endpoint`, and the `Reporting-Endpoints` header

## v2.0.2 - 2026-08-19

- README: Flex registers the bundle automatically (auto-generated recipe from the `symfony-bundle` type)
- README: `config/packages/mulertech_csp.yaml` is optional — the bundle runs on its defaults
- README: requirements aligned with composer.json (PHP >= 8.4, Symfony 6.4 / 7.x / 8.x)
- Drop the `recipe/` folder: Flex never reads a package's own recipe directory

## v2.0.1 - 2026-06-02

Add Symfony 8 support

## v2.0.0 - 2026-03-02

BREAKING CHANGES:

- Directives format changed from scalar strings to arrays of sources
- csp_nonce() Twig function now requires a handle argument: csp_nonce('main')
- Nonce placeholder {nonce} replaced by nonce(handle) syntax
- CspHeaderSubscriber constructor signature changed (now requires CspHeaderBuilder + EventDispatcher)

New features:

- Named nonces: multiple independent nonces via nonce(handle) syntax (256-bit)
- Free directives: any CSP directive accepted via variablePrototype config
- always_add: origins automatically merged into all directives (except 'none')
- Violation reporting: report-uri/report-to with URL, Symfony route, and chance sampling
- BuildCspHeaderEvent: customize CSP headers dynamically per-request
- CspHeaderBuilder: dedicated service for CSP header construction
- Secure defaults: 13 directives pre-configured out of the box
- Report-only mode preserved as native config option

## v1.0.1 - 2026-03-02

Add MulerTech CSP Bundle configuration and update service aliases

## v1.0.0 - 2026-03-02

Create Symfony bundle for Content Security Policy (CSP) header management with nonce support.
