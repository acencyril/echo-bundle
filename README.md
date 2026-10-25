# Echo

**Your site emails you at each important step of its funnel, and once each morning with
yesterday's figures. You never have to open the admin to know how it is going.**

A sign-up, a payment, a contact message: one short email, at once. Page views and the
rest: counted per day. Every morning: one digest with yesterday's counters and its
notable events. Nothing happened: nothing is sent.

*Documentation française : [README.fr.md](README.fr.md)*

```bash
composer require acencyril/echo-bundle
```

- Plain-text emails, short lines, readable on a phone. No HTML, no tracking pixel, no
  link rewriting.
- At most N immediate emails per hour and per event, so a spike cannot flood your inbox;
  optional quiet hours. What is held back is in the next digest.
- Counters per day in your own database: no cookie, no visitor stored, no third party.
  Crawlers, monitors and link previews are left out of page views.
- Never breaks your app: a mail or database failure is logged, never thrown.
- French and English texts, through translation keys.

PHP 8.1+, Symfony 6.4, 7 or 8, Doctrine ORM 2.17+ or 3.

---

## Quick start

**1. Configure** `config/packages/echo.yaml` (the Flex recipe creates it):

```yaml
echo:
    owner: '%env(ECHO_OWNER)%'          # who receives the emails
    sender: '%env(ECHO_SENDER)%'        # the From address, accepted by your mail provider
    site_name: 'My site'                # in every subject
    locale: en                          # en or fr
    timezone: 'Europe/Paris'
    admin_url: 'https://example.com/admin'
    throttle: 10                        # immediate emails per hour and per event, at most
    quiet_hours:
        from: '20:00'
        to: '08:00'
        weekends: true
    events:                             # in this order in the digest
        signup: immediate
        contact: immediate
        payment:
            mode: immediate
            min_amount: 1000            # cents: smaller payments are only counted
        newsletter: digest
        page_view:
            mode: count
            skip_bots: true
        healthcheck: ignore
```

