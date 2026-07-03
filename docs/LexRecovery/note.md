caffeinate -i claude

de revizuit: Ai depășit pachetul lunar. S-a emis o factură pentru dosarul suplimentar, o găsești la Facturi.

Pe lângă nomenclator, înlocuiesc Court.county string → FK County NOT NULL și coveredLocalities JSON → ManyToMany City. Actualizez toate cele ~14 fișiere de test (creare+persist County, tearDown cu ordine FK)
+ resolver + repository + comanda import. Efort și risc mai mari.

De ce fereastra de 1 an? Portalul filtrează pe data înregistrării dosarului, nu pe ultima modificare. Avocatul poate adăuga dosarul în platformă mult după depunere, deci fereastra trebuie să fie generoasă (asta a fost descoperit la testul real cu 2001/300/2026, care era ratat de fereastra inițială de 30 de zile).


Dezvoltări ulterioare (neimplementate încă)
Integrarea email pentru „Cerere de acces la dosarul electronic" (pct. 9), cu un model de cerere validat de tine.
Notificarea avocatului privind restituirea sumelor, în cazul anulării admise după pornirea executării (pct. 2).
Tratarea trecerii la executarea silită „după trecerea termenului în care instanța pune în vedere debitorului achitarea, de obicei 30 de zile": pasul de executare e cablat; eventuala automatizare a acestui termen rămâne de discutat.

https://doc.netopia-payments.com/docs/payment-sdks/php/
