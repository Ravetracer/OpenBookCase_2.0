# OpenBookCase — developer task runner.
#
# New here? Run `make setup` once, then `make serve`.
# `make` (or `make help`) lists everything below.

CONSOLE := php bin/console

# Use the Symfony CLI server if installed, else PHP's built-in server.
SYMFONY := $(shell command -v symfony 2>/dev/null)

.DEFAULT_GOAL := help
.PHONY: help setup install build watch keys db fixtures admin grant-admin serve test cache clean

help: ## Show this help
	@echo "OpenBookCase — make targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[1m%-14s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "First time?  make setup   →   make serve"

setup: ## Full guided setup of a fresh working copy (deps, DB, admin user)
	@bash bin/setup.sh

install: ## Install PHP + JS dependencies and build assets
	composer install
	npm install
	npm run build

build: ## Build the frontend assets (production)
	npm run build

watch: ## Build assets and rebuild on change (development)
	npm run dev

keys: ## Generate the dev OAuth keypair if missing
	@if [ -f config/jwt/private.pem ] && [ -f config/jwt/public.pem ]; then \
		echo "Keypair already present."; \
	else \
		$(CONSOLE) league:oauth2-server:generate-keypair --no-interaction; \
	fi

db: ## Create a clean, EMPTY database (drops existing data!)
	$(CONSOLE) app:dev:db-init

fixtures: ## Reset the database and load sample data + test users
	$(CONSOLE) app:dev:fixtures --fresh

admin: ## Create an admin account (prompts for username/e-mail/password)
	$(CONSOLE) app:dev:create-user --admin

grant-admin: ## Grant ROLE_ADMIN to an existing user: make grant-admin EMAIL=you@example.com
	@test -n "$(EMAIL)" || { echo "Usage: make grant-admin EMAIL=you@example.com"; exit 1; }
	$(CONSOLE) app:user:grant-admin "$(EMAIL)"

serve: ## Start the local web server
ifeq ($(SYMFONY),)
	php -S localhost:8000 -t public/
else
	symfony server:start
endif

test: ## Run the full test suite
	vendor/bin/phpunit

cache: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

clean: ## Remove build artefacts and the SQLite dev database
	rm -rf public/build var/cache/* var/data.db
	@echo "Cleaned. Run 'make build' and 'make db' (or 'make fixtures') to rebuild."
