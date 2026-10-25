# Echo

**Votre site vous écrit à chaque étape importante de son tunnel, et chaque matin avec
les chiffres de la veille. Plus besoin d'ouvrir l'administration pour savoir où il en
est.**

Une inscription, un paiement, un message de contact : un e-mail court, tout de suite.
Les pages vues et le reste : comptés par jour. Chaque matin : un bilan avec les
compteurs de la veille et ses événements notables. Rien ne s'est passé : rien n'est
envoyé.

*English documentation: [README.md](README.md)*

```bash
composer require acencyril/echo-bundle
```

- E-mails en texte brut, lignes courtes, lisibles sur un téléphone. Pas de HTML, pas de
  pixel de suivi, pas de liens réécrits.
- Au plus N e-mails immédiats par heure et par événement : un pic ne noie pas votre
  boîte ; heures calmes en option. Ce qui est retenu figure dans le bilan suivant.
- Compteurs par jour dans votre propre base : pas de cookie, aucun visiteur enregistré,
  aucun tiers. Robots d'indexation, sondes de surveillance et aperçus de liens ne
  comptent pas dans les pages vues.
- Ne casse jamais votre application : un échec d'envoi ou de base de données est
  journalisé, jamais levé.
- Textes en français et en anglais, par clés de traduction.

PHP 8.1+, Symfony 6.4, 7 ou 8, Doctrine ORM 2.17+ ou 3.

---

## Démarrage

**1. Configurez** `config/packages/echo.yaml` (la recette Flex le crée) :

```yaml
echo:
    owner: '%env(ECHO_OWNER)%'          # qui reçoit les e-mails
    sender: '%env(ECHO_SENDER)%'        # l'expéditeur, accepté par votre fournisseur d'e-mail
    site_name: 'Mon site'               # dans chaque objet
    locale: fr                          # fr ou en
    timezone: 'Europe/Paris'
    admin_url: 'https://example.com/admin'
    throttle: 10                        # e-mails immédiats par heure et par événement, au plus
    quiet_hours:
        from: '20:00'
        to: '08:00'
        weekends: true
    events:                             # dans cet ordre dans le bilan
        signup: immediate
        contact: immediate
        payment:
            mode: immediate
            min_amount: 1000            # en centimes : les paiements plus petits sont seulement comptés
        newsletter: digest
        page_view:
            mode: count
            skip_bots: true
        healthcheck: ignore
```

