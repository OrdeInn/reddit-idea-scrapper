.PHONY: up down build shell migrate seed fresh test logs queue key install horizon horizon-status horizon-pause horizon-continue horizon-terminate

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build --no-cache

shell:
	docker compose exec app bash

migrate:
	docker compose exec app php artisan migrate

seed:
	docker compose exec app php artisan db:seed

fresh:
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app php artisan test

logs:
	docker compose logs -f

queue:
	docker compose logs -f queue

horizon:
	@echo "Horizon dashboard: http://localhost:8080/horizon"

horizon-status:
	docker compose exec queue php artisan horizon:status

horizon-pause:
	docker compose exec queue php artisan horizon:pause

horizon-continue:
	docker compose exec queue php artisan horizon:continue

horizon-terminate:
	docker compose exec queue php artisan horizon:terminate

key:
	docker compose exec app php artisan key:generate

install:
	cp -n .env.example .env
	docker compose build --no-cache
	docker compose up -d
	@echo "Waiting for database to be ready..."
	@until docker compose exec db mysqladmin ping -h localhost -u root -ppassword >/dev/null 2>&1; do echo "Database is unavailable - sleeping"; sleep 2; done
	@echo "Database is ready!"
	docker compose exec app composer install
	docker compose exec node npm install
	docker compose exec app php artisan key:generate --force
	docker compose exec app chmod -R 775 storage bootstrap/cache
	docker compose exec app php artisan migrate
	@echo ""
	@echo "Installation complete!"
	@echo "App: http://localhost:8080"
	@echo "Horizon: http://localhost:8080/horizon"
	@echo "Vite: http://localhost:5173"
