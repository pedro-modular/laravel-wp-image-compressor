# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

This tool is designed to be uploaded to production websites, so security reports
are taken seriously.

Please **do not open a public issue** for security problems. Instead, email
**pedroseco@modular-studio.com** with:

- A description of the vulnerability and its impact
- Steps to reproduce (a proof of concept if possible)
- The version affected

You will get an acknowledgement within a few days. Once a fix is released, the
issue will be disclosed in the changelog with credit to the reporter (unless you
prefer to stay anonymous).

## Deployment reminders for users

- Complete the setup wizard after uploading `image-compressor.php`. Setup is
  gated on a proof-of-ownership file you create, so an unfinished install cannot
  be claimed by a passer-by — but still finish it promptly.
- Delete the script from the server when you are done — it is a maintenance
  tool, not something to leave installed permanently.
- Keep the host's ImageMagick `policy.xml` at distro defaults or stricter. The
  tool validates image content and pins decoders, but a hardened system policy
  is good defense in depth. Prefer running the CLI/bash version as a dedicated
  low-privilege user rather than root.
- The `.image-compressor/` directory is protected by an auto-generated
  `.htaccess` on Apache/LiteSpeed; on nginx, add an equivalent
  `location ~ /\.image-compressor { deny all; }` rule.
