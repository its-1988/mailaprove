# Approval By Mail for GLPI 11

GLPI 11 plugin that lets users **approve, reject and rate tickets directly
from their inbox**. Tokenized one-click links — no login required.

> **Fork notice:** This repository is a fork of
> [celsocaninde/mailaprove](https://github.com/celsocaninde/mailaprove)
> adapted to GLPI 11.0.7. See [Изменения в форке](#-изменения-в-форке) for
> the full Russian changelog. Bumped to **1.0.4**.

[![License: GPL-3.0+](https://img.shields.io/badge/License-GPLv3%2B-blue.svg)](LICENSE)
[![GLPI 11.0.7+](https://img.shields.io/badge/GLPI-11.0.7%2B-2C8DBF.svg)](https://github.com/glpi-project/glpi)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net/)

---

## Features

| Feature | Description |
|---------|-------------|
| **Validation approval** | Approve / reject ticket validations from e-mail (`##ticket.validation.accepturl##`, `##ticket.validation.rejecturl##`) |
| **Solution acceptance** | Accept / reject proposed solutions; ticket auto-closes on accept |
| **Satisfaction survey** | 1–5 star rating + free-text feedback |
| **Token-based auth** | Cryptographic single-use tokens, SHA-256 hashed in DB, configurable TTL |
| **GLPI 11 ready** | Symfony router, Firewall strategies, automatic CSRF handling, group-target validations (`itemtype_target`/`items_id_target`) |
| **Audit trail** | Every token life-cycle event + every public action stored with IP, user-agent, payload — exportable to CSV |
| **i18n** | English (en_US), Brazilian Portuguese (pt_BR, source), Russian (ru_RU) |

---

## Requirements

- **GLPI**: ≥ 11.0.0 (verified on 11.0.7)
- **PHP**: ≥ 8.2
- **MySQL/MariaDB**: any version supported by GLPI 11

---

## Installation

### From release archive

```bash
cd /var/glpi/marketplace/   # or /var/glpi/plugins/
tar xzf mailaprove-1.0.4.tar.gz
```

### From source

```bash
cd /var/glpi/marketplace/
git clone https://github.com/its-1988/mailaprove.git
```

Then in GLPI as super-admin:

1. **Setup → Plugins**
2. Find **"Approval By Mail"** → **Install**
3. → **Enable**

The plugin creates three tables: `glpi_plugin_mailaprove_tokens`,
`glpi_plugin_mailaprove_configs`, `glpi_plugin_mailaprove_auditlogs` and
registers a daily cron task `CleanExpiredTokens`.

---

## Configuration

**Setup → Plugins → Approval By Mail** (or the gear icon).

| Option | Default | Description |
|---|---|---|
| Token expiration (hours) | 72 | How long an action link stays valid |
| Used token retention (days) | 30 | Keep used tokens for audit before purge |
| Audit retention (days) | 180 | Audit-log purge horizon |
| Enable validation / solution / satisfaction | on | Per-feature switches |
| Custom button templates | empty | Override the default HTML used in tag substitution. Variables: `{{approve_url}}`, `{{reject_url}}`, `{{accept_url}}`, `{{survey_url}}`, `{{ticket_id}}`, `{{ticket_name}}` |

---

## Notification template setup

The plugin **injects values** into custom tags but does **not** add them
to GLPI notification templates automatically. You add them once in
**Setup → Notifications → Notification templates** to the templates you
already use:

### Ticket validation template

```html
<p>To approve, click here:
   <a href="##ticket.validation.accepturl##" style="background:#28a745;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">Approve</a>
</p>
<p>To reject, click here:
   <a href="##ticket.validation.rejecturl##" style="background:#dc3545;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">Reject</a>
</p>
```

Or use the pre-built block:

```html
##ticket.validation.buttons##
```

### Solution template

```html
##ticket.solution.buttons##
```

or individual tags `##ticket.solution.accepturl##`, `##ticket.solution.rejecturl##`.

### Satisfaction template

```html
##ticket.satisfaction.button##
```

or `##ticket.satisfaction.url##`.

The config page also shows copy-paste HTML snippets with all styling
already applied.

---

## How it works

1. GLPI raises a notification event (`validation`, `solved`,
   `satisfaction`, ...).
2. The plugin's `ITEM_GET_DATA` hook intercepts data assembly, looks up
   the relevant row (validation / solution / satisfaction), figures out
   who the authorized recipient is, and mints a **cryptographically
   random** token. Its SHA-256 hash is stored in
   `glpi_plugin_mailaprove_tokens`; the raw token is appended to the
   action URL injected into the e-mail.
3. User clicks the link → reaches a stateless plugin endpoint
   (e.g. `/plugins/mailaprove/front/approve.php?token=...`).
4. The endpoint hashes the token, looks it up, verifies expiry &
   single-use atomically, then **writes the decision directly through
   `$DB`** (bypassing the model layer because the request has no GLPI
   session) and raises the matching `validation_answer` / `solved` /
   `rejectsolution` / `replysatisfaction` notification.
5. The token is marked used; all related tokens for the same
   item are invalidated.

---

## URL endpoints

| Endpoint | Method | Purpose |
|---|---|---|
| `/plugins/mailaprove/front/approve.php` | GET/POST | Approve ticket validation |
| `/plugins/mailaprove/front/reject.php` | GET/POST | Reject ticket validation |
| `/plugins/mailaprove/front/solution_approve.php` | GET/POST | Accept solution (closes ticket) |
| `/plugins/mailaprove/front/solution_reject.php` | GET/POST | Reject solution |
| `/plugins/mailaprove/front/satisfaction.php` | GET/POST | Submit satisfaction rating |
| `/plugins/mailaprove/front/config.form.php` | GET/POST | Plugin configuration (admin) |
| `/plugins/mailaprove/front/audit.php` | GET | Audit-log viewer + CSV export |

The five "public" endpoints are registered as **stateless** via
`SessionManager::registerPluginStatelessPath()` and get
`STRATEGY_NO_CHECK` from `Firewall::addPluginStrategyForLegacyScripts()`
— GLPI does not enforce session/CSRF on them. Admin endpoints get
`STRATEGY_CENTRAL_ACCESS`.

---

## Localization

Translation files live in `locales/` (note the **`s`** — required by
GLPI 11). Supplied locales:

| Code | File | Source for |
|---|---|---|
| `pt_BR` | (none — strings are the source language) | Brazilian Portuguese |
| `en_US` | `locales/en_US.mo` | English |
| `ru_RU` | `locales/ru_RU.mo` | Russian |

The `.mo` files are pre-compiled. If you edit `*.po` and need to
re-build the `.mo`, run:

```bash
python3 locales/compile_mo.py locales/ru_RU.po
```

(`compile_mo.py` is a pure-Python `.po` → `.mo` compiler — no
`msgfmt`/gettext-tools required.)

After updating a translation: **Setup → General → Reset cache** and
often a PHP-FPM / container restart so opcache forgets the old `.mo`.

---

## Project structure

```
mailaprove/
├── README.md                # this file
├── LICENSE                  # GPLv3+
├── composer.json            # PHP package metadata
├── hook.php                 # install/uninstall + DB migrations
├── setup.php                # plugin entry-point, routes, firewall, hooks
├── ajax/
│   └── template.preview.php # custom-template HTML preview (admin)
├── front/
│   ├── approve.php          # validation approval endpoint (stateless)
│   ├── reject.php           # validation rejection endpoint (stateless)
│   ├── solution_approve.php # solution acceptance endpoint (stateless)
│   ├── solution_reject.php  # solution rejection endpoint (stateless)
│   ├── satisfaction.php     # satisfaction survey endpoint (stateless)
│   ├── config.form.php      # config UI (admin)
│   └── audit.php            # audit log viewer (admin)
├── src/
│   ├── AuditLog.php         # audit logging service
│   ├── Config.php           # configuration model
│   ├── NotificationHandler.php  # ITEM_GET_DATA hook
│   ├── PublicAction.php     # token validation + stateless DB writers
│   └── Token.php            # token generation, claim, cleanup cron
├── templates/
│   ├── layout.php           # public-page chrome (header/footer)
│   ├── action_confirm.php   # "are you sure?" confirmation
│   ├── confirm.php          # post-action success page
│   ├── error.php            # token error page
│   ├── reject_form.php      # rejection reason form
│   └── satisfaction_form.php  # star rating form
├── locales/
│   ├── compile_mo.py        # .po → .mo pure-Python compiler
│   ├── en_US.po / en_US.mo
│   ├── ru_RU.po / ru_RU.mo
│   └── pt_BR.po
└── assets/
    └── icon.svg
```

---

## Troubleshooting

**Plugin won't install / not visible**
- Verify `chmod -R 755 mailaprove/` and ownership matches the web user.
- Reset cache: **Setup → General → Reset cache**.

**Buttons don't appear in e-mail**
- Check the notification template **of the right event** has the tags
  embedded — for the GLPI 11 validation flow the event is `validation`,
  for solution it's `solved`, for surveys it's `satisfaction`.
- Open `audit.php` after sending a test mail and look for
  `hook_invoked` + `validation_url_ok` / `token_created` rows.

**"Action not allowed" when clicking the link**
- Token already used (single-use), or expired, or someone else clicked
  the related accept/reject pair. Generate a fresh validation/solution.

**Action confirmed in browser but ticket unchanged in GLPI**
- Should not happen any more in this fork — the stateless endpoints
  write through `$DB` and recompute `global_validation`. If you still
  see it, attach the new `audit.php` rows for the action.

---

## 🇷🇺 Изменения в форке

Этот форк адаптирован под **GLPI 11.0.7** и закрывает критические
проблемы оригинала. Если коротко — оригинал на 11.0.7 не работал
вообще (XML-ошибка при сохранении настроек) и логически был сделан под
схему GLPI ≤ 10.

### Исправления GLPI 11 совместимости

- **XML-ошибка при сохранении конфига.** Под Symfony front-controller
  GLPI 11 `$_SERVER['PHP_SELF']` отдаёт `/index.php`, и POST формы
  попадал в обработчик инвентаря (`Glpi\Agent\Communication\AbstractRequest`)
  — он пытался распарсить form-data как XML. Заменено на абсолютный URL
  `$CFG_GLPI['root_doc'] . '/plugins/mailaprove/...'`, POST-хендлер
  перенесён ПЕРЕД `Html::header()`.
- **Двойная проверка CSRF.** `Session::checkCSRF()` в коде плагина
  стрелял `AccessDeniedHttpException` потому что middleware GLPI 11 уже
  валидировал и **потреблял** single-use токен до нашего скрипта. Убрал
  ручную проверку, оставил скрытый `_glpi_csrf_token` в форме.
  `Hooks::CSRF_COMPLIANT` deprecated с 11.0.0 — убрал.
- **Регистрация маршрутов в Firewall.** Без явного
  `Firewall::addPluginStrategyForLegacyScripts()` GLPI 11 не понимал
  что для публичных endpoints не нужна сессия — добавлен
  `STRATEGY_NO_CHECK` для mail-ссылок и `STRATEGY_CENTRAL_ACCESS` для
  админских страниц.
- **`SessionManager::registerPluginStatelessPath()`** — публичные
  endpoints (approve/reject/...) теперь явно объявлены stateless.
- **Пути в Docker.** Все `include(GLPI_ROOT . '/plugins/mailaprove/...')`
  заменены на `__DIR__ . '/../templates/...'` чтобы работать когда
  плагин стоит из marketplace (`/var/glpi/marketplace/`), а не из
  `/var/glpi/plugins/`.
- **Bootstrap include.** `include('../../../inc/includes.php')` обёрнут
  в `if (!defined('GLPI_ROOT'))` — `LegacyFileLoadController` GLPI 11
  уже загрузил GLPI к моменту вызова плагина, повторный include ломался
  в Docker-сетапах с volume-маунтами.

### Логические баги

- **Поиск валидатора по новой схеме.** В GLPI 11 `glpi_ticketvalidations`
  хранит цель в `(itemtype_target, items_id_target)` —
  `users_id_validate` равен **0** для новых строк. Старый код смотрел
  только в `users_id_validate` и не находил никого. Теперь:
  - `itemtype_target = 'User'` → валидатор = `items_id_target`;
  - `itemtype_target = 'Group'` → токен выдаётся только тому участнику
    группы, кому идёт письмо, после проверки членства в группе;
  - fallback на `users_id_validate` для строк, мигрированных из 10.x.
- **Безопасность.** Старый код при неизвестном получателе брал ЛЮБУЮ
  ожидающую валидацию и выдавал токен — то есть любой получатель письма
  мог утвердить за другого. Теперь получатель определяется через
  `validation_id` из `$target->options` (GLPI 11 передаёт его в
  notification target) и сверяется с целью валидации.
- **Имена событий уведомлений.** Старый код слушал `'solution'` (такого
  события нет в GLPI 11). Корректные события для GLPI 11:
  `solved`, `rejectsolution`, `validation`, `validation_answer`,
  `validation_reminder`, `satisfaction`, `replysatisfaction`.
- **`$validation->update()` молча отбрасывал поля.** Stateless-эндпоинт
  работает без GLPI-сессии → `prepareInputForUpdate()` из
  `CommonITILValidation` считал что "текущий юзер ≠ валидатор" (current
  user = 0) и **тихо стрипал** `status`/`validation_date`/`comment_validation`.
  `update()` возвращал true, но в БД статус валидации не менялся.
  Добавил `PublicAction::applyValidationDecision()`,
  `applySolutionDecision()` и `applySatisfactionResponse()` — пишут
  через `$DB->update()` напрямую, пересчитывают `global_validation`
  через `CommonITILValidation::computeValidationStatus()` и поднимают
  правильное событие уведомления.
- **При принятии решения** теперь корректно закрывается тикет
  (`status=CLOSED`, заполняются `closedate`/`solvedate`), создаётся
  follow-up в timeline с автором = заявитель.

### Локализация

- В исходниках строки на **португальском**, а
  `locales/en_US.po`/`locales/pt_BR.po` содержали английские `msgid`
  → переводы не подхватывались ВООБЩЕ. Переписан `en_US.po` так чтобы
  `msgid` совпадал с тем что вызывает код.
- Добавлен полный **`locales/ru_RU.po`** + скомпилированный
  `ru_RU.mo` (206 строк, корректные русские склонения для plurals
  `токен / токена / токенов`).
- Папка переименована из `locale/` в **`locales/`** — GLPI 11 ищет
  именно с `s`.
- Хардкод убран из `templates/layout.php` (заголовок/футер) и
  `setup.php` (имя плагина в списке плагинов).
- HTML-сниппеты в `front/config.form.php` (примеры для копирования в
  шаблоны GLPI) переведены через closures с `__()`.
- Добавлен `locales/compile_mo.py` — pure-Python `.po → .mo` компилятор,
  не требует системного `msgfmt`.

### Прочее

- Подробный audit-log с `payload` (раскрывающимся JSON в строке) для
  диагностики проблем уведомлений.
- В `ajax/template.preview.php` убран ненужный ручной `Session::checkCSRF`
  (GLPI 11 валидирует middleware'ом), JS отправляет токен в FormData.
- Версия плагина поднята до **1.0.4**.

---

## License

GPLv3+ — see [LICENSE](LICENSE).

## Credits

Original work by [@celsocaninde](https://github.com/celsocaninde) and
contributors. This fork: GLPI 11.0.7 compatibility patches and Russian
localization.
