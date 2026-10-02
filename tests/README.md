# Security regression checks

Run only against a disposable WordPress installation. These checks create users,
changesets and content, and publish test content. They do not require PHPUnit.

```sh
wp-env start
wp-env run tests-cli wp plugin activate changesets
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-regressions.php
wp-env run tests-cli wp eval-file wp-content/plugins/changesets/tests/security-regressions.php private
```

The standard pass covers Contributor/Author proposal permissions, approval/publish
restrictions, Editor approval and publishing, Administrator settings permissions,
unsafe new and legacy settings bags, mutation invalidation, public identifier
validation, safe anonymous UUID query/cookie previews and successful approved
publishing. The separate private pass checks anonymous denial and Administrator
access without reusing the per-request preview cache.

Verified locally on WordPress 6.9 and PHP 8.2.34. These are integration checks of
plugin functions and WordPress hooks, not a reproduction of the complete original
HackerOne payloads, HTTP registration flow, MCP transport or the other reported
WordPress/PHP versions. The source MCP Adapter archive emits a missing Composer
autoloader notice; these checks call Changesets functions directly.
