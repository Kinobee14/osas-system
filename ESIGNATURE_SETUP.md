# E-Signature feature (added)

## 1. Import the database migration
(Already imported the first version? Import it again — it is safe to re-run and now also creates `signatory_signatures`.)
phpMyAdmin → `osas_db` → Import → `database/migration_esignatures.sql`
(creates `admin_signatures` and `reservation_signatures`; nothing existing is changed).
Until it is imported the system behaves exactly as before.

## 2. How to use it (admin side: `osas`, `academic`, `admin` accounts)
0. **Settings → Reservation Form Signatories**: under *Approved by (1)*, *Approved by (2)* and *Noted by* press
   **Draw E-Signature** to draw that signatory's e-signature (Redraw / Remove also there).
   When that signatory's account presses **Sign Form**, this drawn signature is the one placed on the form;
   it is also what prints on the clearance PDF.
1. **Settings → Reservation Form Signatories → Draw E-Signature** under each signatory.
2. **Approval Queue → Approve.** No manual signing: when an admin account approves a reservation, that
   account's e-signature (drawn in step 1 for its line) is placed on the form automatically, dated when the vote
   was cast. If the vote is undone, the signature comes off. `osas` → Approved by (1), `academic` → Approved by (2),
   `admin` → Noted by. It shows in **View Form** (admin) and on the student's downloaded reservation PDF.
3. **Reports → Clearance / Download Clearance** – the PDF now prints the drawn
   e-signatures (Reviewed by = the reviewing admin's saved signature, Approved by = OSAS,
   Noted by = Admin). If someone has not drawn one yet, the old typed-name e-signature is printed.
   Students downloading their clearance get the same signed PDF.

## Files
New: `database/migration_esignatures.sql`, `shared/esignatures.php`, `admin/api/esignature.php`,
`admin/assets/js/esignature.js`, `admin/assets/css/esignature.css`
Edited (small): `admin/index.html` (2 tags), `shared/clearance.php` (draws signature images),
`shared/clearance_access.php` (all admin accounts may download the clearance)
