.PHONY: help build up down restart logs shell composer test migrate db-create

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

composer: ## Run composer install
	docker compose exec php composer install

composer-update: ## Run composer update
	docker compose exec php composer update

test: ## Run tests
	docker compose exec php ./bin/phpunit

migrate: ## Run database migrations
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

migrate-diff: ## Generate migration from entity changes
	docker compose exec php php bin/console doctrine:migrations:diff

cache-clear: ## Clear Symfony cache
	docker compose exec php php bin/console cache:clear

setup: build up composer migrate ## Full setup: build, start, install dependencies, and run migrations
	@echo "Setup complete! Application is running at http://localhost:8080"
	@echo "Mailpit UI is available at http://localhost:8025"

clean: down ## Stop containers and remove volumes
	docker compose down -v