# Model danych i cykl życia

Propozycja logiczna, nie wykonana migracja. Wszystkie tabele w bazie `blancco`.

## Tabele

| Tabela | Istotne pola / ograniczenia |
|---|---|
| users | email UNIQUE, password, role admin/user, active; tylko admin tworzy użytkowników |
| orders | source, external_id, document_number, source_created_at, source_updated_at, country, source_status; UNIQUE(source, external_id) |
| order_items | order_id, external_line_id, product_id, description, quantity, wymagania; UNIQUE(order_id, external_line_id) |
| devices | id, serial_original, serial_lookup UNIQUE, manufacturer, model; serial bez prefiksu Lenovo |
| device_identifiers | device_id, typ i wartość identyfikatora; nie zastępują oryginalnego serialu |
| device_assignments | order_id, device_id, source_assignment_key UNIQUE, source_assigned_at, first_seen_at, withdrawn_at; niezmienne pochodzenie i kolejność |
| installation_jobs | assignment_id, revision, source_type, preparation_status, lifecycle_status, blocked_reason, image_id, image_name_snapshot, use_dpk BOOLEAN, rule_version, configuration_hash, created_at, first_valid_until, first_success_at, last_success_at, retry_until, cancelled_at; UNIQUE(assignment_id, revision) |
| installation_attempts | UUID id, device_id, job_id nullable, mode order/manual, request_key UNIQUE, state, started_at, finished_at, config_snapshot, failure_code; wynik dotyczy tej próby |
| device_execution_locks | device_id PRIMARY KEY, attempt_id UNIQUE, acquired_at, timeout_at; jeden aktywny Reimage na urządzenie |
| images | name UNIQUE, system, edition, language, active; dokładna nazwa BAR |
| image_rules | source_type, country, windows_version, priority, image_id, version; jawna kolejność i brak nakładających się nierozstrzygniętych reguł |
| license_rules | source_type, warunki, use_dpk, requires_coa, version; reguły odtworzone ze starego procesu |
| device_license_data | device_id, source, rodzaj, encrypted_value, checked_at; bez kluczy w zwykłych snapshotach |
| product_group_mappings | external_product_id, windows_group; odtworzenie mapowania IdoSell, nie kopiowanie zamówień |
| sync_runs | source, started_at, finished_at, state, counts, safe_error; bez sekretów i kluczy |
| sync_cursors | source, scope, cursor, last_success_at; UNIQUE(source, scope) |
| source_snapshots | entity_type, entity_id, source, schema_version, safe_payload, fetched_at; tylko potrzebne dane, bez nagłówków auth i kluczy |
| integration_outbox | operation_key UNIQUE, destination, type, safe_payload, state, attempts, next_attempt_at, external_result_id |
| audit_events | actor_type/id, entity_type/id, event, redacted_changes, created_at; append-only |

FK dla powiązań, indeksy status/czas, serial, dokument i źródło. Usuwanie użytkownika nie usuwa historii. Nie kasujemy kaskadowo zleceń ani prób. UUID raportu nie jest kolumną zlecenia; identyfikator otrzymany z chmury służy do otwarcia wskazanego raportu.

Normalizacja serialu: zachowujemy oryginał i usuwamy wyłącznie brzegowe białe znaki do wyszukiwania. Zmiana wielkości liter/kolacji wymaga sprawdzenia źródeł; brak niejawnego sklejania numerów. Niejednoznaczna tożsamość blokuje automat.

## Statusy

Przygotowanie: `pending`, `ready`, `blocked`. Cykl życia: `open`, `completed`, `cancelled`, `expired`. Stan próby: `started`, `succeeded`, `failed`, `unknown`.

Oddzielenie stanów jest celowe: ukończone zlecenie może umożliwiać ponowienie; wygaśnięcie nie usuwa informacji o sukcesie. Zgoda na start jest obliczana z dat, stanu, kompletności i blokady urządzenia, nigdy tylko z etykiety statusu.

## Tożsamość i kolejność

Przypisanie identyfikujemy po stabilnym kluczu źródła (preferowana identyfikacja zapisu BC). Jeśli źródło nie daje wersji przypisania, dokument+serial jest kluczem deduplikacji. Wycofanie i ponowne przypisanie w tym samym dokumencie nie tworzy bez dowodu nowego zlecenia.

