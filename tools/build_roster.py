"""Build the pet roster: copies one real photo per pet, writes photos.js (demo data) and the SQL seed.

Every pet is a *different animal* with its own photo (the old setup used 10 photos of 10 different animals
for each pet, which makes biometric matching meaningless).

Run from the project folder:   python tools/build_roster.py
Source photos are read from photos_src/ (kept so the build is repeatable).
"""
import json, os, re, shutil, sys
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

# (id, name, species, breed, age, gender, color, status, city, owner, source photo, about)
R = [
 (1, "Bruno", "Dog", "Golden Retriever", 3, "Male", "Golden", "adoption", "Dhaka", "demo", "7/02", "Soft-eyed and gentle. Brings you a toy before you have even sat down. Fully vaccinated and good with children."),
 (2, "Luna", "Cat", "Persian", 2, "Female", "White", "owned", "Dhaka", "demo", "8/10", "Long white coat, calm manner. Sleeps wherever the sun lands and tolerates brushing like a queen."),
 (3, "Max", "Dog", "German Shepherd", 4, "Male", "Black & Tan", "adoption", "Chattogram", "rahim", "3/02", "Loyal and quick to learn. Knows sit, stay and heel. Needs an active family with some outdoor space."),
 (4, "Milo", "Cat", "Orange Tabby", 1, "Male", "Orange", "adoption", "Sylhet", "rahim", "4/04", "Blue-eyed kitten who attacks shoelaces. Litter trained and fine with other cats."),
 (5, "Sandy", "Dog", "Labrador", 4, "Male", "Yellow", "owned", "Cox's Bazar", "nadia", "1/05", "Beach dog. Will fetch a stick from the sea all afternoon and then sleep in the car."),
 (6, "Kiwi", "Bird", "Green Parakeet", 5, "Female", "Green", "lost", "Rajshahi", "nadia", "6/04", "Green parakeet with a red patch above the beak. Whistles a short tune and says a few words. Went missing near the central park."),
 (7, "Daisy", "Dog", "Corgi", 1, "Female", "Tricolor", "adoption", "Khulna", "tanvir", "1/03", "Corgi puppy with enormous ears. Friendly with everyone, needs daily walks."),
 (8, "Oreo", "Cat", "Siamese Mix", 3, "Male", "Cream & Brown", "adoption", "Chattogram", "tanvir", "2/06", "Talkative and social. Follows you from room to room and comments on everything."),
 (9, "Biscuit", "Dog", "Pug Mix", 6, "Male", "Brindle", "lost", "Dhaka", "demo", "1/01", "Brindle face with a crooked grin and a missing front tooth. Wears a dark collar. Slipped out of the gate in Mirpur."),
 (10, "Snow", "Dog", "Samoyed Mix", 5, "Female", "White", "owned", "Sylhet", "rahim", "1/02", "Thick white coat, mellow temperament. Loves cool tiled floors and being talked to."),
 (11, "Shiro", "Dog", "Shiba Inu Mix", 3, "Male", "Tan", "adoption", "Dhaka", "nadia", "1/08", "Independent and tidy. Prefers a quiet home and a routine."),
 (12, "Casper", "Dog", "Golden Retriever", 2, "Male", "Cream", "owned", "Dhaka", "demo", "1/09", "Cream-coloured and endlessly patient. Sits for photos like he has done it before."),
 (13, "Jack", "Dog", "Jack Russell Terrier", 3, "Male", "White & Tan", "adoption", "Rajshahi", "tanvir", "3/01", "Small, fast and fearless. Digs. Best with an owner who enjoys long runs."),
 (14, "Sunny", "Dog", "Golden Retriever", 2, "Female", "Golden", "owned", "Chattogram", "rahim", "3/03", "Catches anything thrown, including things nobody threw."),
 (15, "Sausage", "Dog", "Dachshund", 4, "Male", "Red", "lost", "Khulna", "tanvir", "3/06", "Red dachshund with a pink tongue that is always out. Answers to Sausage, mostly. Last seen near the Khulna rail station."),
 (16, "Pixie", "Dog", "Chihuahua", 2, "Female", "Cream", "owned", "Barishal", "nadia", "3/09", "Tiny, loud and sure she is a big dog. Travels in a tote bag."),
 (17, "Rocky", "Dog", "Rottweiler Mix", 5, "Male", "Black & Tan", "adoption", "Rangpur", "rahim", "3/10", "Big grin, bigger heart. Needs training and an owner who is firm and kind."),
 (18, "Mochi", "Dog", "Papillon Mix", 3, "Female", "Cream & White", "owned", "Dhaka", "demo", "7/01", "Lap dog with feathered ears. Happiest being stroked."),
 (19, "Honey", "Dog", "Labrador", 4, "Female", "Yellow", "adoption", "Cumilla", "tanvir", "7/05", "Waggy, bouncy and house-trained. Loves water."),
 (20, "Pugsley", "Dog", "Pug", 5, "Male", "Fawn", "owned", "Mymensingh", "nadia", "7/09", "Snores. Sulks when dinner is late. Forgives instantly."),
 (21, "Zoom", "Dog", "Greyhound", 6, "Male", "Fawn", "adoption", "Dhaka", "rahim", "1/04", "Retired racer. Sprints for ten seconds, then lies on his back for ten hours."),
 (22, "Desi", "Dog", "Local Mix", 3, "Male", "Tan", "adoption", "Chattogram", "nadia", "3/07", "Rescued from the roadside, now healthy and vaccinated. Lean, alert and very street-smart."),
 (23, "Shadow", "Cat", "Domestic Shorthair", 4, "Male", "Black", "owned", "Dhaka", "demo", "2/01", "Watches the street from the windowsill all day. Yellow eyes, soft purr."),
 (24, "Smokey", "Cat", "Tabby", 2, "Female", "Gray", "adoption", "Sylhet", "nadia", "2/02", "Green-eyed and playful. Likes to be upside down for no reason."),
 (25, "Ginger", "Cat", "Tabby", 6, "Male", "Orange & White", "owned", "Khulna", "rahim", "2/03", "Squints at you like he knows something. Loves a warm lap."),
 (26, "Leo", "Cat", "Maine Coon Mix", 5, "Male", "Brown Tabby", "owned", "Dhaka", "tanvir", "2/04", "Long fur, long whiskers, a small bell on his collar."),
 (27, "Tiger", "Cat", "Tabby", 3, "Male", "Orange", "adoption", "Rajshahi", "rahim", "2/07", "Sits upright and waits to be admired. Easy to live with."),
 (28, "Pixel", "Cat", "Tabby", 2, "Female", "Orange", "owned", "Dhaka", "demo", "4/02", "Sleeps on the keyboard. Has deleted at least one assignment."),
 (29, "Kalo", "Cat", "Bombay", 4, "Male", "Black", "adoption", "Barishal", "tanvir", "4/05", "Sleek black cat with copper eyes. Quiet, affectionate, clean."),
 (30, "Mimi", "Cat", "Calico", 3, "Female", "Calico", "lost", "Chattogram", "nadia", "4/09", "Orange, black and white patches with a white chest. Very vocal. Went missing near GEC Circle."),
 (31, "Ash", "Cat", "British Shorthair", 1, "Male", "Gray", "adoption", "Dhaka", "rahim", "8/01", "Round-faced kitten with a stealthy crawl."),
 (32, "Duchess", "Cat", "Persian", 4, "Female", "Cream & White", "owned", "Sylhet", "tanvir", "8/03", "A large, magnificent tail and the attitude to match."),
 (33, "Yoda", "Cat", "Selkirk Rex", 3, "Male", "Gray", "owned", "Dhaka", "nadia", "8/04", "Curly grey fur and a permanent look of mild disapproval."),
 (34, "Boo", "Cat", "Scottish Fold", 2, "Male", "Tabby", "adoption", "Khulna", "tanvir", "8/05", "Folded ears, round amber eyes, alarmed by everything and then fine."),
 (35, "Nimbu", "Cat", "Domestic Shorthair", 1, "Female", "Orange", "adoption", "Cumilla", "nadia", "8/06", "Small orange kitten who looks straight up at you when she wants food."),
 (36, "Pudding", "Cat", "Munchkin", 2, "Female", "White & Cream", "owned", "Mymensingh", "rahim", "8/07", "Short legs, big eyes, enormous toe beans."),
 (37, "Cloud", "Cat", "Bicolor Shorthair", 3, "Male", "Gray & White", "owned", "Rangpur", "tanvir", "4/10", "Closes his eyes whenever he is happy, which is often."),
 (38, "Bhola", "Cat", "Tabby", 2, "Male", "Brown Tabby", "adoption", "Dhaka", "nadia", "4/03", "Rolls in grass, then looks surprised about it."),
 (39, "Gizmo", "Cat", "Tabby", 5, "Male", "Brown Tabby", "owned", "Chattogram", "rahim", "4/01", "Sticks his tongue out when he is thinking."),
 (40, "Pesto", "Bird", "Green-cheeked Conure", 2, "Male", "Green", "owned", "Dhaka", "demo", "6/01", "Green-cheeked conure. Climbs, chews and whispers."),
 (41, "Rio", "Bird", "Scarlet Macaw", 8, "Male", "Scarlet Red", "owned", "Cox's Bazar", "tanvir", "6/02", "Flies free in the garden every evening and comes back when whistled."),
 (42, "Ruby", "Bird", "Scarlet Macaw", 9, "Female", "Red & Yellow", "adoption", "Dhaka", "nadia", "6/03", "Loud, social and bright. Needs a large aviary and a lot of company."),
 (43, "Jaco", "Bird", "African Grey", 12, "Male", "Grey", "owned", "Sylhet", "rahim", "6/05", "Speaks in full sentences and does the doorbell. Very clever."),
 (44, "Sky", "Bird", "Blue-and-gold Macaw", 10, "Female", "Blue & Gold", "adoption", "Chattogram", "tanvir", "6/06", "Striped white face, powerful beak, gentle with people she knows."),
 (45, "Mango", "Bird", "Green-cheeked Conure", 3, "Female", "Green & Olive", "owned", "Khulna", "nadia", "6/08", "Happy to sit on a finger through a whole family dinner."),
]
HASH_ADMIN = "$2y$10$UHxN1iZi2afc5EuIiCbwkOv49rVYP5P1jQgKoAxN/u0mKKuNP3ene"   # admin123
HASH_USER = "$2y$10$gH1MWgQJPv.sqyySBc5sLOLb5EUhI4WEGvDh8XsXES/95HgdmLGU2"    # demo123
OWNER_DEMO = {"demo": 1, "rahim": 2, "nadia": 3, "tanvir": 4}   # frontend demo user ids
OWNER_SQL = {"demo": 2, "rahim": 3, "nadia": 4, "tanvir": 5}    # ids in pet_biometrics.sql


