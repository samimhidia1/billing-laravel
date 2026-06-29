# Zeop — Vérification de la codebase vs hypothèses deepwiki

> Audit réalisé directement sur le code (lecture des migrations + modèles + services + DB live `liberu_billing`).
> Chaque affirmation est sourcée en `fichier:ligne`. Repo : `billing-laravel` (Laravel 13 / PHP 8.5 / Filament 5).
>
> **Légende des verdicts :** ✅ existe & fonctionne · 🟡 existe mais partiel / non câblé / dead code · ❌ n'existe pas · 🔴 ÉCART vs schéma réel (colonne fantôme / bug bloquant pour le SQL).

---

## §0 — Cartographie & décision de repli (FOSSBilling ?)

- [x] **Stack** : Laravel 13, PHP 8.5, Filament 5 (admin/app/client), Livewire 4, Jetstream (auth + teams), Horizon, Octane. Skeleton `liberusoftware/boilerplate-laravel`.
- [x] **Le cœur métier est-il lisible indépendamment de Filament ?** → **OUI.** La logique vit dans `app/Services/*Service.php` (`BillingService`, `ServiceAutomationService`, `PaymentPlanService`, `RefundService`…) + `app/Models` en Eloquent pur. Filament n'est qu'une couche d'UI par-dessus ; il n'obscurcit PAS le domaine.
- [x] **Le vrai risque n'est pas Filament — c'est que l'automatisation facturation est à moitié câblée.** Beaucoup de méthodes existent mais ne sont jamais appelées/planifiées, et plusieurs colonnes écrites par les modèles n'existent pas en base (voir §1-bis).

