.PHONY: help build up down restart logs shell composer test migrate db-create tailwind tailwind-watch expose demo-setup demo-up demo-down demo-sync demo-logs demo-db-copy demo-expose

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

build: ## Build Docker images
	docker compose build

up: ## Start all containers
	docker compose up -d

down: ## Stop all containers
	docker compose down

restart: ## Restart all containers
	docker compose restart

logs: ## Show logs from all containers
	docker compose logs -f

logs-php: ## Show logs from PHP container
	docker compose logs -f php

logs-nginx: ## Show logs from nginx container
	docker compose logs -f nginx

logs-db: ## Show logs from database container
	docker compose logs -f database

shell: ## Access PHP container shell
	docker compose exec php sh

shell-db: ## Access database container shell
	docker compose exec database mysql -u app -papp_password symfony_mvp

composer: ## Run composer install (also restarts the worker, whose DI container the post-install cache clear invalidates)
	docker compose exec php composer install
	docker compose restart worker

composer-update: ## Run composer update
	docker compose exec php composer update

test: ## Run tests
	docker compose exec php ./bin/phpunit

migrate: ## Run database migrations
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

migrate-diff: ## Generate migration from entity changes
	docker compose exec php php bin/console doctrine:migrations:diff

cache-clear: ## Clear Symfony cache (also restarts the worker, whose DI container the clear invalidates)
	docker compose exec php php bin/console cache:clear
	docker compose restart worker

import-courts: ## Import Romanian courts from data/courts.json
	docker compose exec php php bin/console app:import-courts

create-test-users: ## Create test users for development
	docker compose exec php php bin/console app:create-test-users

setup: build up composer migrate import-courts create-test-users ## Full setup: build, start, install deps, migrate, import courts, create test users
	@echo "Setup complete! Application is running at http://localhost:8080"
	@echo "Mailpit UI is available at http://localhost:8025"

tailwind: ## Build Tailwind CSS once
	docker compose exec php php bin/console tailwind:build

tailwind-watch: ## Watch and rebuild Tailwind CSS on file changes
	docker compose exec php php bin/console tailwind:build --watch

monitor-cases: ## Monitor court cases via portal.just.ro
	docker compose exec php php bin/console app:monitor-court-cases --no-interaction

import-portal-codes: ## Import portal.just.ro institution codes for courts
	docker compose exec php php bin/console app:import-court-portal-codes --no-interaction

expose: ## Expose app + Mailpit publicly via Cloudflare tunnels
	./scripts/expose-app.sh

clean: down ## Stop containers and remove volumes
	docker compose down -v
demo-setup: ## Create the isolated demo stack (worktree pinned to lexrecovery) and seed it
	./scripts/demo.sh setup

demo-up: ## Start the demo stack
	./scripts/demo.sh up -d

demo-down: ## Stop the demo stack
	./scripts/demo.sh down

demo-sync: ## Move the demo stack to the current tip of lexrecovery and reload it
	./scripts/demo.sh sync

demo-logs: ## Show demo stack logs
	./scripts/demo.sh logs -f

demo-db-copy: ## Copy the dev database into the demo database (one-off)
	./scripts/demo.sh db-copy

demo-expose: ## Expose the demo stack over the named Cloudflare tunnel (stable URL)
	./scripts/expose-demo.sh
