# Local security regression checks

Run only against a disposable localhost WordPress installation. Tests create role
accounts, temporary application passwords, changesets and content, and publish test
content. They are not a production diagnostic tool. PHP tests require WP-CLI;
HTTP tests use Python 3.8+ standard libraries and need no browser.

## Function and WordPress-hook tests

```sh
wp-env start
wp-env run tests-cli wp plugin activate changesets
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-regressions.php
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-regressions.php private
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-review-regressions.php
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/plugin-security-regressions.php
for token in numeric cookie array unknown; do
  wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-review-regressions.php invalid-preview "$token"
done
```

Each invalid-preview invocation is a fresh request because preview content uses
per-request caching. Checks cover privilege and ownership boundaries, unsafe and
malformed legacy options, name aliases, approval mutations, source-type inference,
CSS sanitization, immutable publication snapshots and publication locks.

## Actual HTTP and MCP tests

Use a built MCP Adapter, not its source archive without dependencies. The local
validation copied the official source to `.local/mcp-adapter` and installed its
production Composer dependencies (`composer install --no-dev`). An ignored
`.wp-env.override.json` mapped this copy instead of the source archive and selected
unused development/test ports 8898/8899. The plugin's existing `meta.public` flags
worked with the standard Adapter 0.7.0; no custom exposure configuration was used.

```sh
mkdir -p .local
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/http-fixture.php
python3 tests/http-regressions.py --fixture .local/http-fixture-secrets.php
```

Fixture secrets are saved in an excluded PHP file that exits when requested over
HTTP. Never commit them. The suite covers actual REST abilities, MCP calls,
query/cookie previews, registration pages, native draft REST endpoints, native
admin lists, cookie authentication without a REST nonce, and a complete
Contributor proposal → Editor review → mutation → reapproval → publish workflow.
Script injection payloads contain inert markers and are inspected as response text;
no script is executed.

For concurrent requests, add `tests/fixtures/concurrency-barrier` as a plugin in
the **local override only**, and activate it. It pauses local publication for 1.5s
after snapshot capture; the test sends separate HTTP requests during that window.

```sh
wp-env run tests-cli wp plugin activate concurrency-barrier
python3 tests/concurrency-http-regressions.py --fixture .local/http-fixture-secrets.php
wp-env run tests-cli wp plugin deactivate concurrency-barrier
```

The implementation run used the same barrier copied to an excluded local test
plugin. Checks cover both staged items, native deletion, ability mutation,
duplicate publishing, concurrent reapproval and the public content result.

For private HTTP checks, temporarily set `CHANGESETS_PRIVATE_PREVIEWS: true` in
the local wp-env override `config`, restart, and run:

```sh
python3 tests/private-http-regressions.py --fixture .local/http-fixture-secrets.php
```

Restore public mode afterward. Delete fixture accounts (which revokes their
application passwords), remove the secrets file, deactivate the barrier and stop
the disposable environment when finished.

## Validation limits

The suite was exercised on WordPress 6.9/PHP 8.2.34 and the reported WordPress
6.9.4 and 7.1.2/PHP 8.3.35 combinations. These tests reproduce the reported
privilege/registration/identifier/approval scenarios, rather than the complete
unavailable HackerOne payloads. They do not certify every third-party plugin,
multisite configuration, distributed cache or arbitrary process failure.
