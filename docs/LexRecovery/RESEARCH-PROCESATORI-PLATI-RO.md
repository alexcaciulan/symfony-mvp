# Research: procesatori de plăți din România (pentru LexRecovery)

Data: 2026-07-01
Status: research/analiză
Scop: Netopia este cea mai bună alegere, sau există alternative mai potrivite pentru un SaaS B2B pe abonament (recurent, RON, factură fiscală deja gestionată separat)?

---

## 1. Concluzie pe scurt

Netopia NU este singura opțiune și nici automat cea mai bună pentru cazul LexRecovery. Este liderul de piață local (peste 19 ani, integrare ușoară, brand cunoscut de comercianții români), dar profilul LexRecovery are trei particularități care schimbă calculul:

1. Este B2B pe abonament recurent, nu magazin cu multe tranzacții mici. Contează mai mult calitatea API-ului pentru recurring (token/off-session) și fiabilitatea decât comisionul minim per tranzacție.
2. Factura fiscală e deja rezolvată separat (Oblio/SmartBill + e-Factura). Deci NU ai nevoie ca procesatorul să facă și facturare/subscription management. Aceasta elimină un mare avantaj al Stripe Billing (și taxa lui de 0.7%).
3. Volume mici, valoare per tranzacție relativ mare (abonament lunar per avocat). Diferențele de comision procentual contează mai puțin decât la un retailer.

Recomandare: shortlist de 2 finaliști: Netopia (local, brand, deja pregătit în cod) vs. Stripe (cel mai bun API și recurring). Restul (PayU, EuPlătesc, Twispay, LibraPay, PlatiOnline) sunt viabile dar nu aduc un avantaj clar peste aceste două.

---

## 2. Comparație procesatori (piața RO)

| Procesator | Comision orientativ | Recurring / token | API / DX | Onboarding | Note pentru LexRecovery |
|---|---|---|---|---|---|
| **Netopia** (fost mobilPay) | de la ~0.99% + 0.30 RON, negociabil; fără abonament lunar | Da (token_id, one-click, recurent) | SDK oficial PHP v2 (JSON + token); decent | KYC + activare POS | Lider local, brand cunoscut de firme RO. Deja pregătit în codul tău (interfața, DTO, câmpuri externalId). |
| **Stripe** | ~1.4% + 0.25 EUR card european (acquiring local RO disponibil, payout RON); Billing +0.7% (nu-ți trebuie) | Excelent (off-session, SCA-ready, retry inteligent) | Cel mai bun din piață, developer-first, docs superbe | Rapid, self-service | Cel mai bun tehnic pentru recurring. Dar brand mai puțin familiar avocaților RO la checkout; nu-ți trebuie Billing (ai deja engine propriu). |
| **PayU** | de la ~2.49%/tranzacție | Da | REST API + hosted checkout + pluginuri | Standard | Jucător regional puternic, comision mai mare, fără avantaj clar aici. |
| **EuPlătesc** | negociabil pe volum | Da (recurring) | API + plugin (API-key + login) | Standard | Similar Netopia ca features. Alternativă locală solidă, dar DX sub Netopia/Stripe. |
| **Twispay** | fix ~1.3% + 0.10 EUR | Da (recurring, wallets) | API modern | Standard | Comision fix predictibil, bun pentru recurring. Mai puțin răspândit. |
| **LibraPay** (Libra Bank) | negociabil | Da | API/plugin | Cont la Libra Bank | Settlement rapid T+1, avantaj dacă ai deja Libra Bank. Ecosistem mai închis. |
| **PlatiOnline** | negociabil | Da (3D Secure) | API | Standard | Istoric lung (din 2002), dar tracțiune mai mică azi. |

Notă: comisioanele sunt orientative și aproape întotdeauna negociabile pe volum. Cere oferte scrise înainte de decizie.

---

## 3. Ce contează de fapt pentru LexRecovery (criterii ponderate)

