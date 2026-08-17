# Security Policy

## Supported versions

| Version | Status                |
| ------- | --------------------- |
| 6.1.x   | Full support          |
| 6.0.x   | Security fixes only   |
| < 6.0   | Unsupported           |

Shopclass supports the current minor release plus the one before it. Older installs need to
upgrade to receive fixes.

## Reporting a vulnerability

**Report privately.** Use
[GitHub's private vulnerability reporting](https://github.com/mindstellar/shopclass/security/advisories/new),
or email navjottomer@gmail.com if you cannot.

Do not open a public issue or pull request for a security bug. Shopclass is self-hosted, so a
public report exposes every install that has not yet upgraded.

Include what you can:

- What the vulnerability is, and the impact
- Steps to reproduce
- Affected version
- Any suggested fix

## What to expect

- Acknowledgement within 72 hours
- An assessment and a target fix version within 7 days
- Credit in the advisory and the changelog, unless you prefer otherwise

Please give us time to ship a fix and let sites upgrade before publishing details. We will
agree a disclosure date with you rather than impose one.

## Scope

In scope: the Shopclass core in this repository.

Out of scope:

- Bundled plugins and themes — report those on their own repositories
- Third-party plugins and themes we do not publish
- Issues that require a server already misconfigured by its owner (world-writable files,
  exposed `config.php`, a database open to the internet)
- Missing hardening headers with no demonstrated impact

## No bug bounty

There is no paid bounty. Reports are credited in the advisory.
