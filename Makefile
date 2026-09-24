# kingletas/module-read-split
#
# Run `make` with no arguments for the list.
#
# Inside the monorepo the checks run through `../dev/`, the shared harness,
# against the vendor tree `make -C .. install` resolves there. Cloned on its own
# the package's own composer scripts run instead, against its own vendor/, and
# `make install` needs repo.magento.com credentials for `magento/framework`.

SHELL       := /usr/bin/env bash
.SHELLFLAGS := -eu -o pipefail -c
.DEFAULT_GOAL := help

PHP       ?= php
COMPOSER  ?= composer

# Which suite `make test` runs: all, unit, wiring, performance or behaviour.
SUITE ?= all

SUITE_all         := Test/Unit Test/Wiring Test/Performance Test/Behaviour
SUITE_unit        := Test/Unit
SUITE_wiring      := Test/Wiring
SUITE_performance := Test/Performance
SUITE_behaviour   := Test/Behaviour

SUITE_DIRS  := $(SUITE_$(SUITE))
SUITE_LABEL := $(if $(filter all,$(SUITE)),every suite,the $(SUITE) suite)

HARNESS := $(wildcard $(CURDIR)/../dev/run-tests.php)
PHPCS   := $(wildcard $(CURDIR)/../dev/run-phpcs.php)
PHPCBF  := $(wildcard $(CURDIR)/../dev/run-phpcbf.php)

# Where Magento and PHPUnit are read from: the shared harness's vendor tree, or
# this package's own. `make install` fills either, and either can be overridden.
M2_VENDOR ?= $(if $(HARNESS),$(abspath $(CURDIR)/../dev/vendor),$(CURDIR)/vendor)

# Every check runs the tools it was installed with, never whatever is on PATH.
GUARD := $(if $(HARNESS),guard-harness,guard-package)

.PHONY: help
help: ## Show this help
	@echo
	@echo "  kingletas/module-read-split"
	@echo
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    \033[36m%-12s\033[0m %s\n", $$1, $$2}'
	@echo
	@echo "  checked via $(if $(HARNESS),the shared harness in ../dev,this package's own composer scripts)"
	@echo

# --- install ----------------------------------------------------------------

.PHONY: install
install: ## Install this package's dev dependencies (needs repo.magento.com credentials)
	@$(COMPOSER) install

# --- checks -----------------------------------------------------------------

.PHONY: test
test: guard-suite $(GUARD) ## Run the suites; narrow with SUITE=unit|wiring|performance|behaviour
ifneq ($(HARNESS),)
	@M2_VENDOR="$(M2_VENDOR)" $(PHP) "$(HARNESS)" --configuration ../dev/phpunit.xml $(SUITE_DIRS)
else ifeq ($(SUITE),all)
	@M2_VENDOR="$(M2_VENDOR)" $(COMPOSER) run-script test
else
	@M2_VENDOR="$(M2_VENDOR)" $(COMPOSER) run-script test-$(SUITE)
endif

.PHONY: cs
cs: $(GUARD) ## Coding standard
ifneq ($(PHPCS),)
	@M2_VENDOR="$(M2_VENDOR)" $(PHP) "$(PHPCS)" --standard=phpcs.xml.dist .
else
	@$(COMPOSER) run-script cs
endif

.PHONY: cs-fix
cs-fix: $(GUARD) ## Fix what the coding standard can fix automatically
ifneq ($(PHPCBF),)
	@M2_VENDOR="$(M2_VENDOR)" $(PHP) "$(PHPCBF)" --standard=phpcs.xml.dist .
else
	@$(COMPOSER) run-script cs-fix
endif

.PHONY: stan
stan: guard-package ## Static analysis
	@$(COMPOSER) run-script stan

.PHONY: md
md: guard-package ## Mess detection
	@$(COMPOSER) run-script md

.PHONY: lint
lint: ## Syntax check every PHP file
	@$(COMPOSER) run-script lint

.PHONY: check
check: cs test ## Everything a commit has to pass
	@echo
	@echo "  the standard and $(SUITE_LABEL) pass for read-split"

# --- guards -----------------------------------------------------------------

.PHONY: guard-suite
guard-suite:
	@if [ -z "$(SUITE_DIRS)" ]; then \
		echo "  unknown SUITE '$(SUITE)'. One of: all unit wiring performance behaviour"; \
		echo; \
		echo "      make test"; \
		echo "      make test SUITE=behaviour"; \
		echo; \
		exit 2; \
	fi

.PHONY: guard-harness
guard-harness:
	@if [ ! -d "$(M2_VENDOR)/magento/framework" ] || [ ! -d "$(M2_VENDOR)/phpunit/phpunit" ] \
		|| [ ! -d "$(M2_VENDOR)/squizlabs/php_codesniffer" ] || [ ! -d "$(M2_VENDOR)/slevomat/coding-standard" ]; then \
		echo "  Magento, PHPUnit or the coding standard is missing from $(M2_VENDOR)"; \
		echo; \
		echo "  The shared harness resolves them once, for every module:"; \
		echo; \
		echo "      make -C .. install    # needs repo.magento.com credentials"; \
		echo; \
		echo "  or borrow a vendor tree that has Magento and its dev dependencies:"; \
		echo; \
		echo "      make check M2_VENDOR=/path/to/magento/vendor"; \
		echo; \
		exit 2; \
	fi

.PHONY: guard-package
guard-package:
	@if [ ! -f vendor/composer/installed.json ]; then \
		echo "  This package's dev dependencies are not installed, and the checks"; \
		echo "  do not fall back to whatever phpcs or phpunit is on PATH:"; \
		echo; \
		echo "      make install    # needs repo.magento.com credentials"; \
		echo "      make check"; \
		echo; \
		exit 2; \
	fi