**2. Create the tables** (see [Database](#database)):

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

**3. Call it** at each step of your funnel:

```php
use Acencyril\EchoBundle\OwnerEcho;

public function __construct(private readonly OwnerEcho $echo) {}

$this->echo->event('signup', ['email' => $user->email, 'plan' => 'pro']);
$this->echo->event('payment', ['amount' => 4900, 'currency' => 'EUR', 'email' => $order->email]);
$this->echo->event('page_view');
```

**4. Schedule the digest**, once each morning:

```cron
0 8 * * * cd /path/to/app && php bin/console echo:digest
```

## Events and modes

An event is a lowercase name (`a-z`, `0-9`, `_`, `-`, `.`, up to 64 characters) and an
optional context. Each event has a mode:

| Mode | Emailed at once | Listed in the digest | Counted |
|---|---|---|---|
| `immediate` | yes, within the throttle and outside quiet hours | yes | yes |
| `digest` | no | yes | yes |
| `count` | no | no (just its number) | yes |
| `ignore` | no | no | no |

An event not listed under `events` takes `default_mode` (`count` by default): call
`event()` freely, decide later what deserves an email.

Per event:

| Key | Default | |
|---|---|---|
| `mode` | `count` | One of the four above. `signup: immediate` is short for `signup: { mode: immediate }`. |
| `label` | — | The name shown in the emails. Default: the translation of `echo.event.<name>` (domain `echo`), else the name itself. |
| `min_amount` | — | In minor units (cents). An event whose `amount` is missing or lower is only counted: your own test payments do not ring. |
| `skip_bots` | `false` | Drops crawlers, monitors, link previews and scripts (by user agent). For page views. |
| `throttle` | global | Immediate emails per hour for this event. |

### The context

Scalars only (strings, numbers, booleans; dates, enums and stringable objects are
converted), at most 20 keys, strings cut at 500 characters. Arrays and other objects are
dropped. A few keys are read:

- `email` and `amount` come first in subjects and digest lines;
- `amount` is in minor units (cents), formatted with `currency` (or the configured
  `currency`, `EUR` by default): `4900` → `€49.00`, `49,00 €` in French;
- `user_agent` is used by `skip_bots`, then dropped. Without it, the user agent of the
  current request is read. No user agent at all counts as a bot.

Do not put secrets in the context: notable events are stored with it (for the digest)
and emailed.

### From the event dispatcher or Messenger

The same call as an object:

```php
use Acencyril\EchoBundle\EchoEvent;

$dispatcher->dispatch(new EchoEvent('signup', ['email' => $email]));   // EventDispatcher
$bus->dispatch(new EchoEvent('payment', ['amount' => 4900]));          // Messenger
```

With Messenger, route `Acencyril\EchoBundle\EchoEvent` to an async transport to keep the
email out of the request. In a worker there is no request: pass `user_agent` in the
context for events that skip bots.

## Throttle and quiet hours

`throttle` (default 10) caps the immediate emails per hour and per event type. Past it,
events are still counted and listed in the digest, which says how many were not emailed
at the time.

`quiet_hours` (off by default): no immediate email between `from` and `to` (a window over
midnight works), nor all weekend with `weekends: true`. Hours are read in `timezone`
(default: PHP's). Events in that window wait for the digest.

## The digest

```bash
php bin/console echo:digest              # yesterday, in the configured timezone
php bin/console echo:digest --dry-run    # print it, send nothing
php bin/console echo:digest --day=2026-10-24
php bin/console echo:digest --force      # again, for a day already sent
```

One email: yesterday's counters (configured events first, in their order), then the
notable events (`immediate` and `digest`) with their time, at most 50. A day is sent once,
whoever runs the command; a day with nothing happening sends nothing. A failed send exits
with code 1 and can be run again. The command also deletes the notable events older than
`retention_days` (default 30); counters are kept.

With Symfony Scheduler instead of cron, a `RecurringMessage::cron('0 8 * * *', new
RunCommandMessage('echo:digest'))` does the same.

What it looks like:

```text
Subject: [My site] Saturday, October 24, 2026: Sign-up 3 · Payment 1 · Page views 312

Your day of Saturday, October 24, 2026

Sign-up: 3
Payment: 1
Page views: 312

Notable events
09:14  Sign-up — jane@example.com, plan: pro
11:02  Sign-up — sam@example.com, plan: free
15:47  Payment — jane@example.com, €49.00
21:30  Sign-up — lee@example.com, plan: free

1 of them not emailed at the time (quiet hours or hourly limit).

--
Admin: https://example.com/admin
Echo, for My site. What you receive is set in config/packages/echo.yaml.
```

## Configuration reference

| Key | Default | |
|---|---|---|
| `owner` | required | Who receives the emails. |
| `sender` | required | The From address; the name shown is `site_name`. |
| `site_name` | required | In every subject, so several sites can share an inbox. |
| `locale` | `en` | `en` or `fr`. |
| `timezone` | PHP's | Days, times and quiet hours. |
| `admin_url` | — | A link at the end of every email. |
| `currency` | `EUR` | For an `amount` without a `currency`. |
| `default_mode` | `count` | For events not listed. |
| `throttle` | `10` | Immediate emails per hour and per event. |
| `quiet_hours` | off | `from`, `to` (`HH:MM`), `weekends`. |
| `retention_days` | `30` | Notable events are deleted after this many days. |
| `events` | `{}` | Per event: `mode`, `label`, `min_amount`, `skip_bots`, `throttle`. |

Addresses are not checked when the container is built (they usually come from
`%env()%`): a wrong one makes the emails fail, which is logged.

## Database

Two small tables, mapped automatically (attribute mapping `Acencyril\EchoBundle\Entity`),
so `doctrine:migrations:diff` creates them:

- `echo_counter`: one row per day and event (`day`, `event`, `total`);
- `echo_entry`: the notable events with their context, deleted after `retention_days`.

Echo writes through DBAL (an atomic upsert for counters), never through the entity
manager: it never flushes your pending changes. PostgreSQL, MySQL/MariaDB and SQLite use
a native upsert; other platforms an update-then-insert.

The SQL, if you write the migration by hand:

```sql
-- PostgreSQL
CREATE TABLE echo_counter (day DATE NOT NULL, event VARCHAR(64) NOT NULL, total INT NOT NULL, PRIMARY KEY (day, event));
CREATE TABLE echo_entry (id VARCHAR(32) NOT NULL, event VARCHAR(64) NOT NULL, context JSON NOT NULL, mailed BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_echo_entry_created ON echo_entry (created_at);
CREATE INDEX idx_echo_entry_event_created ON echo_entry (event, created_at);

-- MySQL / MariaDB
CREATE TABLE echo_counter (day DATE NOT NULL, event VARCHAR(64) NOT NULL, total INT NOT NULL, PRIMARY KEY (day, event));
CREATE TABLE echo_entry (id VARCHAR(32) NOT NULL, event VARCHAR(64) NOT NULL, context JSON NOT NULL, mailed TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_echo_entry_created (created_at), INDEX idx_echo_entry_event_created (event, created_at), PRIMARY KEY (id));
```

If you declare several entity managers (`doctrine.orm.entity_managers`), the mapping is
not added for you; add it to the manager of the default connection:

```yaml
doctrine:
    orm:
        entity_managers:
            default:
                mappings:
                    EchoBundle:
                        type: attribute
                        is_bundle: false
                        dir: '%kernel.project_dir%/vendor/acencyril/echo-bundle/src/Entity'
                        prefix: 'Acencyril\EchoBundle\Entity'
```

## Texts

Texts live in the `echo` translation domain (`translations/echo.en.yaml`,
`echo.fr.yaml`): subjects, digest lines, and the labels of common events (`page_view`,
`signup`, `login`, `contact`, `lead`, `order`, `checkout`, `payment`, `subscription`,
`cancellation`, `refund`, `trial`, `error`). Name your own events in your
`translations/echo.<locale>.yaml`:

```yaml
echo:
    event:
        quote_request: Quote request
```

The Symfony translator must be enabled (it is in a standard application).

## Never breaks the app

`event()` never throws: an invalid name, a missing table, a refused email are logged
(channel `echo` with Monolog) and the call returns `false`. Mailer runs through Messenger
when your application routes emails there.

## Develop

```bash
composer install
vendor/bin/phpunit
```

The suite runs on in-memory SQLite. To run it on another database:
`ECHO_TEST_DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/echo_test" vendor/bin/phpunit`.

## License

MIT