Najnowsze oznacza datę nowego przypisania ze źródła, z utrwalonym tie-breakerem. `updated_at` synchronizacji nigdy nie nadaje pierwszeństwa. Stare dane wykryte z opóźnieniem nie mogą przebić nowszego przypisania. Jednoczesne sprzeczne przypisania bez rozstrzygającej chronologii dają blokadę, nie losowy wybór.

Jedno zamówienie może mieć wiele urządzeń. Wspólna konfiguracja jest kopiowana do zlecenia każdego urządzenia; nie tworzymy fikcyjnych numerów zamówienia `_1`, `_2`. Niejednoznaczna konfiguracja pozostaje widoczna jako blokada.

## Algorytm wyboru

1. Znajdź urządzenie po serialu. Nieznane urządzenie: MANUAL.
2. Pobierz przypisania/zlecenia od najnowszego, w stabilnej kolejności.
3. Pomiń zlecenia anulowane (zatwierdzony wyjątek powrotu do starszego). Wycofane przypisanie nie może być użyte; traktujemy je jako wyłączone z wyboru i zapisujemy przyczynę.
4. Dla pierwszego pozostałego oceń kompletność i daty. Brak danych: WAIT/BLOCKED. Wygaśnięcie: MANUAL, bez poszukiwania starszego. Brak sukcesu i ważność: AUTO. Sukces i otwarte okno ponowienia: ASK_RETRY.
5. Gdy brak kandydata: MANUAL. Gdy istnieje aktywna próba urządzenia: BUSY.

Sam GET niczego nie anuluje, nie odnawia i nie rozpoczyna. Powrót do starszego nie odnawia jego dat ani konfiguracji. Nowe zlecenie nie usuwa historii starszego.

## Granice czasu

`first_valid_until` = początek dnia utworzenia w Europe/Warsaw + 3 dni kalendarzowe, zapisany w UTC. Termin wyłączny: poniedziałkowe zlecenie działa do czwartku 00:00, czyli do końca środy. Nie liczymy 72 godzin ani 3 dni od osiągnięcia gotowości.

Pierwszy sukces ustawia `first_success_at` i `retry_until` = początek następnego dnia w Polsce. Kolejne sukcesy aktualizują tylko `last_success_at`, nie przesuwają okna ponowienia. Przy pierwszym starcie termin musi jeszcze obowiązywać. Dokończenie już rozpoczętej próby można zapisać po granicy, lecz nie otwiera ono nowego okna ponowień, jeśli termin zlecenia już minął (propozycja techniczna na przypadek brzegowy).

Start wymaga aktualnej zgody w transakcji. Odczyt z 23:59 nie uprawnia do rozpoczęcia o 00:01. Cron jedynie aktualizuje prezentację statusów; nie jest źródłem prawdy o terminie.

## Transakcje i zmiana konfiguracji

Start blokuje wiersz urządzenia i zlecenia, ponownie ocenia wybór i zapisuje próbę z niezmiennym snapshotem. Anulowanie i start używają tej samej kolejności blokad. Zmiana konfiguracji między GET i start daje konflikt i wymaga ponownego lookup.

Ponowienie requestu start z tym samym request_key zwraca ten sam attempt_id. Nowa instalacja wymaga nowego request_key. Callback sukcesu jest idempotentny per attempt_id. Wynik sprzeczny z już zapisanym daje konflikt. Wynik starej próby nie oznacza sukcesu nowego zlecenia na ten sam serial.

Nie utrzymujemy transakcji SQL podczas operacji sieciowych ani Reimage. Timeout próby oznacza `unknown`, nie automatyczny sukces lub zgodę na kolejny start. Rozstrzygnięcie wymaga sprawdzenia przez operatora; konkretna długość timeoutu po pomiarze na sprzęcie.

Zmiany źródła aktualizują konfigurację zlecenia tylko poza aktywną próbą i bez odnawiania dat. Po rozpoczęciu snapshot próby jest stały. Zmiana po sukcesie wymaga jawnej, audytowanej decyzji o nowym zleceniu; synchronizacja nie daje nowej zgody sama z siebie.
