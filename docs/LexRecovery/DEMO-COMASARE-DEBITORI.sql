-- Demo stack only (lexdemo-db), run ONCE after migration Version20260930150000.
-- Merges the two library rows of ONLINE ADVERTISING CONCEPT SRL (CUI 36736564,
-- account las@test.ro): row 10 (most recently modified, case 10) stays, case 9
-- moves onto it. The migration gives each link the id of its old debtor row,
-- so link 9 belongs to case 9 and debtor 9.
-- The address of case 9 changes (the kept row has a shorter address and postal
-- code 61192); the change is recorded on case 9, so the payment order screen
-- shows it. Check the SELECT before running the rest.

SELECT d.id, l.legal_case_id, d.name, d.cui_key, d.address, d.updated_at
FROM debtor d JOIN legal_case_debtor l ON l.debtor_id = d.id
WHERE d.id IN (9, 10);

START TRANSACTION;

-- What case 9 sees before and after, in the case history.
INSERT INTO audit_log (action, entity_type, entity_id, old_data, new_data, created_at, user_id, category)
SELECT 'debtor_identity_changed', 'App\\Entity\\LegalCase', '9',
       JSON_OBJECT('address', o.address, 'administrator', o.administrator),
       JSON_OBJECT('address', k.address, 'administrator', k.administrator),
       NOW(), NULL, NULL
FROM debtor o JOIN debtor k ON k.id = 10
WHERE o.id = 9 AND o.cui_key = k.cui_key AND o.user_id = k.user_id;

INSERT INTO audit_log (action, entity_type, entity_id, old_data, new_data, created_at, user_id, category)
VALUES ('debtor_merged', 'App\\Entity\\LegalCase', '9', JSON_OBJECT('debtorId', 9), JSON_OBJECT('debtorId', 10), NOW(), NULL, NULL);

UPDATE legal_case_debtor SET debtor_id = 10 WHERE id = 9 AND legal_case_id = 9 AND debtor_id = 9;

DELETE FROM debtor WHERE id = 9 AND NOT EXISTS (SELECT 1 FROM legal_case_debtor WHERE debtor_id = 9);

COMMIT;
