# Security regressions

Use a disposable localhost WordPress installation with WP-CLI and Python 3.9+.
These tests create accounts and publish test content. The PHP suite removes its
created records on exit; the HTTP fixture has an explicit cleanup command.

## Integration tests

```sh
wp-env start
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-regressions.php
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-regressions.php private
for token in numeric cookie array unknown; do
  wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-regressions.php invalid-preview "$token"
done
```

Covers permissions, legacy settings, approval mutations, fingerprints, immutable
publication, CSS and preview identifiers. Preview modes run in fresh processes.
Leave `CHANGESETS_PRIVATE_PREVIEWS` undefined for these commands.

## HTTP/MCP and concurrent requests

Build the MCP Adapter (`composer install --no-dev` in its checkout), then map
that checkout and the barrier in a local override. For example, if the Adapter
is in `.local/mcp-adapter`, `.wp-env.override.json` can contain:

```json
{"plugins":[".","./.local/mcp-adapter","./tests/fixtures/concurrency-barrier"],"port":8898,"testsPort":8899}
```

Restart wp-env after changing the override. Pass `--base` if the test port differs.
The barrier is a localhost-only plugin that pauses publication for race tests.

```sh
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/http-fixture.php
python3 tests/http-regressions.py --fixture .local/http-fixture-secrets.php
wp-env run tests-cli wp plugin activate concurrency-barrier
python3 tests/concurrency-http-regressions.py --fixture .local/http-fixture-secrets.php
wp-env run tests-cli wp plugin deactivate concurrency-barrier
```

For private HTTP checks, set `CHANGESETS_PRIVATE_PREVIEWS: true` in the local
override config, restart wp-env, and run the HTTP command with `--private`.
Restore public mode afterward. Always clean up, including after a failed test:

```sh
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/http-fixture.php cleanup
wp-env stop
```

Fixture credentials are stored in `.local/`, ignored by Git, and protected from
HTTP reads. Cleanup revokes application passwords and restores registration
options. Tests inspect inert script markers; they do not execute scripts.

Validated with WordPress 6.9/PHP 8.2 and 6.9.4/7.1.2/PHP 8.3, MCP Adapter 0.7.0.
These regressions do not cover every integration, multisite or worker failure.
