# Contributing

Thanks for looking.

## Where this module is written

**This repository is published from the tree holding all the Kingletas modules, not edited
directly.** A pull request here is read and welcome, but it gets applied in that
tree and arrives back as a new published commit, so do not be surprised when it
lands under a different hash.

That also means an issue about how this module works alongside the others is the
right kind of issue to open here.

## The gate

```bash
composer check
```

Linting, the coding standard, PHPStan, mess detection and the test suites. The
same set CI runs on a pull request.

The tests need a real Magento installation to resolve the framework against,
without being installed into one. Point `M2_VENDOR` at that installation's
`vendor` directory:

```bash
M2_VENDOR=/path/to/magento/vendor composer test
```

Each suite can be run on its own with `composer test-unit`, `test-wiring`,
`test-behaviour` and `test-performance`.

## What a change should look like

- One concern per pull request, with the reasoning in the description.
- `composer check` green.
- A test that fails before your change and passes after it. One direction is not
  a test.
- An entry in `CHANGELOG.md` under a new heading, saying what changed for
  somebody using the module rather than what the diff did.
- Comments say what the code does or what it guards against, in a sentence or
  two. History belongs in the commit message and the changelog.

## Security

Please do not open a public issue for a vulnerability. [SECURITY.md](SECURITY.md)
has the reporting route.
