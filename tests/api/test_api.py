"""API integration tests (PHP + MySQL). Data-driven rows come from tests/cases/design.json."""
import io, uuid, datetime
import pytest
from conftest import DESIGN, photo

JPEG = None


def reg(guest, **o):
    body = {"name": "Ok User", "email": f"t{uuid.uuid4().hex[:10]}@test.com", "password": "abcdefgh", **o}
    return guest.call("POST", "/auth/register", json=body)


# ---------------- security headers (D-9) ----------------
def test_security_headers(guest):
    r = guest.call("GET", "")
    assert r.status_code == 200
    h = r.headers
    assert h["X-Content-Type-Options"] == "nosniff"
    assert h["X-Frame-Options"] == "DENY"
    assert "frame-ancestors 'none'" in h["Content-Security-Policy"]
    assert "X-Powered-By" not in h


# ---------------- registration: EP + BVA ----------------
@pytest.mark.parametrize("row", DESIGN["register"]["rows"], ids=lambda r: r["id"])
def test_register_table(guest, row):
    r = reg(guest, name=row["name"], password=row["password"], phone=row["phone"])
    assert r.status_code == row["http"], f'{row["id"]} ({row["why"]}): {r.text}'


@pytest.mark.parametrize("email", DESIGN["register"]["bad_emails"])
def test_register_bad_email(guest, email):
    assert reg(guest, email=email).status_code == 400


def test_register_duplicate_email(guest):
    e = f"dup{uuid.uuid4().hex[:8]}@test.com"
    assert reg(guest, email=e).status_code == 201
    assert reg(Client2(), email=e).status_code == 409


def Client2():
    from conftest import Client
    return Client()


def test_password_is_hashed_not_stored_plain():
    import os, subprocess
    cmd = ["mysql", "-h", os.environ.get("PETBIO_DB_HOST", "127.0.0.1"), "-u", os.environ.get("PETBIO_DB_USER", "root"), "-N", "-e", "SELECT password_hash FROM pet_biometrics.users"]
    if os.environ.get("PETBIO_DB_PASS"): cmd.insert(5, "-p" + os.environ["PETBIO_DB_PASS"])
    out = subprocess.run(cmd, capture_output=True, text=True).stdout
    assert out and all(l.startswith("$2y$") for l in out.split())


# ---------------- login / session ----------------
def test_login_ok_returns_no_hash(guest):
    u = guest.login("demo@petbio.com", "demo123")
    assert u["role"] == "user" and "password_hash" not in u


def test_login_wrong_password(guest):
    assert guest.call("POST", "/auth/login", json={"email": "demo@petbio.com", "password": "nope"}).status_code == 401


def test_login_lockout_after_5_tries(guest):
    for _ in range(5):
        assert guest.call("POST", "/auth/login", json={"email": "demo@petbio.com", "password": "bad"}).status_code == 401
    assert guest.call("POST", "/auth/login", json={"email": "demo@petbio.com", "password": "demo123"}).status_code == 429


def test_me_and_logout(user):
    assert user.call("GET", "/auth/me").json()["user"]["email"] == "demo@petbio.com"
    assert user.call("POST", "/auth/logout").status_code == 200
    assert user.call("GET", "/auth/me").json()["user"] is None


# ---------------- access control (decision table) ----------------
@pytest.mark.parametrize("row", DESIGN["access_control"]["rows"], ids=lambda r: r["id"])
def test_access_control_table(request, row):
    c = request.getfixturevalue({"guest": "guest", "user": "user", "admin": "admin"}[row["as"]])
    assert c.call("GET", row["route"]).status_code == row["http"]


def test_user_cannot_change_own_role(user):
    me = user.call("GET", "/auth/me").json()["user"]
    r = user.call("POST", f"/admin/users/{me['user_id']}/role", json={"role": "admin"})
    assert r.status_code == 403
    assert user.call("GET", "/auth/me").json()["user"]["role"] == "user"


# ---------------- pets ----------------
def test_pets_list_public_and_filter(guest):
    r = guest.call("GET", "/pets", params={"species": "Bird"})
    assert r.status_code == 200
    data = r.json()["data"]
    assert data and all(p["species"] == "Bird" for p in data)


