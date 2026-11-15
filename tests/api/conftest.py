import io, json, os, pathlib, uuid
import pytest, requests

API = os.environ.get("API_URL", "http://127.0.0.1:8080/api/index.php")
ML = os.environ.get("ML_URL", "http://127.0.0.1:5000")
ROOT = pathlib.Path(__file__).resolve().parents[2]
DESIGN = json.loads((ROOT / "tests/cases/design.json").read_text(encoding="utf-8"))


class Client:
    def __init__(self):
        self.s = requests.Session()

    def call(self, method, route, **kw):
        return self.s.request(method, API + route, timeout=30, **kw)

    def login(self, email, password):
        r = self.call("POST", "/auth/login", json={"email": email, "password": password})
        assert r.status_code == 200, r.text
        return r.json()["user"]


@pytest.fixture
def guest():
    return Client()


@pytest.fixture
def user():
    c = Client(); c.login("demo@petbio.com", "demo123"); return c


@pytest.fixture
def admin():
    c = Client(); c.login("admin@petbio.com", "admin123"); return c


@pytest.fixture
def fresh_user():
    """A brand-new account so tests never depend on each other's data."""
    c = Client()
    email = f"t{uuid.uuid4().hex[:10]}@test.com"
    r = c.call("POST", "/auth/register", json={"name": "Temp Tester", "email": email, "password": "secret12"})
    assert r.status_code == 201, r.text
    c.email, c.uid = email, r.json()["user"]["user_id"]
    return c


def photo(name="dog1"):
    p = sorted((ROOT / "photos/pets").glob("*.jpg"))[0] if name == "dog1" else sorted((ROOT / "photos/pets").glob("*.jpg"))[-1]
    return p.read_bytes()
