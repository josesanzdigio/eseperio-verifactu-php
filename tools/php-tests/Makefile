.PHONY: help build php-version test-unit test-file test-integration clean

THIS_DIR := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
ROOT_DIR := $(abspath $(THIS_DIR)/../..)
COMPOSE_FILE := $(ROOT_DIR)/docker-compose.yml
UNIT_SERVICE := unit
INTEGRATION_SERVICE := integration
COMPOSER_INSTALL := composer install --no-interaction --prefer-dist

# ── Help ──────────────────────────────────────────────────────────────────

help:
	@printf "Available targets:\n"
	@printf "  make build                        Build or rebuild the PHP test image\n"
	@printf "  make php-version                  Show PHP version inside the container\n"
	@printf "  make test-unit                    Run the complete unit suite (no .env or certificate required)\n"
	@printf "  make test-file FILE=<path>        Run a single test file (path relative to repo root)\n"
	@printf "  make test-integration             Run sandbox integration tests (requires .env and certificate)\n"
	@printf "  make clean                        Remove Docker volumes created by this test environment\n"

# ── Build ──────────────────────────────────────────────────────────────────

build:
	docker compose -f "$(COMPOSE_FILE)" build $(UNIT_SERVICE)

# ── PHP version ────────────────────────────────────────────────────────────

php-version:
	docker compose -f "$(COMPOSE_FILE)" run --rm $(UNIT_SERVICE) sh -lc '$(COMPOSER_INSTALL) && php -v'

# ── Unit tests ─────────────────────────────────────────────────────────────

test-unit:
	docker compose -f "$(COMPOSE_FILE)" run --rm $(UNIT_SERVICE) sh -lc '$(COMPOSER_INSTALL) && vendor/bin/phpunit --testsuite=Unit'

# ── Single-file test ───────────────────────────────────────────────────────
#
# FILE is captured into TEST_FILE via $(value ...) which preserves the literal
# value without Make expansion.  The recipe then accesses it only through shell
# parameter expansion $${TEST_FILE} — the host shell substitutes the value but
# never re-parses it as syntax, so backticks, $(), etc. remain ordinary data.

override export TEST_FILE := $(value FILE)

test-file:
	@f="$${TEST_FILE-}" && test -n "$$f" || { printf 'FILE is required. Example: make test-file FILE=tests/Unit/Models/DateFormatContractTest.php\n'; exit 2; }
	@f="$${TEST_FILE-}" && printf '%s' "$$f" | grep -qE '^tests/[A-Za-z0-9_./-]+\.php$$' || { printf 'Invalid FILE: %s\nMust be a relative path under tests/ with only [A-Za-z0-9_./-] chars.\n' "$$f"; exit 2; }
	@f="$${TEST_FILE-}" && printf '%s' "$$f" | grep -qv '\.\.' || { printf 'Path traversal not allowed: %s\n' "$$f"; exit 2; }
	@f="$${TEST_FILE-}" && test -f "$(ROOT_DIR)/$$f" || { printf 'File not found: %s\n' "$$f"; exit 2; }
	docker compose -f "$(COMPOSE_FILE)" run --rm $(UNIT_SERVICE) sh -c '$(COMPOSER_INSTALL) && exec vendor/bin/phpunit "$$1"' _ "$${TEST_FILE}"

# ── Integration tests ──────────────────────────────────────────────────────

_INTEGRATION_ENV_FILE := $(ROOT_DIR)/.env

# Extract a value from .env without sourcing it as shell code.
# Only matches lines beginning with KEY=, trims surrounding whitespace.
define get_env
$(shell grep -E '^(export )?$(1)=' '$(_INTEGRATION_ENV_FILE)' 2>/dev/null | head -1 | sed 's/^export //;s/^$(1)=//')
endef

test-integration:
	@test -f "$(_INTEGRATION_ENV_FILE)" || (printf ".env file is required for integration tests.\nCopy .env.example to .env and configure it with a sandbox certificate.\n" && exit 1)
	$(eval _CERT_PATH := $(call get_env,VERIFACTU_CERT_PATH))
	$(eval _IS_PLACEHOLDER := $(shell printf '%s' "$(_CERT_PATH)" | grep -q '/path/to/your/' && echo yes || echo no))
	@test -n "$(_CERT_PATH)" || (printf "VERIFACTU_CERT_PATH is not set in .env\n" && exit 1)
	@test "$(_IS_PLACEHOLDER)" != "yes" || (printf "VERIFACTU_CERT_PATH in .env still contains the example placeholder.\nUpdate it to point to a real sandbox certificate file.\n" && exit 1)
	@test -e "$(_CERT_PATH)" || (printf "Certificate not found: $(_CERT_PATH)\nCheck VERIFACTU_CERT_PATH in .env\n" && exit 1)
	@test -f "$(_CERT_PATH)" || (printf "Certificate path is not a regular file: $(_CERT_PATH)\n" && exit 1)
	@printf "Running sandbox integration tests (requires valid sandbox certificate)\n"
	docker compose -f "$(COMPOSE_FILE)" --profile integration run --rm $(INTEGRATION_SERVICE) sh -lc '$(COMPOSER_INSTALL) && vendor/bin/phpunit --testsuite=Integration'

# ── Clean ───────────────────────────────────────────────────────────────────

clean:
	docker compose -f "$(COMPOSE_FILE)" down --remove-orphans -v 2>/dev/null || true
	docker compose -f "$(COMPOSE_FILE)" rm -f -s -v 2>/dev/null || true