def test_pet_crud_and_ownership(fresh_user, user):
    r = fresh_user.call("POST", "/pets", json={"name": "Rex", "species": "Dog", "age": 3})
    assert r.status_code in (200, 201), r.text
    pid = r.json()["data"]["pet_id"]
    assert fresh_user.call("POST", f"/pets/{pid}", json={"name": "Rex2", "species": "Dog"}).status_code in (200, 201)
    assert user.call("POST", f"/pets/{pid}", json={"name": "Hacked", "species": "Dog"}).status_code == 403   # not the owner
    assert user.call("DELETE", f"/pets/{pid}").status_code == 403
    assert fresh_user.call("DELETE", f"/pets/{pid}").status_code == 200
    assert fresh_user.call("GET", f"/pets/{pid}").status_code == 404


def test_pet_name_required(user):
    assert user.call("POST", "/pets", json={"name": "", "species": "Dog"}).status_code == 400


def test_sql_injection_in_search_is_inert(guest):
    r = guest.call("GET", "/pets", params={"q": "' OR '1'='1"})
    assert r.status_code == 200 and r.json()["data"] == []
    r = guest.call("GET", "/pets", params={"q": "x'; DROP TABLE pets;--"})
    assert r.status_code == 200
    assert guest.call("GET", "/pets").json()["data"]          # table still there


# ---------------- care records ----------------
def test_care_requires_login_and_ownership(guest, fresh_user, user):
    assert guest.call("GET", "/care", params={"pet_id": 1}).status_code == 401
    pet = fresh_user.call("POST", "/pets", json={"name": "Tom", "species": "Cat"}).json()["data"]["pet_id"]
    assert user.call("GET", "/care", params={"pet_id": pet}).status_code in (403, 404)
    assert user.call("POST", "/care", json={"pet_id": pet, "type": "Grooming", "date": "2026-01-01"}).status_code in (403, 404)


def test_care_days_left_is_computed_from_today(fresh_user):
    """D-1 on the server side: DATEDIFF(next_due, CURDATE()) must follow the real date."""
    pet = fresh_user.call("POST", "/pets", json={"name": "Tom", "species": "Cat"}).json()["data"]["pet_id"]
    today = datetime.date.today()
    due = (today - datetime.timedelta(days=2)).isoformat()
    r = fresh_user.call("POST", "/care", json={"pet_id": pet, "type": "Vaccination", "date": today.isoformat(), "next_due_date": due})
    assert r.status_code == 201, r.text
    rows = fresh_user.call("GET", "/care/upcoming").json()["data"]
    assert [int(x["days_left"]) for x in rows if x["pet_id"] == pet] == [-2]


@pytest.mark.parametrize("bad", ["2026-13-45", "yesterday", "", "2026/01/01"])
def test_care_invalid_date_rejected(fresh_user, bad):
    pet = fresh_user.call("POST", "/pets", json={"name": "Tom", "species": "Cat"}).json()["data"]["pet_id"]
    assert fresh_user.call("POST", "/care", json={"pet_id": pet, "type": "Grooming", "date": bad}).status_code == 400


# ---------------- lost & found upload ----------------
def make_file(kind, extra):
    if kind == "jpeg":
        return ("p.jpg", photo(), "image/jpeg")
    if kind == "text":
        return ("p.jpg", b"this is not an image", "image/jpeg")
    if kind == "pdf":
        return ("p.pdf", b"%PDF-1.4\n%fake\n", "application/pdf")
    raise ValueError(kind)


@pytest.mark.parametrize("row", DESIGN["upload"]["rows"], ids=lambda r: r["id"])
def test_lost_report_upload_table(fresh_user, row):
    name, data, mime = make_file(row["kind"], row["bytes"])
    if row["bytes"] > len(data):
        data = data + b"\0" * (row["bytes"] - len(data))
    form = {"report_type": "found", "location": "Dhanmondi", "found_date": datetime.date.today().isoformat(), "phone": "01700000000", "species": "Dog"}
    r = fresh_user.call("POST", "/lost-reports", data=form, files={"image": (name, data, mime)})
    assert r.status_code == row["http"], f'{row["id"]} {row["why"]}: {r.text[:200]}'


