de revizuit: Ai depășit pachetul lunar. S-a emis o factură pentru dosarul suplimentar, o găsești la Facturi.

Pe lângă nomenclator, înlocuiesc Court.county string → FK County NOT NULL și coveredLocalities JSON → ManyToMany City. Actualizez toate cele ~14 fișiere de test (creare+persist County, tearDown cu ordine FK)
+ resolver + repository + comanda import. Efort și risc mai mari.

De ce fereastra de 1 an? Portalul filtrează pe data înregistrării dosarului, nu pe ultima modificare. Avocatul poate adăuga dosarul în platformă mult după depunere, deci fereastra trebuie să fie generoasă (asta a fost descoperit la testul real cu 2001/300/2026, care era ratat de fereastra inițială de 30 de zile).