1. Recurring fiabil (abonament lunar automat, off-session). Câștigători: Stripe (cel mai matur), Netopia/EuPlătesc/Twispay (au token recurent).
2. Nu dublează ce ai deja construit. Ai deja `Subscription`, `Invoice`, `Plan`, overage, factură fiscală (Oblio/SmartBill). Îți trebuie un procesator de plăți „pur", nu o platformă de billing. Deci NU plăti pentru Stripe Billing (0.7%); folosește doar Stripe Payments cu off-session charges, sau Netopia cu token. Aici Netopia și Stripe sunt la egalitate ca fit.
3. Încredere la checkout pentru publicul țintă (avocați, cabinete RO). Netopia/PayU sunt branduri familiare pe piața RO; Stripe e mai „internațional". Pentru B2B, e un factor minor, dar real.
4. Calitatea API + efort de mentenanță. Stripe e superior clar. Netopia v2 e acceptabil și ai deja abstracția în cod.
5. Payout RON + cont bancar local + reconciliere. Toate procesatoarele locale plătesc în RON nativ. Stripe suportă acquiring local și payout RON, dar verifică termenii exacți la onboarding.
6. e-Factura / fiscal. Niciun procesator nu rezolvă e-Factura pentru tine; tu o faci deja prin Oblio/SmartBill. Deci nu e un criteriu de departajare. (Doar reține: procesatorul plătește, providerul fiscal emite factura, cele două sisteme sunt separate în arhitectura ta.)

---

## 4. Recomandare pentru LexRecovery

Două scenarii rezonabile:

- Scenariul „local-first" (recomandat pentru lansare RO): rămâi pe Netopia. Motive: brand cunoscut de avocați/cabinete, comisioane competitive negociabile, suport local, și deja ai codul pregătit (`PaymentGatewayInterface`, DTO, `externalId`). Efort minim, risc de adopție minim pe piața RO.

- Scenariul „developer-first / scalare": Stripe (doar Payments, fără Billing). Motive: cel mai bun API și recurring off-session, retry inteligent pe carduri expirate (reduce churn involuntar), docs excelente. Dezavantaj: brand mai puțin familiar la checkout pentru publicul RO și acquiring-ul local trebuie confirmat.

Sfat practic: arhitectura ta permite ambele fără rescriere (interfața `PaymentGatewayInterface`). Poți implementa Netopia acum pentru lansare, iar dacă recurring-ul sau churn-ul devin probleme, adaugi un `StripePaymentGateway` ca al doilea implementor și comuți prin env. Nu te blochezi în nicio direcție.

Verdict: pentru go-live pe piața românească, Netopia este alegerea pragmatică corectă (nu pentru că e „cea mai bună" absolut, ci pentru fit-ul local + costul de implementare deja plătit în arhitectură). Stripe rămâne cea mai bună opțiune tehnică de rezervă/scalare.

---

## 5. Pași de decizie recomandați

1. Cere oferte scrise de comision (Netopia + Stripe + încă un local, ex. EuPlătesc sau Twispay) pe volumul tău estimat.
2. Confirmă la Netopia și Stripe: suport recurring off-session (charge automat lunar fără user), termenii token-ului, payout RON, timp settlement.
3. Confirmă onboarding-ul (KYC, contract, cont bancar) și durata de activare POS, care e blocantul pentru go-live.
4. Decide: MVP plăți pe Netopia (local), cu opțiunea Stripe ca al doilea implementor dacă e nevoie.

---

## Surse

- [Best Payment Gateways for Businesses in Romania 2026 (NOWPayments)](https://nowpayments.io/blog/payment-gateway-romania)
- [Romania: 2025 analysis of payments and ecommerce trends (ThePaypers)](https://thepaypers.com/payments/expert-views/romania-2025-analysis-of-payments-and-ecommerce-trends)
- [Procesatori de plăți online, ghid 2026 (Webhipsters)](https://webhipsters.ro/procesatori-de-plati-online-in-romania/)
- [Stripe Billing pricing](https://stripe.com/en-ro/billing/pricing)
- [Stripe, guide to payments in Romania](https://stripe.com/resources/more/payments-in-romania)
- [EuPlătesc](https://www.euplatesc.ro/en/homepage/)
- [NETOPIA Payments](https://netopia-payments.com/en/)
