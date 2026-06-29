# Zeop — Audit profond des données (Postgres `zeop`)

> Cible : conteneur Docker **`zeop-pg`** (`postgres:16`), base **`zeop`** (user `zeop`), port 5432. C'est le **data warehouse de démo** (Zeop = FAI réunionnais), **distinct** de la base applicative MySQL `liberu_billing`.
> Méthode : introspection schéma + 5 passes de requêtes d'intégrité sur les données réelles. Volumes : 1 316 clients, 2 077 abonnements, 11 192 factures, 13 942 lignes, 9 644 paiements.
> Sévérité : 🔴 critique · 🟠 élevé · 🟡 moyen · 🟢 sain.

---

## 0. Ce qui est SAIN (à ne pas « corriger »)
- 🟢 **Intégrité référentielle FK appliquée** : toutes les FK majeures existent et **0 orphelin** (Postgres les bloque).
- 🟢 **Géo propre** : 100 % des `city` (clients, sites, analytics) ∈ `communes_ref` (24 communes 974). Réalisme correct (Saint-Denis, Saint-Paul…).
- 🟢 **Scores/ratios dans les bornes** : NPS, CSAT (1–5), `churn_risk` (0–1), `saturation_pct` (0–100), `satisfaction_score`, `lifetime_value`, `tenure_months` — **0 hors borne**.
- 🟢 **Math des lignes de facture parfaite** : `invoice_items.total = quantity*unit_price` sur **13 942/13 942**.
- 🟢 **Pas de doublon client** ; `email` unique respecté ; `customer_analytics` strictement 1:1 avec `customers` (1316=1316).
- 🟢 **Remboursements cohérents** : `refunded_amount ≤ amount`, jamais incohérent, `payment_gateway_id` jamais NULL.
- 🟢 **`churn_events` complet** : 0 NULL sur date/raison/mrr/subscription.

---

