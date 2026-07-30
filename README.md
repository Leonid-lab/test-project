# Отчёты по ценам товаров

## Быстрый запуск

Для запуска потребуются Docker и Docker Compose. Make используется только как
короткая оболочка над Docker-командами.

```bash
git clone <repository-url>
cd <project-directory>
make init
```

После запуска доступна страница процессов:
http://localhost:8081/report-processes.

Сформировать отчёт по демонстрационной категории:

```bash
make report CATEGORY_ID=10
```

После выполнения обновите страницу процессов. Сформированные CSV скачиваются
только по ссылкам в таблице.

```bash
make check
make down
```

Полностью пересоздать тестовую базу и повторно загрузить demo-данные:

```bash
make fresh
```

Эквивалентный первый запуск без Make:

```bash
cp .env.example .env
docker compose build
docker compose up -d --wait
docker compose exec -T app composer install --no-interaction
docker compose exec -T app php artisan key:generate --no-interaction
docker compose exec -T app php artisan migrate --seed --force
docker compose exec -T app php artisan db:seed --class=DemoCatalogSeeder --force
docker compose exec -T app mkdir -p storage/app/private/reports
```

Повторный `make init` не удаляет данные. Разрушающее пересоздание схемы
выполняется только явной командой `make fresh`.

## О проекте

Artisan-команда принимает идентификатор категории и создаёт отдельный CSV для
каждого производителя, у которого есть цены за последние семь календарных
дней. Минимальная и максимальная записи выбираются оконным запросом PostgreSQL.

Ход каждого сформированного файла хранится в `report_process`. Blade-страница
показывает журнал, а приватные файлы скачиваются через контроллер после проверки
статуса, пути и существования файла.

## Стек

- PHP 8.5, Laravel 13, Blade;
- PostgreSQL 18 в Docker (поддерживается PostgreSQL 16+);
- PHPUnit и Laravel Pint;
- Nginx, PHP-FPM и Docker Compose.

## Docker-команды

```bash
make up
make down
make restart
make build
make install
make migrate
make seed
make shell
make logs
```

Сервисы Compose: `app`, `nginx`, `postgres`. Исходный код и приватное хранилище
смонтированы в `app`; данные PostgreSQL находятся в именованном volume.

## Демонстрационные данные

`make init` автоматически запускает `DemoCatalogSeeder`. Повторный ручной запуск:

```bash
make seed
# или
docker compose exec app php artisan db:seed --class=DemoCatalogSeeder
```

Seeder создаёт категории `10` и `20`, двух производителей, цены на границах
периода, повторяющиеся экстремумы, одну цену, устаревшую цену и товар без
актуальной цены. Рабочая демонстрационная категория — `10`.

## Формирование отчёта

```bash
make report CATEGORY_ID=10
# или
docker compose exec app php artisan report:generate 10
```

Пример результата:

```text
Reports generated successfully.
Period: 2026-07-23 — 2026-07-29
Manufacturers: 2
Files: 2
 - reports/report_1_10_2026-07-29_10-42-15.csv
 - reports/report_2_10_2026-07-29_10-42-15.csv
Total execution time: 0.042 s
```

Реальные дата, идентификаторы производителей и время выполнения зависят от
запуска.

## Просмотр процессов

- журнал: http://localhost:8081/report-processes;
Nginx не публикует `storage/app/private`; скачивание выполняется по маршруту с
`rp_id`.

## Запуск проверок

Все проверки выполняются внутри `app` и используют PostgreSQL:

```bash
make lint
make test
make check
```

Без Make:

```bash
docker compose exec app vendor/bin/pint --test
docker compose exec -e DB_DATABASE=product_report_test app php artisan test
```

## Формат CSV

- UTF-8 с BOM для корректного открытия в Excel, разделитель — запятая;
- заголовки: `manufacturer_name,product_name,price,price_date`;
- цена всегда имеет два знака после точки, дата — `Y-m-d`;
- имя: `report_{manufacturer_id}_{category_id}_{Y-m-d_H-i-s}.csv`;
- текст, начинающийся с `=`, `+`, `-` или `@`, получает ведущий апостроф.

## Архитектурные решения

Min/max и детерминированный выбор одинаковых цен выполняет PostgreSQL, поэтому
в PHP не загружается история цен. Репозиторий отдаёт строки курсором, а CSV
writer пишет их последовательно.

Файл сначала создаётся с уникальным временным именем в каталоге отчётов, затем
перемещается в итоговое имя на том же filesystem. Статус становится завершённым
только после перемещения. Долгая транзакция не используется: файловый I/O и
короткие обновления журнала разделены.

Скачивание идёт через контроллер, поскольку путь берётся только из БД и
проверяется относительно каталога отчётов. Отдельный файл на производителя
следует формату имени и сохраняет соответствие «файл — запись процесса».

## Допущения

- период — текущая дата приложения и шесть предыдущих дат включительно;
- товары без цен за период не входят в CSV;
- при единственной цене одна запись выводится дважды;
- для минимума при равенстве выбирается ранняя дата и меньший `price_id`, для
  максимума — поздняя дата и больший `price_id`;
- категория хранится как `category_id` товара, отдельный справочник категорий
  постановкой не задан;
- отсутствие товаров или актуальных цен — ожидаемая ошибка без error-level лога;
- в БД хранится только относительный путь на настроенном приватном disk.