**Verdict repli :** 🟡 Exploitable pour une démo **si** on choisit les briques qui existent réellement (facturation récurrente quotidienne, relances SMS « upcoming », late fees, création d'échéancier, devis). ❌ Non exploitable « clé en main » pour : suspension automatique, relances e-mail, changement de plan différé, avoirs. → Le repli FOSSBilling ne se justifie **pas** par Filament ; il se justifierait seulement si la démo a besoin des briques manquantes ci-dessus **et** que les construire coûte trop cher. Décision à toi (voir §8).

---

## §1 — Modèle de données (base du `zeop_demo_warehouse.sql`)

Colonnes **réelles** (migrations + DB live). Nullabilité notée `null`/`NN`.

### `customers` — `2024_06_17_143940`
`id` · `name` NN · `email` NN unique · `phone_number` null · `address` null · `city` null · `state` null · `postal_code` null · `country` null · `sms_notifications_enabled` bool def true · `timestamps` · **`team_id`** null def 1 *(ajouté par `2024_08_29_093707_add_team_to_resources`)*
- Modèle `Customer` fillable = name,email,phone_number,address,city,state,postal_code,country (`app/Models/Customer.php:11-20`). `sms_notifications_enabled` existe en base mais **pas** dans `$fillable` (lu via `$customer->sms_notifications_enabled`, `BillingService.php:609`).

### `products_services` — `2024_06_17_145015`  *(modèle `Products_Service`, `#[Table('products_services')]`)*
`id` · `name` NN · `description` null · `base_price` decimal(8,2) def 0 · `type` def 'service' · `pricing_model` null · `custom_pricing_data` json null · `product_type_id` null · `hosting_server_id` null · `trial_days` def 0 · `trial_enabled` def false · `timestamps` · **`team_id`** null
- `price` = accesseur calculé sur `base_price` (`Products_Service.php:43-46`), **pas une colonne**.

### `subscriptions` — `2024_06_17_145100`
`id` · `customer_id` NN · `product_service_id` NN · `start_date` NN · `end_date` null · `renewal_period` def 'monthly' · `status` def 'active' *(string libre)* · `price` decimal(10,2) def 0 · `currency`(3) def 'USD' · `auto_renew` bool def true · `last_billed_at` null · `ends_at` null · `domain` null · `domain_name` null · `domain_registrar` null · `domain_expiration_date` null · `scheduled_change` json null · `timestamps` · **`team_id`** null
- `status` est un **string non contraint** ; valeurs écrites en code : `pending,active,suspended,cancelled,terminated` ; l'API valide `active,cancelled,suspended,expired` (`Api/SubscriptionController.php:58`) — incohérent (`expired` jamais écrit, `pending`/`terminated` hors liste).

### `invoices` — `2024_06_17_145508` (vérifié sur DB live)
`id` · `customer_id` NN · `subscription_id` null · `invoice_number` NN unique · `issue_date` NN · `due_date` NN · `total_amount` decimal(10,2) NN · `currency`(3) NN · `status` **enum('pending','paid','overdue')** NN · `sent_at` null · `viewed_at` null · `paid_at` null · `status_history` json null · `late_fee_amount` decimal(10,2) def 0 · `last_late_fee_date` null · `upcoming_reminder_sent` tinyint null · `reminder_count` int null · `last_reminder_date` null · `discount_id` int null · `discount_amount` decimal(10,2) null · `parent_invoice_id` int null · `is_installment` bool def false · `timestamps` · **`team_id`** null
- 🔴 **`status` enum ne contient PAS `partially_paid`** alors que `Invoice::updateStatus()` écrit `'partially_paid'` (`Invoice.php:200`) → écriture qui **échoue en MySQL strict**. Les états `sent`/`viewed` sont calculés (accesseur `Invoice.php:438-453`), pas stockés.

### `invoice_items` — `2024_06_17_150814` + `2024_06_18_000000_update_invoice_items_for_api` (DB live)
`id` · `invoice_id` NN · `description` null · `product_service_id` **null** · `quantity` int NN · `unit_price` decimal(10,2) NN · `total_price` decimal(12,2) NN · `currency`(3) NN · `timestamps`
- La migration `_update_..._for_api` ajoute `description`, rend `product_service_id` nullable, et **renomme `total` → `total_price`**.
- 🔴 **Pas de colonne `team_id`** alors que le modèle utilise le trait `HasTeam` (`Invoice_Item.php:26`) → même classe de risque que le bug de tenancy déjà corrigé.

### `payments` — `2024_06_17_151700` + `2024_06_18_000001_add_refund_status` (DB live)
`id` · `invoice_id` NN · **`payment_gateway_id` NN** *(ajouté par `2024_07_29_create_payment_gateways`)* · `payment_date` NN · `amount` decimal(10,2) NN · `currency`(3) NN · `payment_method` **enum('credit card','bank transfer','PayPal')** NN · `transaction_id` NN unique · `refund_status` **enum('none','pending','completed')** def 'none' · `timestamps` · **`team_id`** null
- `payment_gateway_id` est **NOT NULL sans défaut** → tout INSERT de paiement doit le fournir.

### `quotes` — `2026_03_03_000000`
`id` · **`team_id`** null *(migration propre)* · `customer_id` NN · `quote_number` NN unique · `title` NN · `status` enum(draft,sent,viewed,accepted,declined,expired) def 'draft' · `valid_until` null · `subtotal` decimal(10,2) def 0 · `tax_amount` decimal(10,2) def 0 · `total` decimal(10,2) def 0 · `currency`(3) def 'USD' · `notes` null · `terms` null · `sent_at` null · `viewed_at` null · `accepted_at` null · `declined_at` null · `timestamps` · index `[team_id,status]`, `[customer_id,status]`

### `quote_items` — `2026_03_03_000000`
`id` · `quote_id` NN · `description` NN · `quantity` decimal(10,2) def 1 · `unit_price` decimal(10,2) NN · `total` decimal(10,2) NN · `sort_order` def 0 · `timestamps` — **pas de `team_id`**. `total` recalculé à `saving` (`QuoteItem.php:36-38`).

### Couverture `team_id` (important pour la démo multi-tenant / tenancy)
- ✅ **Ont `team_id`** : `customers, products_services, subscriptions, invoices, payments, credits` (via `add_team_to_resources`), `quotes` (migration propre).
- ❌ **N'ont PAS `team_id`** : `invoice_items, quote_items, service_suspensions, client_contacts`.

---

## §1-bis — 🔴 COLONNES FANTÔMES (modèle ↔ migration) — LE point critique pour le SQL

Colonnes présentes dans `$fillable`/utilisées par le code mais **absentes de toute migration et de la DB live** (vérifié `SHOW COLUMNS`). Toute insertion/maj qui les vise échoue en MySQL.

| Table | Colonne fantôme | Référencée dans | Conséquence |
|---|---|---|---|
| `invoices` | `tax_amount` | `Invoice.php:35,233` (accesseur `final_total`) | total calculé suppose une colonne inexistante |
| `invoices` | `is_recurring` | `Invoice.php:34,461` (`setupRecurringBilling`) | `update(['is_recurring'=>true])` → **échoue** |
| `invoices` | `invoice_template_id` | `Invoice.php:31,245` (relation `template()`) | relation/colonne inexistante |
| `payments` | `refunded_amount` | `Payment.php`, `RefundService.php` | **les remboursements échouent** (colonne absente) |
| `payments` | `refund_reason` | `Payment.php:119-135` | idem refund |
| `payments` | `status` | `Payment.php:33,73` (cast) | colonne absente |
| `payments` | `reconciliation_status`, `reconciliation_notes` | `Payment.php:78-87` | réconciliation paiements cassée |
| `payments` | `affiliate_id`, `affiliate_commission` | `Payment.php:104-107` | lien affilié cassé |
| `payments` | `stripe_token`, `square_token`, `google_pay_token`, `payment_method_details` | `Payment.php` (#[Fillable]) | tokenisation absente |

> ⚠️ `Payment` déclare un `#[Fillable(...)]` large **puis** un `protected $fillable = [...]` plus court (`Payment.php:51-62`) qui **gagne** ; mais plusieurs de ses colonnes (`payment_gateway_id`, `affiliate_id`, `affiliate_commission`, `payment_method_details`) ne sont pas toutes en base non plus.
>
> **Pour `zeop_demo_warehouse.sql` :** soit on **ajoute ces colonnes** (migrations correctives) si la démo touche refund/réconciliation/taxe/templates, soit on **n'écrit jamais** sur ces chemins. Ne pas supposer qu'elles existent.

---

## §2 — Les 3 tables « inférées » (à confirmer) → CONFIRMÉES

### `service_suspensions` — `2026_02_15_000007`
`id` · `subscription_id` NN · `invoice_id` null · `reason` **enum('overdue_payment','manual','terms_violation','fraud')** def 'overdue_payment' · `notes` null · `suspended_at` NN · `unsuspended_at` null · `suspended_by` null (FK users) · `unsuspended_by` null (FK users) · `is_active` bool def true · `timestamps` · index `[subscription_id,is_active]`, `suspended_at`
- 🔴 **PAS de colonne `status`.** L'état = `is_active` + `unsuspended_at` (`ServiceSuspension::isSuspended()` `:63-66`). Tout schéma qui suppose `service_suspensions.status` est faux.
- `reason` réellement écrit : `overdue_payment` (`ServiceAutomationService.php:32`), `manual` (défaut `:46`). `terms_violation`/`fraud` = valeurs d'enum **jamais écrites** (dead enum).

### `credits` — `2024_06_18_000000`
`id` · `customer_id` NN (FK cascade) · `amount` decimal(10,2) NN · `description` null · `expiry_date` **date** null · `timestamps` · **`team_id`** null
- Modèle minimal (`Credit.php`) : fillable customer_id,amount,description,expiry_date ; cast `expiry_date=>datetime`. **Aucune** colonne `invoice_id`, ni solde/used. (cf. §7)

### `client_contacts` — `2026_03_03_000001`
`id` · `customer_id` NN · `first_name` NN · `last_name` NN · `email` NN · `phone` null · `title` null · `is_primary` bool def false · `can_view_invoices` bool def true · `can_make_payments` bool def false · `can_manage_services` bool def false · `timestamps` · index `[customer_id,is_primary]`, `email` — **pas de `team_id`**. `full_name` = accesseur calculé (`ClientContact.php:42-45`).

---

## §3 — Suspension de service  🟡 (existe mais non automatisé + deux mécanismes disjoints)

- [x] **Deux mécanismes parallèles NON reliés :**
  - **A — par ligne `ServiceSuspension`** (`ServiceAutomationService`) : crée une ligne + passe `subscriptions.status='suspended'`. Suspend : `ServiceAutomationService::suspendOverdueServices(7)` → `suspendService()` (`ServiceAutomationService.php:19,43-80`). Réactive : `unsuspendService()`/`autoUnsuspendOnPayment()` (`:85-131`) → `ServiceSuspension::unsuspend()` (`ServiceSuspension.php:54-61`).
  - **B — par compte d'hébergement** (`BillingService` + `ServiceProvisioningService` + `HostingService`) : agit sur le panel & `hosting_accounts.status`, **ne touche jamais** `ServiceSuspension` ni `subscriptions.status` (`BillingService.php:335,341,462-465`).
- [x] `BillingService::handleFailedPayment()` existe (`BillingService.php:456-476`) : après grâce `config('billing.grace_period',3)` jours, suspend le **compte hosting** (mécanisme B) et passe la facture `overdue`. **Ne crée pas** de `ServiceSuspension`.
- [x] Valeurs `reason` réelles : `overdue_payment`, `manual`. Valeurs `subscriptions.status` réelles : `pending,active,suspended,cancelled,terminated`.

**Gaps :** ❌ La commande `services:suspend-overdue` **n'est PAS planifiée** (`routes/console.php` ne contient que `processRecurringBilling`, `invoices:send-reminders`, `invoices:process-reminders`, `audit:prune`, rapports). ❌ `unsuspendService`/`autoUnsuspendOnPayment`/`terminateService` **sans aucun appelant en prod** (seulement dans les tests). ❌ Aucune réconciliation entre les deux mécanismes.

---

## §4 — Relances / dunning  🟡 (SMS « upcoming » live ; e-mails commentés ; commande planifiée cassée)

- [x] **Planifié** : `invoices:send-reminders` et `invoices:process-reminders`, `->daily()` (`routes/console.php:19-20`).
- [x] **Colonnes réelles utilisées** : `reminder_count` (incrémenté `BillingService.php:590`), `last_reminder_date` (écrit `:591`). `upcoming_reminder_sent` **existe en base mais n'est lu/écrit par aucun code vivant** (seulement dans du code commenté `:496,501`) → **pas de garde d'idempotence** sur les rappels « upcoming » (ré-envoi chaque jour).
- [x] **SMS = RÉEL** : `SmsService::send()` fait un `Http::post(...)` live (`SmsService.php:27-33`), appelé pour les rappels « upcoming » (`BillingService.php:609-616`).

**Gaps :** ❌ **E-mail de relance overdue commenté** : `// Mail::to(...)->send(...)` (`BillingService.php:690`) + tableau `$data` non assigné (dead code `:680-688`) → **aucun e-mail envoyé**, mais les compteurs avancent quand même. ❌ Rappels e-mail « upcoming », late-fee, SMS overdue : **tous commentés** (`BillingService.php:478-529,627-643,666,693-703`). 🔴 **Commande `invoices:process-reminders` cassée quand planifiée** : son constructeur force `billingService=null` quand instancié sans args (ce que fait le scheduler), puis `handle()` appelle `$this->billingService->...` → **NPE** (`ProcessInvoiceReminders.php:17-25,40`), et `BillingService::sendUpcomingInvoiceReminders()` est commentée. 🟡 `ReminderSetting` (table + modèle) existe mais **inutilisé** (réf. seulement dans du code commenté) → fenêtre 7 j codée en dur, pas de cap `max_reminders`.

---

## §5 — Échéanciers / paiements fractionnés  🟡 (création OK, génération récurrente NON planifiée)

- [x] **Flux complet présent** : `Invoice::createPaymentPlan()` (`Invoice.php:253-271`) → `PaymentPlan` (table `payment_plans`, `2024_01_25_000001`) → `PaymentPlanService::processPaymentPlans()` (`PaymentPlanService.php:41-54`) crée des factures-enfant (`parent_invoice_id`, `is_installment=true`, `PaymentPlanService.php:27-28`).
- [x] **`is_installment`** : colonne sur `invoices` (def false). Écrit à `PaymentPlanService.php:28` ; lu comme garde à `Invoice.php:255,457`. ⚠️ **Non casté** en booléen (`Invoice::casts()` l'omet).

**Gaps :** ❌ **`BillingService::processPaymentPlans()` n'est PAS planifié** (absent de `routes/console.php`) et `PaymentPlanService::processPaymentPlans()` n'a aucun autre appelant → **les échéances ne se génèrent jamais automatiquement**. 🟡 Détection de complétion via relation potentiellement périmée dans la boucle + cap `total_installments` non garanti (`PaymentPlanService.php:47-52`). 🟡 `payment_plans` **sans `team_id`** alors que `PaymentPlan` utilise `HasTeam`.

---

## §6 — Changement de plan / renouvellement / auto-renew  🟡/❌

- [x] **`auto_renew`** : colonne bool def true ; togglée à la création (`BillingService.php:54`) et à `cancel()` (`Subscription.php:96`). UI admin fonctionnelle : toggle dans `SubscriptionResource` (`app/Filament/Admin/Resources/Subscriptions/SubscriptionResource.php:52`).
- [x] **`Subscription::renew()`** (`Subscription.php:80-92`) : si `auto_renew` & pas `cancelled`, avance `end_date`, `last_billed_at=now`, `status='active'`. **N'applique PAS** `scheduled_change`, ne génère pas de facture.
- [x] **Renouvellement automatisé** : `processRecurringBilling()` → `processSubscriptionBilling()` (`BillingService.php:309-350`) appelle `renew()` après paiement réussi ; **planifié `->daily()`** (`routes/console.php:16`). 🟡 Le job `app/Jobs/ProcessSubscriptionBilling.php` duplique cette logique mais **n'est jamais dispatché**.

**Gaps :** 🔴 **`scheduled_change` est écrit mais JAMAIS lu** : écrit dans `ServiceManagementController::downgrade()/cancel()` (`:76,90`), **aucun lecteur** nulle part → les changements différés (downgrade/cancel programmé) sont **silencieusement ignorés**. ❌ `BillingService::upgradeSubscription()` = **dead code** (aucun appelant), calcule une proration **non utilisée** (`BillingService.php:68-72`) et vise une colonne erronée `subscription_plan_id` (le modèle utilise `product_service_id`). ❌ Routes client upgrade/downgrade/cancel **commentées** (`routes/web.php:31-35`). ❌ **`ManageSubscriptionPage`** : non enregistrée dans la nav **et sa vue Blade `filament.pages.manage-subscription` n'existe pas** (`ManageSubscriptionPage.php:23`, aucun fichier dans `resources/`) → page non fonctionnelle. ❌ `Api\SubscriptionController::renew()` ne fait que `status='active'` (n'appelle pas `renew()`).

---

## §7 — Crédits / avoirs automatiques  ❌ (confirmation de ton hypothèse)

- [x] **Confirmé : aucun avoir automatique. Et aucune création, même manuelle.** Grep `Credit::create|new Credit|->credits()->create` sur `app/`,`routes/`,`database/`,`resources/` → **0 site de création**. Seule référence au modèle : `Customer::credits()` (`Customer.php:25-28`).
- [x] **Pas de `CreditResource` Filament, pas d'endpoint API, pas de factory/seeder.**
- [x] **Refund** (`RefundService.php:13-47`) met à jour `Payment.refund_status`/(`refunded_amount` fantôme) — **ne crée pas d'avoir**. **Dispute** (`DisputeService.php`) repasse la facture en `pending` — **pas d'avoir**.
- [x] **Application d'un crédit à une facture : inexistante** (pas de `invoice_id` sur `credits`, aucune logique `applyCredit`/solde).

**Verdict :** ❌ La fonctionnalité « avoirs » (auto **et** manuelle) est une **table + modèle orphelins**. C'est du **net-new** à construire — ton hypothèse est exacte.

---

## §8 — Synthèse « réalité du code » (à diffuser sur le SQL & le plan)

> ⚠️ **Pour finaliser le diff il me manque tes hypothèses.** Colle ici le **tableau §1 de `Zeop_Insights_vers_Features.md`** et la **liste des colonnes de `zeop_demo_warehouse.sql`** (non présents dans ce repo), et je remplis la colonne « Δ vs hypothèse ».

### Capacités — état réel

| Capacité | État | Preuve (fichier:ligne) | Δ vs hypothèse |
|---|---|---|---|
| Facturation récurrente (renew après paiement) | ✅ planifié quotidien | `BillingService.php:309-350`, `routes/console.php:16` | _(à compléter)_ |
| Devis (quotes/quote_items) | ✅ modèle complet | `Quote.php`, `QuoteItem.php`, migration `2026_03_03_000000` | |
| Late fees | ✅ calcul présent | `Invoice.php:304-370` | |
| Création d'échéancier | 🟡 création OK, **génération non planifiée** | `Invoice.php:253-271`, `PaymentPlanService.php:41-54` | |
| Relances | 🟡 **SMS upcoming live**, e-mails commentés, cmd planifiée NPE | `BillingService.php:609-616,690`, `ProcessInvoiceReminders.php:17-25` | |
| Suspension | 🟡 logique présente, **non planifiée**, 2 mécanismes disjoints | `ServiceAutomationService.php`, `routes/console.php` | |
| Changement de plan (upgrade/downgrade) | ❌ dead code / routes commentées / différé jamais appliqué | `BillingService.php:65-83`, `routes/web.php:31-35`, `scheduled_change` jamais lu | |
| Renouvellement assisté / UI client | ❌ page sans vue, non enregistrée | `ManageSubscriptionPage.php:23` | |
| Avoirs / crédits (auto & manuel) | ❌ table+modèle orphelins | `Credit.php`, 0 site de création | |
| Refund / réconciliation | 🔴 code vise des **colonnes fantômes** | `RefundService.php`, `Payment.php` vs DB live | |

### Décisions à figer avant SQL / runbook / plan
- [ ] **Colonnes fantômes** (§1-bis) : ajouter des migrations correctives **ou** exclure ces chemins de la démo (refund, réconciliation, tax_amount, templates, is_recurring).
- [ ] **`invoices.status`** : étendre l'enum avec `partially_paid` (sinon `updateStatus()` casse) ou retirer ce chemin.
- [ ] **`team_id` manquants** : `invoice_items`, `quote_items`, `service_suspensions`, `client_contacts` (cohérence multi-tenant).
- [ ] **Planifier** ce qui est attendu pour la démo : `services:suspend-overdue`, `processPaymentPlans` (sinon « existe » mais ne tourne jamais).
- [ ] **`service_suspensions.status`** : ne PAS le mettre dans le SQL (n'existe pas ; état = `is_active`+`unsuspended_at`).
- [ ] **Avoirs** : traiter comme net-new (table à étendre : `invoice_id`, solde, application).
