# Vendored: p2flux/sdk-php

Source: https://github.com/P2Flux/sdk-php branch `feat/finance-ready`, 0.15.0 UNRELEASED (commit
`c032579ad8ef7c8dc92a793b2c89bb1b6c5ad0ec`), copied with the same two edits as `dev/vendor-sdk.sh` but
from that commit, because 0.15.0 is not tagged yet. Before a release, re-run `dev/vendor-sdk.sh` with the
`v0.15.0` tag; `dev/release-check.sh` fails until then.

`Keccak.php` is copied too (the client loads it for `checkMerchantRef()`); `Statement.php` and
`Paywall.php` are not used by this plugin.

Two edits, both mechanical:

- `namespace P2Flux` becomes `namespace P2FluxWC\Vendor\P2Flux`, so this copy cannot collide with
  another plugin's copy of the same SDK.
- `CurlTransport.php` is not copied. This plugin always supplies `wp_remote_post`, and
  WordPress.org rejects plugins that call curl directly.

Do not edit these files. Fix the SDK upstream, tag it, and re-run the script.
