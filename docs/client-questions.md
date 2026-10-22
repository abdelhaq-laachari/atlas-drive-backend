# Questions for the client (agency owner)

Open questions to ask at the next meeting. When answered, write the answer under the question and the date,
then tell Claude so the code and CLAUDE.md are updated.

## Users & permissions
1. **Roles or per-person permissions?** Are two fixed roles enough (Owner = everything, Staff = daily work),
   or do you want to tick permissions per staff member (e.g. "can give discounts / waive charges",
   "can see money reports", "can add or edit cars and prices")?
2. **Cars:** who may add a car, edit prices, archive a sold car? (Now: owner only. Staff can only change the
   status: available / maintenance / out of service.)
3. Should staff be allowed to **reduce or waive return charges** (late, fuel, km, damage), or only the owner?
4. **Two-step verification:** required for the owner? For staff too, or optional?

## Rentals rules (values used in the prototype — please confirm)
5. **Day counting:** calendar days + 1 extra day if the car comes back later than the pickup time. Correct?
   Any tolerance (e.g. up to 1 hour late = no extra day)?
6. **Late return:** a rental is overdue more than **60 minutes** after the return time. Right number?
7. **Fuel:** policy "return with the same level as pickup"? Price charged per missing quarter: **150 MAD**?
8. **Kilometres:** included **250 km/day**? Extra-km fee per category (sample: economy 1.50, compact 2,
   SUV/van 2.50, luxury 4 MAD/km)?
9. **Deposits** per category (sample: economy 3,000, compact 4,000, SUV/van 6,000, luxury 15,000 MAD)?
10. **Extras** offered and their prices (child seat, GPS, extra driver, delivery to airport/hotel...)?
11. **Weekly / monthly prices:** fixed per car, or calculated (sample: weekly = 6 × daily, monthly = 22 × daily)?

## Return
22. **Deposit:** do you give the deposit back at the return, or keep the cheque / card hold a few days
    (traffic fines, damage found later)? (Now: settled and given back at the return.)

## Money & invoices
12. **VAT (TVA 20%)**: are your prices shown **with** VAT included or without? Do you need invoices with
    HT / TVA / TTC lines and your ICE / IF / RC numbers?

## Documents
13. **"Expiring soon"** warning: **30 days** before expiry enough for insurance / inspection / vignette?
15. **Documents:** may staff add and correct documents (now: yes), with only the owner deleting one? Do you want to
    keep a **scan / photo** of each document (insurance certificate, carte grise) in the system?
16. **Damage:** may staff mark a damage as repaired and enter the workshop cost (now: yes), or only you?
    Do you want **photos** of damage at hand-over / return?

## Customers
17. Which customer details are **required** for you (now: name, phone, ID type + number, nationality, birth date;
    email, address and licence optional)? Do you keep a **copy/photo of the CIN / passport / licence**?
18. **Blocklist:** only you block/unblock customers (now), or trusted staff too?
19. Do you rent to **companies** today? If yes, how many and how do you bill them (monthly invoice, several cars)?
20. **Foreign customers:** do you accept a foreign driving licence as is, or do you require an international
    driving permit? Any different age / licence rule for them?
21. **Files:** which documents do you want to keep in the system (CIN / passport / licence copies, signed contract,
    deposit cheque photo, car papers)? **How long** should we keep ID copies after a customer's last rental
    (e.g. 2 years, then deleted automatically)?

## Agency details (for contracts and invoices)
14. Real agency name, address, phone, email, logo, legal numbers (ICE, RC, IF, patente).

---

# Before selling the SaaS (for me, not for the client)
- **Talk once to a Moroccan lawyer:** check the contract / terms of use with agencies (data access only for support,
  security or legal reasons; owner-granted support access), my role as service provider under law 09-08, and how
  agencies declare their customer files to the **CNDP**.
- Check the Vuexy licence allows SaaS use (Extended licence).

