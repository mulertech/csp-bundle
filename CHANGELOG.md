# Release notes for csp-bundle

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