**2. Créez les tables** (voir [Base de données](#base-de-données)) :

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

**3. Appelez-le** à chaque étape de votre tunnel :

```php
use Acencyril\EchoBundle\OwnerEcho;

public function __construct(private readonly OwnerEcho $echo) {}

$this->echo->event('signup', ['email' => $user->email, 'plan' => 'pro']);
$this->echo->event('payment', ['amount' => 4900, 'currency' => 'EUR', 'email' => $order->email]);
$this->echo->event('page_view');
```

**4. Planifiez le bilan**, une fois chaque matin :

```cron
0 8 * * * cd /chemin/vers/app && php bin/console echo:digest
```

## Événements et modes

Un événement est un nom en minuscules (`a-z`, `0-9`, `_`, `-`, `.`, 64 caractères au
plus) et un contexte facultatif. Chaque événement a un mode :

| Mode | E-mail tout de suite | Listé dans le bilan | Compté |
|---|---|---|---|
| `immediate` | oui, dans la limite horaire et hors heures calmes | oui | oui |
| `digest` | non | oui | oui |
| `count` | non | non (seulement son nombre) | oui |
| `ignore` | non | non | non |

Un événement absent de `events` prend `default_mode` (`count` par défaut) : appelez
`event()` sans retenue, décidez plus tard de ce qui mérite un e-mail.

Par événement :

| Clé | Défaut | |
|---|---|---|
| `mode` | `count` | L'un des quatre ci-dessus. `signup: immediate` abrège `signup: { mode: immediate }`. |
| `label` | — | Le nom affiché dans les e-mails. Par défaut : la traduction de `echo.event.<nom>` (domaine `echo`), sinon le nom lui-même. |
| `min_amount` | — | En unité mineure (centimes). Un événement sans `amount` ou en dessous est seulement compté : vos propres paiements de test ne sonnent pas. |
| `skip_bots` | `false` | Écarte robots, sondes, aperçus de liens et scripts (d'après le user agent). Pour les pages vues. |
| `throttle` | global | E-mails immédiats par heure pour cet événement. |

### Le contexte

Des scalaires seulement (chaînes, nombres, booléens ; dates, enums et objets
convertibles en chaîne sont convertis), 20 clés au plus, chaînes coupées à 500
caractères. Tableaux et autres objets sont écartés. Quelques clés sont lues :

- `email` et `amount` passent en premier dans les objets et les lignes du bilan ;
- `amount` est en unité mineure (centimes), mis en forme avec `currency` (ou la
  `currency` configurée, `EUR` par défaut) : `4900` → `49,00 €`, `€49.00` en anglais ;
- `user_agent` sert à `skip_bots`, puis est retiré. Sans lui, c'est le user agent de la
  requête en cours. Aucun user agent du tout compte comme un robot.

Ne mettez pas de secret dans le contexte : les événements notables sont enregistrés
avec lui (pour le bilan) et envoyés par e-mail.

### Depuis l'EventDispatcher ou Messenger

Le même appel, sous forme d'objet :

```php
use Acencyril\EchoBundle\EchoEvent;

$dispatcher->dispatch(new EchoEvent('signup', ['email' => $email]));   // EventDispatcher
$bus->dispatch(new EchoEvent('payment', ['amount' => 4900]));          // Messenger
```

Avec Messenger, routez `Acencyril\EchoBundle\EchoEvent` vers un transport asynchrone
pour sortir l'e-mail de la requête. Dans un worker il n'y a pas de requête : passez
`user_agent` dans le contexte des événements qui écartent les robots.

## Limite horaire et heures calmes

`throttle` (10 par défaut) plafonne les e-mails immédiats par heure et par type
d'événement. Au-delà, les événements restent comptés et listés dans le bilan, qui dit
combien n'ont pas été envoyés sur le moment.

`quiet_hours` (désactivé par défaut) : aucun e-mail immédiat entre `from` et `to` (une
plage à cheval sur minuit fonctionne), ni de tout le week-end avec `weekends: true`. Les
heures se lisent dans `timezone` (par défaut celui de PHP). Les événements de cette
plage attendent le bilan.

## Le bilan

```bash
php bin/console echo:digest              # la veille, dans le fuseau configuré
php bin/console echo:digest --dry-run    # l'afficher, ne rien envoyer
php bin/console echo:digest --day=2026-10-24
php bin/console echo:digest --force      # à nouveau, pour un jour déjà envoyé
```

Un e-mail : les compteurs de la veille (les événements configurés d'abord, dans leur
ordre), puis les événements notables (`immediate` et `digest`) avec leur heure, 50 au
plus. Un jour n'est envoyé qu'une fois, qui que ce soit qui lance la commande ; un jour
sans rien n'envoie rien. Un envoi raté sort avec le code 1 et peut être relancé. La
commande supprime aussi les événements notables plus vieux que `retention_days` (30
par défaut) ; les compteurs sont conservés.

Avec Symfony Scheduler plutôt que cron, un `RecurringMessage::cron('0 8 * * *', new
RunCommandMessage('echo:digest'))` fait la même chose.

À quoi il ressemble :

```text
Objet : [Mon site] samedi 24 octobre 2026 : Inscription 3 · Paiement 1 · Pages vues 312

Votre journée du samedi 24 octobre 2026

Inscription : 3
Paiement : 1
Pages vues : 312

À retenir
09:14  Inscription — jeanne@example.com, plan: pro
11:02  Inscription — sam@example.com, plan: free
15:47  Paiement — jeanne@example.com, 49,00 €
21:30  Inscription — lea@example.com, plan: free

1 non envoyé(s) sur le moment (heures calmes ou limite horaire).

--
Administration : https://example.com/admin
Echo, pour Mon site. Ce que vous recevez se règle dans config/packages/echo.yaml.
```

## Référence de configuration

| Clé | Défaut | |
|---|---|---|
| `owner` | requis | Qui reçoit les e-mails. |
| `sender` | requis | L'adresse d'expédition ; le nom affiché est `site_name`. |
| `site_name` | requis | Dans chaque objet, pour que plusieurs sites partagent une boîte. |
| `locale` | `en` | `en` ou `fr`. |
| `timezone` | celui de PHP | Jours, heures et heures calmes. |
| `admin_url` | — | Un lien à la fin de chaque e-mail. |
| `currency` | `EUR` | Pour un `amount` sans `currency`. |
| `default_mode` | `count` | Pour les événements non listés. |
| `throttle` | `10` | E-mails immédiats par heure et par événement. |
| `quiet_hours` | désactivé | `from`, `to` (`HH:MM`), `weekends`. |
| `retention_days` | `30` | Les événements notables sont supprimés après ce nombre de jours. |
| `events` | `{}` | Par événement : `mode`, `label`, `min_amount`, `skip_bots`, `throttle`. |

Les adresses ne sont pas vérifiées à la construction du conteneur (elles viennent
souvent de `%env()%`) : une adresse fausse fait échouer les e-mails, ce qui est
journalisé.

## Base de données

Deux petites tables, mappées automatiquement (mapping par attributs
`Acencyril\EchoBundle\Entity`), donc `doctrine:migrations:diff` les crée :

- `echo_counter` : une ligne par jour et par événement (`day`, `event`, `total`) ;
- `echo_entry` : les événements notables avec leur contexte, supprimés après
  `retention_days`.

Echo écrit par DBAL (un upsert atomique pour les compteurs), jamais par l'entity
manager : il ne flushe jamais vos modifications en attente. PostgreSQL, MySQL/MariaDB et
SQLite utilisent un upsert natif ; les autres plateformes un update puis insert.

Le SQL, si vous écrivez la migration à la main :

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

Si vous déclarez plusieurs entity managers (`doctrine.orm.entity_managers`), le mapping
n'est pas ajouté pour vous ; ajoutez-le au manager de la connexion par défaut :

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

## Textes

Les textes sont dans le domaine de traduction `echo` (`translations/echo.fr.yaml`,
`echo.en.yaml`) : objets, lignes du bilan, et noms des événements courants
(`page_view`, `signup`, `login`, `contact`, `lead`, `order`, `checkout`, `payment`,
`subscription`, `cancellation`, `refund`, `trial`, `error`). Nommez vos propres
événements dans votre `translations/echo.<locale>.yaml` :

```yaml
echo:
    event:
        quote_request: Demande de devis
```

Le traducteur Symfony doit être activé (il l'est dans une application standard).

## Ne casse jamais l'application

`event()` ne lève jamais d'exception : un nom invalide, une table absente, un e-mail
refusé sont journalisés (canal `echo` avec Monolog) et l'appel renvoie `false`. Le
Mailer passe par Messenger si votre application y route les e-mails.

## Développer

```bash
composer install
vendor/bin/phpunit
```

La suite tourne sur SQLite en mémoire. Pour la lancer sur une autre base :
`ECHO_TEST_DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/echo_test" vendor/bin/phpunit`.

## Licence

MIT
