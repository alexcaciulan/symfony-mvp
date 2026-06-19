de revizuit: Ai depășit pachetul lunar. S-a emis o factură pentru dosarul suplimentar, o găsești la Facturi.

Pe lângă nomenclator, înlocuiesc Court.county string → FK County NOT NULL și coveredLocalities JSON → ManyToMany City. Actualizez toate cele ~14 fișiere de test (creare+persist County, tearDown cu ordine FK)
+ resolver + repository + comanda import. Efort și risc mai mari.