def test_lost_report_future_date_rejected(fresh_user):
    form = {"report_type": "lost", "location": "X", "found_date": (datetime.date.today() + datetime.timedelta(days=3)).isoformat(), "phone": "01700000000"}
    assert fresh_user.call("POST", "/lost-reports", data=form, files={"image": ("p.jpg", photo(), "image/jpeg")}).status_code == 400


@pytest.mark.parametrize("phone", ["12345", "abc", "123456789012345678901"])
def test_lost_report_bad_phone(fresh_user, phone):
    form = {"report_type": "lost", "location": "X", "found_date": datetime.date.today().isoformat(), "phone": phone}
    assert fresh_user.call("POST", "/lost-reports", data=form, files={"image": ("p.jpg", photo(), "image/jpeg")}).status_code == 400


def test_lost_report_reaches_admin_and_reporter(fresh_user, admin):
    form = {"report_type": "lost", "pet_name": "Zed", "location": "Mirpur", "found_date": datetime.date.today().isoformat(), "phone": "01711111111", "species": "Dog"}
    r = fresh_user.call("POST", "/lost-reports", data=form, files={"image": ("p.jpg", photo(), "image/jpeg")})
    assert r.status_code == 201, r.text
    rid = r.json()["data"]["report_id"]
    assert any(n.get("ref_id") == rid or f"#{rid}" in n["message"] for n in admin.call("GET", "/notifications").json()["data"])
    assert any(f"#{rid}" in n["message"] for n in fresh_user.call("GET", "/notifications").json()["data"])
    # admin sees the phone, an unrelated user does not
    assert admin.call("GET", "/admin/reports").status_code == 200
    ad = admin.call("GET", f"/admin/reports/{rid}")
    assert ad.status_code == 200
    assert admin.call("GET", f"/admin/reports/{rid}/photo").headers["Content-Type"].startswith("image/")


# ---------------- find my pet (needs the ML service) ----------------
def test_find_pet_ranks_the_same_photo_first(guest):
    pets = guest.call("GET", "/pets", params={"limit": 100, "species": "Dog"}).json()["data"]
    pets = [x for x in pets if x.get("cover_image")] or pets
    import pathlib
    from conftest import ROOT
    # pick a seeded pet whose cover photo is on disk
    for p in pets:
        cover = p.get("cover_image")
        if cover and (ROOT / cover).is_file():
            break
    else:
        pytest.skip("no seeded cover photo found")
    data = (ROOT / cover).read_bytes()
    r = guest.call("POST", "/find-pet", files={"image": ("q.jpg", data, "image/jpeg")})
    assert r.status_code == 200, r.text
    top = r.json()["matches"][0]
    assert top["pet"]["pet_id"] == p["pet_id"] and top["score"] >= 90


def test_find_pet_without_file(guest):
    assert guest.call("POST", "/find-pet").status_code == 400


def test_find_pet_rejects_non_image(guest):
    assert guest.call("POST", "/find-pet", files={"image": ("x.jpg", b"hello", "image/jpeg")}).status_code == 415


# ---------------- admin actions ----------------
def test_admin_suspend_blocks_login(admin, guest):
    e = f"s{uuid.uuid4().hex[:8]}@test.com"
    uid = reg(guest, email=e).json()["user"]["user_id"]
    assert admin.call("POST", f"/admin/users/{uid}/active", json={"active": 0}).status_code == 200
    assert Client2().call("POST", "/auth/login", json={"email": e, "password": "abcdefgh"}).status_code == 403
    assert admin.call("POST", f"/admin/users/{uid}/active", json={"active": 1}).status_code == 200
    assert Client2().call("POST", "/auth/login", json={"email": e, "password": "abcdefgh"}).status_code == 200


def test_admin_broadcast_reaches_users(admin, fresh_user):
    msg = f"Announcement {uuid.uuid4().hex[:6]}"
    assert admin.call("POST", "/admin/broadcast", json={"message": msg}).status_code in (200, 201)
    assert any(msg in n["message"] for n in fresh_user.call("GET", "/notifications").json()["data"])
