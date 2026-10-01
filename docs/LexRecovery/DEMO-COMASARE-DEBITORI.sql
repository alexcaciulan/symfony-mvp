-- Demo stack only (lexdemo-db), run ONCE after migration Version20260930150000,
-- with a backup taken first (see DEMO-STACK.md).
--
-- Merges the two library rows of ONLINE ADVERTISING CONCEPT SRL (CUI 36736564,
-- account las@test.ro): row 10 (most recently modified, case 10) stays, case 9
-- moves onto it. The migration gives each link the id of its old debtor row, so
-- link 9 belongs to case 9 and debtor 9.
--
-- Case 9 already has a generated payment order petition, which keeps the old
-- address; the change is recorded on case 9 field by field, so the payment
-- order screen lists it and asks for an acknowledgment before regenerating.
--
-- Safe to run twice: every statement runs only while link 9 still points to
-- debtor 9 and both rows are the same company of the same lawyer.

-- 1. Look first: the two rows and what case 9 would see change.
SELECT d.id, l.legal_case_id, d.user_id, d.name, d.cui_key, d.onrc_number,
       d.address, d.address_county, d.address_locality, d.administrator, d.updated_at
FROM debtor d JOIN legal_case_debtor l ON l.debtor_id = d.id
WHERE d.id IN (9, 10);

START TRANSACTION;

SET @ok := (
    SELECT COUNT(*)
    FROM debtor o
    JOIN debtor k ON k.id = 10
    JOIN legal_case_debtor l ON l.id = 9 AND l.legal_case_id = 9 AND l.debtor_id = 9
    WHERE o.id = 9 AND o.cui_key = k.cui_key AND o.user_id = k.user_id
);

-- 2. The identity of case 9 before and after, on every field that names the
--    debtor or decides the court (as DebtorLibraryService records an edit).
INSERT INTO audit_log (action, entity_type, entity_id, old_data, new_data, created_at, user_id, category)
SELECT 'debtor_identity_changed', 'App\\Entity\\LegalCase', '9',
       JSON_OBJECT('name', o.name, 'cui', o.cui, 'onrcNumber', o.onrc_number, 'address', o.address,
                   'addressCounty', o.address_county, 'addressLocality', o.address_locality, 'administrator', o.administrator),
       JSON_OBJECT('name', k.name, 'cui', k.cui, 'onrcNumber', k.onrc_number, 'address', k.address,
                   'addressCounty', k.address_county, 'addressLocality', k.address_locality, 'administrator', k.administrator),
       NOW(), NULL, NULL
FROM debtor o JOIN debtor k ON k.id = 10
WHERE o.id = 9 AND @ok = 1;

INSERT INTO audit_log (action, entity_type, entity_id, old_data, new_data, created_at, user_id, category)
SELECT 'debtor_merged', 'App\\Entity\\LegalCase', '9',
       JSON_OBJECT('debtorId', 9),
       JSON_OBJECT('debtorId', 10, 'reason', 'Legacy per-case copy merged into the library company (manual SQL on demo, 2026-10).'),
       NOW(), NULL, NULL
FROM DUAL
WHERE @ok = 1;

-- 3. Move case 9, then drop the row nothing names any more.
UPDATE legal_case_debtor SET debtor_id = 10 WHERE id = 9 AND legal_case_id = 9 AND debtor_id = 9 AND @ok = 1;

DELETE FROM debtor
WHERE id = 9 AND @ok = 1
  AND NOT EXISTS (SELECT 1 FROM legal_case_debtor WHERE debtor_id = 9);

COMMIT;

-- 4. Expect: one row (10) with cases 9 and 10, and two audit entries on case 9.
SELECT l.legal_case_id, l.debtor_id FROM legal_case_debtor l WHERE l.legal_case_id IN (9, 10);
SELECT id, action, entity_id FROM audit_log WHERE entity_type = 'App\\Entity\\LegalCase' AND entity_id = '9'
  AND action IN ('debtor_identity_changed', 'debtor_merged');