## 1. 🔴 Aucune contrainte de domaine n'est appliquée (cause racine)
- **0 contrainte CHECK** dans toute la base. Aucun garde-fou sur : valeurs de statut, montants positifs, bornes de scores, devise.
- **Liens référentiels NON protégés par FK** (données actuellement propres mais rien n'empêche la dérive) :
  - `invoices.discount_id` → `discounts` (pas de FK ; 0 orphelin aujourd'hui)
  - `invoices.parent_invoice_id` → `invoices` (pas de FK ; 0 orphelin)
  - `customers.city` / `network_sites.city` / `customer_analytics.city` → `communes_ref.city` (pas de FK)
- **`credits` sans lien vers une facture** : pas de colonne `invoice_id` → un avoir ne peut **pas** être rattaché/appliqué (cf. §3.4).

> **Fix** : ajouter FK + CHECK (voir §5) **après** nettoyage. C'est ce qui empêchera la régénération de réintroduire les écarts ci-dessous.

---

## 2. 🟠 Intégrité financière (le cœur du warehouse)

| # | Constat | Volume | Détail / preuve |
|---|---|---|---|
| 2.1 | **`invoices.total_amount` ≠ lignes** (ni `Σitems`, ni `Σitems + late_fee − discount`) | **2 769 / 11 192 (24,7 %)** | écart de −323 € à **+3 258 €** |
| 2.2 | **`quotes.total_amount` ≠ `Σ(quantity*unit_price)`** | **368 / 368 (100 %)** | écarts ±, −1 939 € à +920 €, moy −153 € |
| 2.3 | **Statut « non payé » mais facture soldée** par les paiements | **212** | `status ∈ (pending,overdue)` alors que `Σpaiements ≥ total` |
| 2.4 | **Factures à total ≤ 0** | **54** | dont **39 `paid`**, 6 pending, 5 partially_paid, 4 overdue |
| 2.5 | **Paiements à montant ≤ 0** | **37** | + **9 factures sur-payées**, 9 « paid » sous-payées |
| 2.6 | **`discount_amount` renseigné sans `discount_id`** | **1 410** | remise « fantôme » sans code rattaché |
| 2.7 | **`credits.balance > amount`** (solde > montant initial — impossible) | **115 / 249 (46 %)** | balance ne peut excéder le montant émis |
| 2.8 | **`partially_paid` mal classées** | **10** | 5 sans aucun paiement, 5 entièrement payées |

> **Pourquoi ça compte** : tout KPI de revenu (CA, MRR, encaissé vs facturé, remises) est faux tant que header≠lignes et que statut≠paiements.

---

## 3. 🟠 Cohérence cycle de vie & statuts

### 3.1 Abonnements
- **1 066 abonnements `active` avec `end_date` dans le passé** (sur 1 573 actifs = **68 %**) → « actif » périmé / renouvellement non appliqué.
- **176 abonnements `cancelled` avec `auto_renew = true`** (annulé mais renouvellement encore actif — contradiction).
- **8 abonnements `start_date > end_date`** (fin avant début).

### 3.2 Suspensions (`service_suspensions`)
- **17 abonnements `suspended` sans ligne de suspension `active`** (statut sub sans preuve).
- **9 suspensions `status='lifted'` mais `lifted_at` NULL** (levée sans date).
- **16 suspensions `status='active'` mais `lifted_at` renseigné** (active alors qu'une date de levée existe — contradiction).
- **5 suspensions `lifted_at < suspended_at`** (levée avant la suspension).

### 3.3 Churn (3 sources qui se contredisent)
- **112 clients avec `churn_events` mais `customer_analytics.is_churned ≠ true`** (churné non flaggé).
- **51 clients `is_churned=true` sans aucun `churn_event`** (flaggé sans événement).
- **51 clients `is_churned=true` mais `churn_date` NULL**.
- 🟢 cohérent par ailleurs : 0 client churné avec abonnement encore `active`.

### 3.4 Avoirs (`credits`)
- Raisons réalistes : `Geste commercial` (67), `Dedommagement panne` (63 — **dédommagement incident**), `Avoir sur facture` (63), `Parrainage` (56).
- **Aucun lien vers `invoices`** → « Avoir sur facture » ne référence aucune facture ; impossible d'appliquer/consommer un solde. + voir 2.7 (balance>amount).

---

## 4. 🟡 Qualité / réalisme des données
- **Vocabulaire de statut mixte FR/EN** :
  - `subscriptions.status` = `active/cancelled/pending/suspended` **+ `en_attente_renouvellement`** (30, en français).
  - `support_tickets.status` = **`resolu` (710) / `en_cours` (122)** (français), alors que le reste est en anglais.
- **Devise EUR + USD mélangées** pour un FAI 974 (EUR uniquement) : **124 factures, 154 lignes, 96 paiements, 50 abonnements en USD**. Cohérent **par facture** (header/lignes/paiements même devise) mais hors domaine.
- **212 abonnements sans aucune facture** (= probablement les 212 du §2.3 / mêmes ordres de grandeur, à rapprocher).
- **387 clients ont des contacts mais aucun `is_primary`** (pas de contact principal ; 0 client en a *plusieurs*, donc le fix est sûr).

---

## 5. Plan de correction

### Étape A — Nettoyage des données (UPDATE) — *exécuter avant d'ajouter les contraintes*
```sql
-- 2.1 Recaler le total facture sur les lignes (+ frais − remise)
UPDATE invoices i SET total_amount = s.st + i.late_fee_amount - COALESCE(i.discount_amount,0)
FROM (SELECT invoice_id, SUM(total) st FROM invoice_items GROUP BY 1) s
WHERE s.invoice_id = i.id;

-- 2.2 Recaler le total devis sur ses lignes
UPDATE quotes q SET total_amount = COALESCE(t.st,0)
FROM (SELECT quote_id, SUM(quantity*unit_price) st FROM quote_items GROUP BY 1) t
WHERE t.quote_id = q.id;

-- 2.3 / 2.8 Recalculer le statut depuis les paiements (régle unique)
WITH p AS (SELECT invoice_id, SUM(amount) paid FROM payments GROUP BY 1)
UPDATE invoices i SET status = CASE
    WHEN COALESCE(p.paid,0) >= i.total_amount - 0.01 THEN 'paid'
    WHEN COALESCE(p.paid,0) > 0                      THEN 'partially_paid'
    WHEN i.due_date < CURRENT_DATE                   THEN 'overdue'
    ELSE 'pending' END,
  paid_at = CASE WHEN COALESCE(p.paid,0) >= i.total_amount - 0.01 THEN COALESCE(i.paid_at, now()) ELSE NULL END
FROM (SELECT i2.id, COALESCE(p.paid,0) paid FROM invoices i2 LEFT JOIN p ON p.invoice_id=i2.id) p
WHERE p.id = i.id;

-- 2.7 Clamp du solde d'avoir
UPDATE credits SET balance = amount WHERE balance > amount;

-- 3.1 Annulé ⇒ auto_renew off ; (option) statut périmé
UPDATE subscriptions SET auto_renew = false WHERE status='cancelled' AND auto_renew;

-- 3.2 Réconcilier suspensions ↔ lifted_at
UPDATE service_suspensions SET status='lifted'  WHERE lifted_at IS NOT NULL AND status<>'lifted';
UPDATE service_suspensions SET lifted_at = NULL  WHERE status='active';

-- 4 Vocabulaire : normaliser en anglais (exemple)
UPDATE subscriptions  SET status='pending_renewal' WHERE status='en_attente_renouvellement';
UPDATE support_tickets SET status = CASE status WHEN 'resolu' THEN 'resolved' WHEN 'en_cours' THEN 'open' ELSE status END;

-- 4 Backfill contact principal (le plus ancien)
UPDATE client_contacts c SET is_primary=true
WHERE id IN (SELECT DISTINCT ON (customer_id) id FROM client_contacts
             WHERE customer_id IN (SELECT customer_id FROM client_contacts GROUP BY 1 HAVING bool_or(is_primary)=false)
             ORDER BY customer_id, id);
```

### Étape B — Réconciliations à décider (source de vérité)
- **Churn (§3.3)** : choisir LA source. Recommandé : `churn_events` fait foi → régénérer `is_churned`/`churn_date` depuis le dernier événement, et créer un événement pour les 51 flaggés sans event (ou les déflagger).
- **Devise (§4)** : si Zeop = EUR only → convertir/forcer `currency='EUR'` partout (et recalculer les montants USD au taux retenu) ; sinon documenter le multi-devise comme volontaire.
- **`active` périmés (§3.1, 1 066)** : soit avancer `end_date` (simuler le renouvellement), soit passer en `expired`. Dépend du scénario de démo voulu.
- **Avoirs (§3.4)** : ajouter `invoice_id` (+ logique d'application) si la démo montre l'application d'avoirs ; sinon les laisser « non rattachés » et le documenter.

### Étape C — Contraintes anti-régression (après nettoyage)
```sql
ALTER TABLE invoices  ADD CONSTRAINT fk_inv_discount  FOREIGN KEY (discount_id)       REFERENCES discounts(id);
ALTER TABLE invoices  ADD CONSTRAINT fk_inv_parent     FOREIGN KEY (parent_invoice_id) REFERENCES invoices(id);
ALTER TABLE customers ADD CONSTRAINT fk_cust_commune   FOREIGN KEY (city)              REFERENCES communes_ref(city);

ALTER TABLE invoices            ADD CONSTRAINT ck_inv_total_pos   CHECK (total_amount >= 0);
ALTER TABLE payments            ADD CONSTRAINT ck_pay_amount_pos  CHECK (amount > 0);
ALTER TABLE credits             ADD CONSTRAINT ck_cred_balance    CHECK (balance >= 0 AND balance <= amount);
ALTER TABLE subscriptions       ADD CONSTRAINT ck_sub_dates       CHECK (end_date >= start_date);
ALTER TABLE service_suspensions ADD CONSTRAINT ck_susp_dates      CHECK (lifted_at IS NULL OR lifted_at >= suspended_at);
ALTER TABLE invoices            ADD CONSTRAINT ck_inv_status      CHECK (status IN ('pending','paid','partially_paid','overdue'));
ALTER TABLE subscriptions       ADD CONSTRAINT ck_sub_status      CHECK (status IN ('active','cancelled','pending','suspended','pending_renewal'));
ALTER TABLE support_tickets     ADD CONSTRAINT ck_ticket_status   CHECK (status IN ('open','resolved','pending'));
```

> ⚠️ Si la base est **régénérée par un script de seed**, corriger **le générateur** (header=lignes, statut=paiements, devise EUR, vocabulaire unique, churn cohérent, `auto_renew=false` à l'annulation) est préférable au patch SQL — sinon les §2–§4 reviendront au prochain reseed.

---

## 6. Top priorités (si temps limité avant la démo)
1. 🔴 **Recaler `invoices.total_amount` et `quotes.total_amount` sur les lignes** (2 769 + 368) — sinon tous les montants affichés sont faux.
2. 🟠 **Recalculer les statuts de facture depuis les paiements** (212 + 10 + 54).
3. 🟠 **Réconcilier le churn** (112 + 51 + 51) — sinon les dashboards rétention sont incohérents.
4. 🟡 **Normaliser statuts FR/EN et devise EUR** — visible immédiatement dans une démo.
5. 🟡 **`auto_renew=false` sur annulés (176)** + suspensions↔`lifted_at` + contacts principaux (387).
