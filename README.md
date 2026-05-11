<h1 align="center">Pogoda dla Śląska</h1>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white">
  <img alt="Bootstrap" src="https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white">
  <img alt="Tailwind CSS" src="https://img.shields.io/badge/Tailwind_CSS-CDN-38BDF8?logo=tailwindcss&logoColor=white">
  <img alt="Telegram" src="https://img.shields.io/badge/Telegram-Bot_API-26A5E4?logo=telegram&logoColor=white">
</p>

<p align="center">
  Nowoczesna, responsywna aplikacja PHP prezentująca 3-dniową prognozę pogody dla Śląska na podstawie publicznego kanału RSS serwisu <a href="https://pogodadlaslaska.pl">pogodadlaslaska.pl</a>.
</p>

## Najważniejsze funkcje

- automatyczne pobieranie najnowszej prognozy z RSS,
- parsowanie wpisu na prognozy dzienne,
- usuwanie zbędnych fragmentów źródłowego wpisu,
- rozpoznawanie warunków pogodowych: słońce, opady, burze, wiatr, mgła, śnieg, mróz i przymrozki,
- wyciąganie temperatur maksymalnych oraz średnich z zakresów,
- kolorystyczne karty prognozy dopasowane do typu pogody,
- responsywny interfejs dla desktopu i urządzeń mobilnych,
- widok mobilny z poziomą listą dni i rozwijanymi opisami,
- komunikat błędu, gdy prognoza nie może zostać pobrana,
- opcjonalne powiadomienia Telegram uruchamiane z CLI,
- mechanizm zapamiętywania ostatnio wysłanej prognozy, aby unikać duplikatów.

## Uruchomienie

Projekt jest jednoplikową aplikacją PHP. Do działania nie wymaga procesu buildowania ani instalowania zależności przez Composer lub npm.

```bash
php -S localhost:8000
```

Następnie otwórz:

```text
http://localhost:8000
```

## Konfiguracja Telegram

Powiadomienia Telegram są opcjonalne i działają w trybie CLI.

Wymagane zmienne środowiskowe:

```bash
TELEGRAM_BOT_TOKEN=token_bota
TELEGRAM_CHAT_ID=id_czatu
```

Opcjonalnie:

```bash
PUBLIC_PAGE_URL=https://twoja-domena.pl/pogoda/
```

Uruchomienie sprawdzania prognozy:

```bash
php index.php --telegram
```

Pierwsze uruchomienie zapisuje aktualny stan bez wysyłki. Wysyłkę testową można wymusić poleceniem:

```bash
php index.php --telegram --force
```

Po uruchomieniu trybu Telegram aplikacja tworzy plik:

```text
telegram_weather_state.json
```

Plik przechowuje zapis ostatnio obsłużonej prognozy i chroni przed ponowną wysyłką tej samej treści.

## Źródło danych

Prognozy pochodzą z publicznego kanału RSS:

```text
https://pogodadlaslaska.pl/blog/prognoza-krotkoterminowa/rss
```
