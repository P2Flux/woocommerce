# Vendored: p2flux/p2flux-php

Source: https://github.com/P2Flux/sdk-php at `v0.6.0`, copied by `dev/vendor-sdk.sh`.

Two edits, both mechanical:

- `namespace P2Flux` becomes `namespace P2FluxWC\Vendor\P2Flux`, so this copy cannot collide with
  another plugin's copy of the same SDK.
- `CurlTransport.php` is not copied. This plugin always supplies `wp_remote_post`, and
  WordPress.org rejects plugins that call curl directly.

Do not edit these files. Fix the SDK upstream, tag it, and re-run the script.