def slug(s): return re.sub(r"[^a-z0-9]+", "-", s.lower()).strip("-")


def main():
    # keep the original dataset photos in photos_src/ the first time we run
    if not os.path.isdir("photos_src"):
        os.makedirs("photos_src")
        for pid in (1, 2, 3, 4, 6, 7, 8):
            for n in range(1, 11):
                shutil.copy(f"photos/{pid}/{n:02d}.jpg", f"photos_src/{pid}-{n:02d}.jpg")
    shutil.rmtree("photos/pets", ignore_errors=True); os.makedirs("photos/pets")
    pets, photos, sql_pets, sql_imgs = [], {}, [], []
    for (pid, name, sp, breed, age, gender, color, status, city, owner, src, about) in R:
        a, b = src.split("/")
        dst = f"photos/pets/{pid:02d}-{slug(name)}.jpg"
        shutil.copy(f"photos_src/{a}-{int(b):02d}.jpg", dst)
        photos[pid] = [dst]
        w, h = Image.open(dst).size
        pets.append(dict(pet_id=pid, owner_id=OWNER_DEMO[owner], name=name, species=sp, breed=breed, age=age,
                         gender=gender, color=color, status=status, location=city, description=about, ar=round(w / h, 3)))
        q = lambda s: "'" + s.replace("'", "''") + "'"
        sql_pets.append(f" ({pid},{OWNER_SQL[owner]},{q(name)},{q(sp)},{q(breed)},{age},{q(gender)},{q(color)},{q(about)},{q(status)},{q(city)})")
        sql_imgs.append(f" ({pid},{q(dst)},1)")
    os.makedirs("data", exist_ok=True)
    json.dump(pets, open("data/roster.json", "w"), indent=1, ensure_ascii=False)
    with open("photos.js", "w") as f:
        f.write("/* Generated by tools/build_roster.py - one real photo per pet. */\n")
        f.write("window.PHOTOS = " + json.dumps(photos, indent=1) + ";\n")
        f.write("window.DEMO_PETS = " + json.dumps(pets, indent=1, ensure_ascii=False) + ";\n")
    # SQL seed: everything after the "Seed data" marker is regenerated, so re-running is safe
    sys.path.insert(0, os.path.join(ROOT, "ml"))
    from engine import make_engine, load_rgb            # same fingerprints the ML service computes
    eng = make_engine("descriptor")
    q = lambda s: "NULL" if s is None else "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"
    sql_pets, sql_imgs, posts = [], [], []
    for p, (pid, name, sp, breed, age, gender, color, status, city, owner, src, about) in zip(pets, R):
        sql_pets.append(f" ({pid},{OWNER_SQL[owner]},{q(name)},{q(sp)},{q(breed)},{age},{q(gender)},{q(color)},{q(about)},{q(status)},{q(city)},{p['ar']})")
        vec = eng.embed(load_rgb(open(photos[pid][0], "rb").read()))
        sql_imgs.append(f" ({pid},{q(photos[pid][0])},1,{q(json.dumps([round(float(x), 5) for x in vec], separators=(',', ':')))})")
        if status == "adoption":
            posts.append(f" ({pid},{OWNER_SQL[owner]},{q(city)},{q(about.split('.')[0] + '.')},'active')")
    head = open("db/pet_biometrics.sql", encoding="utf-8").read().split("-- =====================================================================\n--  Seed data (demo)")[0]
    seed = f"""-- =====================================================================
--  Seed data (demo) - generated by tools/build_roster.py, do not edit by hand
--  Logins:  admin@petbio.com / admin123     demo@petbio.com, rahim@mail.com, ... / demo123
--  Change these passwords before the site is public.
-- =====================================================================
INSERT INTO users (name, email, password_hash, phone, role, address) VALUES
 ('Admin',          'admin@petbio.com',  '{HASH_ADMIN}', '01700000005', 'admin', 'Dhaka'),
 ('Demo User',      'demo@petbio.com',   '{HASH_USER}', '01700000001', 'user',  'Dhaka'),
 ('Rahim Uddin',    'rahim@mail.com',    '{HASH_USER}', '01700000002', 'user',  'Chattogram'),
 ('Nadia Karim',    'nadia@mail.com',    '{HASH_USER}', '01700000003', 'user',  'Dhaka'),
 ('Tanvir Hasan',   'tanvir@mail.com',   '{HASH_USER}', '01700000004', 'user',  'Khulna'),
 ('Sumaiya Akter',  'sumaiya@mail.com',  '{HASH_USER}', '01700000006', 'user',  'Sylhet');

INSERT INTO pets (pet_id, owner_id, name, species, breed, age, gender, color, description, status, location, photo_ratio) VALUES
""" + ",\n".join(sql_pets) + """;

-- One real photo per pet, with its ML fingerprint (embedding_vector, engine descriptor-v1).
-- If you switch ML engine, refresh them:  POST /api/index.php/admin/reindex  (admin only)
INSERT INTO pet_images (pet_id, image_path, is_primary, embedding_vector) VALUES
""" + ",\n".join(sql_imgs) + """;

INSERT INTO care_records (pet_id, type, date, notes, next_due_date) VALUES
 (1,'Vaccination',CURDATE() - INTERVAL 80 DAY,'Rabies booster',CURDATE() + INTERVAL 5 DAY),
 (2,'Deworming',CURDATE() - INTERVAL 27 DAY,'Monthly tablet',CURDATE() + INTERVAL 3 DAY),
 (2,'Vet Checkup',CURDATE() - INTERVAL 130 DAY,'All healthy',CURDATE() + INTERVAL 60 DAY),
 (1,'Grooming',CURDATE() - INTERVAL 35 DAY,'Bath and nail trim',CURDATE() - INTERVAL 5 DAY);

-- every pet with status 'adoption' gets an active post
INSERT INTO adoption_posts (pet_id, posted_by, location, note, status) VALUES
""" + ",\n".join(posts) + """;

INSERT INTO adoption_requests (post_id, requester_id, phone, message, status) VALUES
 (1,3,'01700000002','I have a big garden and two kids who would love Bruno.','pending'),
 (2,4,'01700000003','Experienced with German Shepherds, very interested.','approved');

-- Sample reports (photos are real files in photos/site and photos/pets).
-- Photo fingerprints are computed on first view / by POST /api/index.php/admin/reindex.
INSERT INTO lost_reports (reporter_id, report_type, pet_name, pet_id, image_path, species, description, phone, location, found_date, status) VALUES
 (4,'found',NULL,NULL,'photos/site/lost1.jpg','Dog','English bulldog, white and tan, black collar. Very friendly and hungry.','01700000003','Dhanmondi Lake, Dhaka',CURDATE() - INTERVAL 3 DAY,'open'),
 (5,'found',NULL,NULL,'photos/site/lost2.jpg','Cat','Orange kitten with pale green eyes. Seems to be someone''s pet.','01700000004','GEC Circle, Chattogram',CURDATE() - INTERVAL 4 DAY,'open'),
 (6,'found',NULL,NULL,'photos/site/lost3.jpg','Bird','Red macaw sitting on a tea stall roof. Calm, lets people come close.','01700000006','Zindabazar, Sylhet',CURDATE() - INTERVAL 6 DAY,'open'),
 (4,'lost','Kiwi',6,'photos/pets/06-kiwi.jpg','Bird','Green parakeet with a red patch above the beak. Flew out of an open window.','01700000003','Central Park, Rajshahi',CURDATE() - INTERVAL 2 DAY,'open'),
 (2,'lost','Biscuit',9,'photos/pets/09-biscuit.jpg','Dog','Brindle pug mix, dark collar, missing a front tooth. Slipped out of the gate.','01700000001','Mirpur 10, Dhaka',CURDATE() - INTERVAL 1 DAY,'open');

-- Admin (user 1) is told about every new lost/found report. ref_id = report_id
INSERT INTO notifications (user_id, type, message, ref_id, is_read) VALUES
 (1,'report','New LOST report #5: Biscuit (Dog) near Mirpur 10, Dhaka.',5,0),
 (1,'report','New LOST report #4: Kiwi (Bird) near Central Park, Rajshahi.',4,0),
 (1,'report','New FOUND report #3: Bird near Zindabazar, Sylhet.',3,1),
 (2,'adoption','Rahim Uddin sent an adoption request for Bruno.',NULL,0),
 (2,'care','Luna''s deworming is due in 3 days.',NULL,0),
 (2,'system','We received your lost-pet report for Biscuit. Our team will compare the photo and contact you.',NULL,0),
 (2,'system','Welcome to Pet Biometrics! Add your first pet to get started.',NULL,1);
"""
    open("db/pet_biometrics.sql", "w", encoding="utf-8").write(head + seed)
    print(len(pets), "pets written")


if __name__ == "__main__":
    main()
