COMPOSE := docker compose

.PHONY: init up down restart build install migrate seed fresh report test lint check shell logs

init:
	@test -f .env || cp .env.example .env
	$(COMPOSE) build
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec -T app composer install --no-interaction
	$(COMPOSE) exec -T app sh -lc 'grep -q "^APP_KEY=base64:" .env || php artisan key:generate --no-interaction'
	$(COMPOSE) exec -T app php artisan migrate --seed --force
	$(COMPOSE) exec -T app php artisan db:seed --class=DemoCatalogSeeder --force
	$(COMPOSE) exec -T app mkdir -p storage/app/private/reports
	@echo ""
	@echo "Проект успешно запущен."
	@echo ""
	@echo "Страница процессов:"
	@echo "http://localhost:8081/report-processes"
	@echo ""
	@echo "Сформировать демонстрационный отчёт:"
	@echo "make report CATEGORY_ID=10"
	@echo ""
	@echo "Запустить все проверки:"
	@echo "make check"
	@echo ""
	@echo "Остановить проект:"
	@echo "make down"

up:
	$(COMPOSE) up -d --wait

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

build:
	$(COMPOSE) build

install:
	$(COMPOSE) exec app composer install

migrate:
	$(COMPOSE) exec app php artisan migrate --seed

seed:
	$(COMPOSE) exec app php artisan db:seed --class=DemoCatalogSeeder

fresh:
	$(COMPOSE) exec app php artisan migrate:fresh --seed
	$(COMPOSE) exec app php artisan db:seed --class=DemoCatalogSeeder

report:
	@test -n "$(CATEGORY_ID)" || (echo "Необходимо указать CATEGORY_ID. Пример: make report CATEGORY_ID=10" && exit 1)
	$(COMPOSE) exec app php artisan report:generate "$(CATEGORY_ID)"

test:
	$(COMPOSE) exec -e DB_DATABASE=product_report_test app php artisan test

lint:
	$(COMPOSE) exec app vendor/bin/pint --test

check: lint test

shell:
	$(COMPOSE) exec app sh

logs:
	$(COMPOSE) logs -f
