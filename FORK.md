# HTMLgraphic maintenance fork

This fork maintains Ion Auth's CI4 branch for PHP 8.2+ and CodeIgniter 4.7.6+.
It starts at upstream `395b84dc43052c9d0246fd4b5ef65cf12a815b82` and preserves
the existing users/groups schema and library return contracts. It is a
maintenance candidate, not an official upstream release or a security audit
certification.

## Included work

- Upstream #1593: PHP 8 property/null handling.
- Upstream #1595: default database-group selection, with a further correction
  to select the seeder's configured connection before parent construction.
- Upstream #1596: disabled remember-me enforcement, extended to direct model calls.
- The two tooling commits from #1598 (`e514ee1`, `bb1b985`) without its
  multi-device token changes.
- Upstream #1597 input normalization, corrected so form validation reads the
  same POST data that is stored. Password whitespace is preserved.
- Selected #1599 behavior: generic recovery and inactive-login responses.
  Registration continues to return a new user ID/activation data; it remains
  administrator-only in the example controller. No duplicate-account
  notification channel was added.
- Logout revokes tokens using the current account's configured identity.
  Profile/admin password changes revoke remember tokens and recovery codes
  in the same database update. Reset paths clear selectors as well as hashes.
- Remember cookies always use HttpOnly; malformed or cleared token state
  fails closed. Failed login flashes only identity, never the password.
- Stable Composer dependencies, corrected PSR-4 mapping, PHPUnit 10, and
  regression tests with disposable SQLite databases. CI targets PHP 8.2–8.5.

## Installation

Keep the upstream Composer package name for compatibility. Add this fork as a
VCS repository in the consuming application's root composer.json, then require
`^4.0@dev` to select branch `4` (normalized as `4.x-dev`). Commit the application's
lock file so deployment does not silently follow branch changes.

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/htmlgraphic/CodeIgniter-Ion-Auth"}
  ],
  "require": {
    "benedmunds/codeigniter-ion-auth": "^4.0@dev"
  }
}
```

The application's committed `composer.lock` is the exact revision pin. Review
its source/dist reference before accepting a dependency update. Use `composer
install` for reproducible deployments; `composer update` deliberately refreshes
branch dependencies and requires a fresh review. Raw `#commit` constraints can
produce strict Composer validation warnings and inconsistent branch metadata.
This does not replace the consuming application's integration tests or deployment review.

## Integration boundaries

- Remember-me is disabled by default in this fork. The retained legacy schema
  has no independent server-side expiry timestamp, and its client cookie
  expiry is not a server-enforced deadline. Leave it disabled until a reviewed
  expiry policy/store is integrated. Enabling it is an explicit application choice.
- Multi-device PR #1594 and the full #1598 branch are excluded. No token-table
  migration or legacy-token fallback is introduced.
- The original installation migration drops existing tables. Use it only to
  create a disposable/new database; never run it over existing account data.
  The demonstration seeder creates a publicly documented password.
- The example recovery action has uniform messages and redirects, not uniform
  timing or built-in request limits. Apply throttling/mail budgets at the app
  boundary. With `useCiEmail = false`, implement delivery through application
  code/hooks; library reset data must remain private. The unmodified external
  email return contract is retained for that purpose.
- The new recovery message is supplied in English; other locales fall back
  to English until translated. No notification emails are added for duplicates.
- Configure HTTPS, Secure/SameSite cookies, CSRF filters, session isolation,
  email delivery, role/route authorization, and account recovery in the app.
  Password/token revocation does not terminate every already active session.

## Development

```sh
composer install
composer validate --strict
composer audit --locked
composer test
```

Tests use the framework's testing bootstrap and SQLite `:memory:`. They do not
require a running site, real credentials, outbound email, or a production DB.
BCrypt cost 4 appears only in test fixtures; runtime cost defaults are retained.

Before updating from upstream, inspect new commits/PRs, run this suite and the
consumer's authentication tests, and update the pinned consumer revision.
