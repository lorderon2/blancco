# Blancco

Aplikacja Laravel do przygotowania zleceń instalacji oraz obsługi danych Blancco. Repozytorium GitHub: `lorderon2/blancco`. Wdrożenie testowe: https://blancco.amso365.com (2026-11-17). Integracje zewnętrzne pozostają wyłączone do dopasowania konfiguracji źródeł.

## Uruchomienie lokalne

PHP 8.4+, Composer. Panel Blade korzysta z `public/app.css`; do działania obecnego interfejsu nie wymaga uruchamiania Vite.

```sh
composer install
cp .env.example .env
php artisan key:generate
# Utwórz pusty plik database/database.sqlite, jeśli jeszcze go nie ma.
php artisan migrate
php artisan blancco:admin twoj-email@example.com
```

Polecenie administratora pyta o hasło bez pokazywania go i bez zapisywania w historii shella. Nie ma kont ani haseł domyślnych. W istniejącym środowisku nie nadpisuj .env i nie generuj ponownie APP_KEY.

W Laravel Herd panel jest pod `http://blancco.test/login`. Produkcyjnie planowane jest `blancco.amso365.com`, a baza `blancco` na `192.168.12.203`. Lokalnie używana jest SQLite. Wdrożenie testowe korzysta z nowej bazy `blancco` na 192.168.12.203, bez importu historii KeyDeploy.

## Dostępne funkcje

- Logowanie, reset hasła, role administrator/użytkownik, tworzenie kont tylko przez administratora.
- Lista i szczegóły zleceń, wyszukiwanie, filtry, ręczny import seriali, anulowanie i audyt.
- Dodawanie dokumentu BC z konfiguracją oraz podgląd dokumentów oczekujących na serial.
- Ważność trzech dni kalendarzowych, ponowienia w dniu sukcesu, powrót do starszego ważnego zlecenia po anulowaniu.
- Rejestrowanie prób i ręczne rozstrzyganie prób bez potwierdzenia; blokada równoległego Reimage.
- Konektory BC SQL, AIKEN, oddziałów i IdoSell; historia przebiegów synchronizacji.
- Lista raportów Blancco per serial, wszystkie strony, podgląd danych i PDF.
- Outbox Bitrix HOME z ochroną przed ponownym wysłaniem niepewnego zapisu. Automatyczny trigger HOME i zastępstwo KeyDeploy /dpk wymagają ustalenia rzeczywistego źródła; nie są włączone ani zgadywane.

Wszystkie integracje są domyślnie wyłączone. Konfiguracja jest w `.env.example`, bez sekretów. Import wymaga jawnego `BLANCCO_IMPORT_FROM` i aktualnego mapowania produktów w `product_group_mappings`. Mapowanie to konfiguracja, nie historia zamówień.

## Zadania aplikacji

Po skonfigurowaniu źródeł scheduler uruchamia `blancco:sync-idosell`, `blancco:sync bc`, `blancco:sync departments`, `blancco:expire`, `blancco:detect-stalled` oraz `blancco:dispatch-bitrix`. Na produkcji potrzebny jest standardowy wpis `schedule:run` co minutę; jest zainstalowany w `/etc/cron.d/blancco`. Integracje wyłączone w konfiguracji nie wykonują pobierania.

Oddzielne API Python jest przygotowane w repozytorium Blancco_API na gałęzi `feat/application-v1`. Nowe API uruchomiono jako `blancco-application-api` na 192.168.12.203:8089. Stary api.py nie został podmieniony; fizyczny workflow należy przełączyć dopiero podczas próby na urządzeniu.

## Testy

```sh
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Test prawdziwego HTTP `IdosellHttpTest` wymaga `BLANCCO_SIM_URL` wskazującego lokalny serwer, w pozostałych uruchomieniach jest pomijany. Lokalny mock jest w `postman/mocks/idosell/default.js`. Nie wolno używać do tego testu rzeczywistego endpointu.

Izolowany test MariaDB i API (wymaga PyMySQL, mariadb-install-db i mariadbd):

```sh
python3 tests/run_mysql.py --api-root /sciezka/do/worktree/Blancco_API
```

Runner sam tworzy tymczasowy katalog, bazę `blancco_test_*` i serwer bez TCP. Nie czyta konfiguracji produkcyjnej. Sprawdza zgodność wspólnych fixture kontraktu, wykonuje migracje, testy PHP oraz Python i zatrzymuje serwer.

## Dokumentacja i workflow

- [Specyfikacja](docs/specyfikacja.md)
- [Model danych](docs/model-danych.md)
- [API i workflow](docs/api-workflow.md)
- [Reguły i brakujące dowody](docs/reguly-i-weryfikacja.md)
- [Kandydat eksportu workflow do importu testowego](resources/workflows/install-v1.json)

Eksport nie zawiera tokena. Należy uzupełnić `REPLACE_WITH_API_HOST` i `REPLACE_WITH_API_TOKEN`. Struktura i połączenia są przetestowane lokalnie; import do edytora BAR i wykonanie na urządzeniu pozostają do sprawdzenia. Ponowny eksport tworzy `python3 resources/workflows/build.py`.

## Granice aktualnej weryfikacji

Testy sprawdzają lokalny kod, syntetyczne dane źródeł i wspólną testową MariaDB. Nie potwierdzają rzeczywistego crontaba, uprawnień źródeł, kompletności mapowania produktów, wariantu reguł COA/PRE ani opóźnienia 10 minut na produkcji. Odczyt produkcji, utworzenie zdalnej bazy, konfiguracja sekretów i wdrożenie wymagają osobnej autoryzacji.

Postman CLI 1.74 uruchamia simulation z błędem modułu datasets w tym środowisku; prawdziwy HTTP sprawdzono tym samym mockiem uruchomionym bezpośrednio przez Node. Wyszukiwanie mocków właściciela pominięto (brak działającego `postman status`), Orbit nie znalazł kontraktu IdoSell. Konektor bazuje na wywołaniu legacy; zgodność aktualnego endpointu trzeba sprawdzić przed włączeniem.

## Stan wdrożenia testowego

Panel HTTPS, konto administratora, migracje, scheduler i API v1 zostały uruchomione. Sprawdzono logowanie i pełny cykl API na syntetycznym zleceniu, następnie je anulowano; nie wykonywano fizycznego Reimage. Konfiguracje nginx/crona bez sekretów są w resources/deployment. Hasła, .env i token API nie są w Git.

Na serwerze dotychczasowy cron używa BC w trybie API, a nie SQL. Adapter API BC korzysta z potwierdzonego pola N24C_SystemCreatedAt i Entry_No; wariant SQL pozostaje dostępny jako osobna konfiguracja. Klucz chmurowego eksportu raportów oraz reguły Bitrix wymagają osobnej konfiguracji. Fizyczny import i uruchomienie workflow w BAR pozostają do próby operatora.
