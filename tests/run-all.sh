#!/usr/bin/env bash
# Runs EVERYTHING: lint -> unit -> API+ML -> browser (functional + accessibility), with coverage and thresholds.
# Needs: MySQL/MariaDB already running with the schema imported (db/pet_biometrics.sql), node 20+, python 3.10+, php 8+.
# Used by .github/workflows/ci.yml and runnable locally:  bash tests/run-all.sh
set -u
cd "$(dirname "$0")/.."
OUT=tests/reports; rm -rf "$OUT" tests/.cov .coverage*; mkdir -p "$OUT"
JS_MIN=${JS_MIN:-85}; PY_MIN=${PY_MIN:-85}
FAIL=0; step() { echo; echo "=================== $1"; }
run()  { local name=$1; shift; "$@" 2>&1 | tee "$OUT/$name.log"; local rc=${PIPESTATUS[0]}; [ $rc -eq 0 ] || { echo "!! $name FAILED"; FAIL=1; }; }

PIDS=()
cleanup() { for p in "${PIDS[@]}"; do kill -INT "$p" 2>/dev/null; done; sleep 2; for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; }
trap cleanup EXIT

step "start services"
for port in 5000 8080 8000; do
  if (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null; then echo "Port $port is already in use. Stop the old server first (a stale one would make the coverage numbers wrong)."; exit 1; fi
done
( cd ml && exec python3 -m coverage run --data-file="$PWD/../.coverage.svc" --source=. service.py >"../$OUT/ml.log" 2>&1 ) & PIDS+=($!)
php -d upload_max_filesize=8M -d post_max_size=9M -S 127.0.0.1:8080 -t . >"$OUT/php.log" 2>&1 & PIDS+=($!)
python3 -m http.server 8000 >"$OUT/static.log" 2>&1 & PIDS+=($!)
for i in $(seq 1 40); do
  curl -sf http://127.0.0.1:5000/health >/dev/null && curl -sf http://127.0.0.1:8080/api/index.php >/dev/null && curl -sf http://127.0.0.1:8000/index.html >/dev/null && break
  sleep 1
done
curl -sf http://127.0.0.1:5000/health >/dev/null || { echo "ML service did not start"; exit 1; }

step "1. static checks (lint)";               run lint node tests/lint.js
step "2. unit tests + coverage (c8)";         run unit npx c8 --include=app.js --include=ml-lite.js --reporter=text --reporter=json-summary --reports-dir="$OUT/c8-unit" node --test "tests/unit/*.test.js"
step "3. API + ML tests (pytest)";            run api python3 -m coverage run --data-file=.coverage.tests --source=ml -m pytest tests/api -q -p no:cacheprovider --junitxml="$OUT/pytest.xml"
step "4. browser tests (functional + a11y)";  COVERAGE=1 run e2e node --test --test-concurrency=1 "tests/e2e/*.test.js"

step "coverage"
kill -INT "${PIDS[0]}" 2>/dev/null; sleep 3
python3 -m coverage combine --keep --data-file=.coverage .coverage.svc .coverage.tests >/dev/null 2>&1
PYCOV=$(python3 -m coverage report --data-file=.coverage --include="ml/*" | tee "$OUT/coverage-python.txt" | tail -1 | awk '{print $NF}' | tr -d '%'); echo "Python (ml/): ${PYCOV}%"
node tests/coverage-report.js | tee "$OUT/coverage-browser.txt"
JSCOV=$(node tests/coverage-report.js --exclude-data | tail -1 | awk '{print $NF}' | tr -d '%'); echo "Browser JS (without the photos.js data file): ${JSCOV}%"
awk -v v="$JSCOV" -v m="$JS_MIN" 'BEGIN{exit !(v+0>=m)}' || { echo "!! JS coverage ${JSCOV}% < ${JS_MIN}%"; FAIL=1; }
awk -v v="$PYCOV" -v m="$PY_MIN" 'BEGIN{exit !(v+0>=m)}' || { echo "!! Python coverage ${PYCOV}% < ${PY_MIN}%"; FAIL=1; }

step "summary"
for f in unit e2e; do grep -E "^# (tests|pass|fail)" "$OUT/$f.log" | tr '\n' ' '; echo " <- $f"; done
tail -1 "$OUT/api.log"
[ $FAIL -eq 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $FAIL
