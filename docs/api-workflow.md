# API i workflow — projekt kontraktu v1

Kontrakt v1 zaimplementowano lokalnie w osobnej gałęzi `feat/application-v1` repozytorium Blancco_API. API nie zostało wdrożone. Kandydat eksportu BAR znajduje się w `resources/workflows/install-v1.json`; wymaga importu i próby na urządzeniu.

## Zasady kontraktu

JSON, Bearer, daty ISO 8601 z offsetem lub Z, niejawne lokalne daty niedopuszczalne. Odpowiedź ma wyłącznie potrzebne pola; bez SELECT * i bez kluczy licencyjnych. `useDpk` jest booleanem. Jeżeli BAR wymaga tekstu, dodatkowe `hasDpk` ma dokładnie YES/NO z tej samej wartości.

Biznesowe decyzje lookup zwracają 200 i pole decision. Błąd autoryzacji 401, złe wejście 400, niedostępna baza 503. Nie traktujemy błędów technicznych jako zgody na ręczny tryb. Nagłówki Cache-Control: no-store. Decyzje: AUTO, ASK_RETRY, MANUAL, WAIT, BLOCKED, BUSY.

## Operacje

| Operacja | Wejście | Wyjście / efekt |
|---|---|---|
| POST /v1/request-key | puste body lub {} | nowy requestKey dla uruchomienia workflow; bez zapisu w bazie |
| GET /v1/lookup?serial=… | jeden serial | decyzja, jobId nullable, configurationVersion, imageName, useDpk, hasDpk, orderNumber, installedAt, validUntil, retryUntil, reasonCode, message; bez zapisów |
| POST /v1/attempts/start | JSON: serial, jobId nullable, configurationVersion dla trybu order, mode, requestKey, retryConfirmed | ponowna walidacja; 201 nowa próba/200 identyczne ponowienie; attemptId, utrwalona konfiguracja |
| POST /v1/attempts/{attemptId}/complete | puste body | 200 i niezmienny finishedAt; tylko potwierdzenie Reimage |
| POST /v1/attempts/{attemptId}/fail | JSON: failureCode | 200; wynik błędu, bez nadpisywania sukcesu |

Start: 409 dla zmiany wybranego zlecenia/konfiguracji lub zajętego urządzenia; 422 dla braku wymaganej zgody/niepoprawnej kombinacji pól. Callback: 404 dla nieznanej próby; 409 dla sprzecznego wyniku. Błędy: code, message, retryable, requestId. Kody decyzji nie zależą od tłumaczenia komunikatu.

Zlecenie order zawsze odnajdujemy ponownie po aktualnym wyborze urządzenia; sam jobId nie omija anulowania lub terminu. Dla ASK_RETRY wymagane retryConfirmed=true i świadoma odpowiedź operatora. Tryb manual nie oznacza realizacji dowolnego zamówienia; jobId=null, zapis próby urządzenia.

Workflow pobiera `requestKey` raz na początku z `POST /v1/request-key` i zachowuje w zmiennej przy ponawianiu startu. Nie wymaga generatora UUID po stronie BAR. Ponowienie pobrania klucza przed startem jest bezpieczne, bo nie tworzy próby. Po wysłaniu startu nie wolno pobierać nowego klucza do ponowienia tego samego żądania. Nowy restart workflow dostaje nowy klucz; nierozstrzygnięta poprzednia próba blokuje drugą instalację.

Start zwraca także `state`. Tylko `started` prowadzi do Reimage; idempotentna odpowiedź dla już zakończonej próby nie może ponownie uruchomić instalacji.

## Przykład lookup

```json
{
  "decision": "ASK_RETRY",
  "jobId": "123",
  "configurationVersion": "2",
  "imageName": "1WIN 11 PRO PL",
  "useDpk": true,
  "hasDpk": "YES",
  "orderNumber": "ZAM/123",
  "installedAt": "2026-11-17T13:00:00Z",
  "retryUntil": "2026-11-17T23:00:00Z",
  "reasonCode": "ALREADY_INSTALLED_TODAY",
  "message": "Komputer był już instalowany dzisiaj. Wybierz ponowienie według zamówienia lub instalację ręczną."
}
```

To przykład projektowanego kontraktu, nie odpowiedź działającego serwera. 23:00Z odpowiada północy w Polsce w listopadzie.

## Nowy workflow

1. Booted: wyzeruj SerialNumber, JobId, AttemptId, ImageName, HasDpk, Decision, komunikaty i kody HTTP.
2. Odczytaj oryginalny serial z raportu sprzętowego.
3. GET lookup; rozdziel problem techniczny od decyzji biznesowej.
4. AUTO: konfiguracja zamówienia. ASK_RETRY: pokaż datę, dokument i obraz; pytanie „Według zamówienia” / „Ręcznie”.
5. MANUAL: ręczny wybór obrazu i DPK. WAIT/BLOCKED: pokaż konkretny powód, ponów odczyt lub świadomie wybierz ręcznie. BUSY: brak drugiej równoległej instalacji; sprawdzenie bieżącej próby.
6. Start próby przed Reimage. Błąd startu nie przechodzi do automatycznego Reimage. Konfigurację zamówienia pobierz z odpowiedzi start, nie z potencjalnie starego GET.
7. Reimage z kluczem/bez klucza. Sukces: complete z attemptId; błąd: fail z tym samym identyfikatorem.
8. Gdy complete nie udało się zapisać, komunikat „Reimage zakończony. Nie zapisano potwierdzenia.” i ponowienie samego callback. Nigdy automatycznie ponownie do Reimage.
9. Send Report i Finish. Błąd wysłania raportu nie cofa sukcesu Reimage. Zapis sukcesu i raport to osobne wyniki.

Przy awarii lookup: informacja o niedostępności i ponowienie. Ewentualny awaryjny start całkowicie poza rejestracją wymaga jawnej procedury, nie ukrytej gałęzi fallback.

## Ograniczenia do sprawdzenia w BAR

Załączony JSON potwierdza zmienne, Server Message, status HTTP, warunki, Reimage z obrazu lub prompt oraz Send Report. Nie potwierdza: generatora identyfikatora próby, dostępu do obrazu wybranego w prompt, danych umożliwiających bezbłędne odzyskanie próby po restarcie ani identycznego działania obu POST z pustym body.

Próby ręczne można zapisać bez nazwy wybranego obrazu, jeśli edytor jej nie udostępnia; panel pokazuje „wybór ręczny — obraz nieprzekazany”, zamiast zgadywać. Wyboru klucza dla manual dokonuje operator jak w obecnym workflow. Rejestracja zdarzenia nie dowodzi użycia klucza po restarcie.

## Odczyt raportów

Laravel pyta chmurowe API Blancco po serialu. Korzysta z ustalonego i przetestowanego kontraktu eksportu; przed implementacją należy odzyskać dokładny filtr, paginację i zakres dat z dokumentacji lub aktualnej kolekcji. Nie zakładamy, że samo 200 dowodzi filtrowania. Test kontrolny znanego i nieistniejącego serialu obowiązkowy.

Panel pokazuje wszystkie pasujące raporty, a nie automatycznie pierwszy/najnowszy. UUID jest używany do pobrania wskazanego raportu, bez kolumny UUID w zleceniu. Obsługujemy limit/paginację i jawnie pokazujemy zakres historii, jeśli API go ogranicza.
