# PetBiometrics

Register pets, adopt, care tracker, and **lost & found with photo matching**.

## Run it (2 minutes, no server needed)

```
cd petbio
python -m http.server 8000      # then open http://localhost:8000
```
Demo mode keeps everything in the browser (localStorage), including the photo matching.
Do not open the files by double-click: browsers block reading image pixels from `file://`.

Demo logins (users and admins sign in on separate pages):
* **User:** `register.html` (Login tab) - any email and password except the admin email.
* **Admin:** `admin-login.html` - `admin@petbio.com` / `admin123`. Admins get their own menu (Admin panel, Reports inbox, Gallery) and cannot open user pages; users cannot open the admin panel.

## What the admin can control (admin panel)

* **Overview:** live counts, a "needs your attention" list, pets by species, reports by status.
* **Lost/Found reports:** review, download photo, ML match, confirm / not a match / reunited, remove.
* **Users:** search, make admin / user, **suspend or re-activate** (suspended users cannot sign in), message one user, delete.
* **Pets:** search and filter, change a pet's status (with owner / for adoption / lost / adopted) from the table, view, delete.
* **Adoption requests:** filter, approve, reject.
* **Announcements:** send a notification to all users or to one user.

## Lost & found: how it works

1. **User** opens *Lost & Found* → *I lost my pet* (or *I found a pet*), uploads a photo, says where and when, leaves a phone number.
2. The photo is fingerprinted by the ML code and compared with every registered pet and with the opposite kind of report.
3. **Admin** gets a notification in the *Reports inbox* showing the lost pet (name, breed, owner and phone, last-seen place, the photo that was sent next to the registered photo) with a **Review & match** button and a **Download photo** button.
4. In the match console the admin sees the closest photos with a similarity score, can slide-compare two photos, or upload any other photo to compare, then **Confirm match**, **Not a match**, or **Mark reunited**. The people involved are notified.

Pages: `lost-found.html` (users), `notifications.html` and `admin.html` → *Lost/Found reports* (admin), `find-my-pet.html` (anyone can search by photo), `gallery.html` (45 pets: 18 dogs, 20 cats, 7 parrots).

## The ML part (honest version)

`ml/engine.py` (Python) and `ml-lite.js` (browser) compute the same photo fingerprint: colour histogram + 4x4 colour layout + edge-orientation histogram, averaged with the mirrored photo. Similarity is the cosine of two fingerprints, rescaled to 0-100.

* Tested on the 45 gallery photos: the same photo cropped, brightened, flipped, rotated or shrunk is ranked first 44-45 times out of 45.
* It matches **how photos look** (colour, pattern, shape). It is not real biometric identification: two pets with similar coats can score high. That is why a person (the admin) makes the final decision.
* Better accuracy: `pip install tensorflow` and start the service with `PETBIO_ENGINE=mobilenet PETBIO_FLOOR=0.45`, then call `POST /api/index.php/admin/reindex` once. `ml/pet_classifier.ipynb` is the original classifier notebook.

## Run with the real backend (PHP + MySQL + Python)

1. Start Apache + MySQL (XAMPP). Put the `petbio` folder in `htdocs`.
2. phpMyAdmin → Import `db/pet_biometrics.sql` (this resets the database).
   Logins: `admin@petbio.com / admin123`, `demo@petbio.com / demo123` (change them before going public).
3. ML service:
   ```
   pip install -r ml/requirements.txt
   python ml/service.py          # http://127.0.0.1:5000
   ```
4. In `app.js` set `USE_DEMO: false` and `API_BASE` to your URL (default `/api/index.php`, i.e. `http://localhost/petbio/api/index.php` if the site is in a sub-folder).
5. DB password or ML address differ? Edit the top of `api/index.php` or set `PETBIO_DB_USER`, `PETBIO_DB_PASS`, `PETBIO_DB_HOST`, `PETBIO_DB_NAME`, `PETBIO_ML_URL`.

If the ML service is off, reports are still saved; fingerprints are filled in when the admin opens the report. If you change engine, run `POST /api/index.php/admin/reindex`.

New API routes: `GET /admin/users`, `POST /admin/users/{id}/active`, `GET /admin/pets`, `POST /admin/pets/{id}/status`, `POST /admin/broadcast`, `GET/POST /lost-reports`, `GET /admin/reports`, `GET /admin/reports/{id}` (look-alikes), `GET /admin/reports/{id}/photo` (download), `POST /admin/reports/{id}/compare`, `POST /admin/reports/{id}/decide`, `POST /admin/reindex`. Phone numbers and admin notes are only returned to admins and to the person who sent the report.

## Add or change pets

Edit the list `R` in `tools/build_roster.py` (put the photo in `photos_src/`), run `python tools/build_roster.py`. It rebuilds `photos/pets/`, `photos.js` and the SQL seed (with fingerprints).
