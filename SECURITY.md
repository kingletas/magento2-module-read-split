# Security policy

## Supported versions

The `main` branch is supported, and tagged releases receive fixes for the
current minor version.

## Reporting a vulnerability

**Do not open a public issue.**

Report privately through GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)
on this repository, which opens a draft advisory only the maintainers can see.
Or email **code@kingletas.com**.

Please include what the module does wrong, how to reach it, and what an attacker
gets. A failing test is the clearest report there is.

## What this module assumes

It runs inside Magento, with Magento's own access control in front of it. A
finding that needs administrator rights the attacker should not have is a
finding about the store's configuration rather than about this module, but
report it anyway if you are unsure, and we will work out which it is.
