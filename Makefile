.PHONY: help up down cc migrate test

ENV ?= dev
COMPOSE ?= docker compose -f docker-compose.yml -f docker-compose.$(ENV).yml

help: ## Список команд
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-8s\033[0m %s\n", $$1, $$2}'

up: ## Поднять стек (ENV=dev|prod)
	$(COMPOSE) up --build -d
	@echo "Env: $(ENV) → http://127.0.0.1:$${APP_PORT:-8080}"

down: ## Остановить стек (ENV=dev|prod)
	$(COMPOSE) down

migrate: ## Накатить миграции
	$(COMPOSE) exec app php artisan migrate --force

cc: ## Очистить кэши Laravel
	$(COMPOSE) exec app php artisan optimize:clear
	@echo "Caches cleared."

test: ## Тесты на отдельной БД maps_reviews_testing
	@$(COMPOSE) exec -T db psql -U maps -d maps_reviews -tc "SELECT 1 FROM pg_database WHERE datname='maps_reviews_testing'" | grep -q 1 \
		|| $(COMPOSE) exec -T db psql -U maps -d maps_reviews -c "CREATE DATABASE maps_reviews_testing;"
	php artisan test
