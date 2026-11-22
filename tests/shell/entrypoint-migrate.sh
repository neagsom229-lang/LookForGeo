#!/bin/sh
# Harness for the migration retry loop in entrypoint.sh. It extracts only the
# loop (the real script goes on to start Apache/Supervisor), stubs `php`, and
# checks both the recoverable and the permanent-failure paths.
set -u

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin"

# Keep everything up to (but not including) the success marker, then add a
# marker we can assert on.
awk '/Migrations completed/{exit} {print}' "$root/entrypoint.sh" > "$tmp/entrypoint-test.sh"
printf "echo 'MIGRATIONS_OK'\n" >> "$tmp/entrypoint-test.sh"

cat > "$tmp/bin/php" <<'STUB'
#!/bin/sh
if [ "$1" = "artisan" ] && [ "$2" = "migrate" ]; then
  n=$(cat "$MIGRATE_COUNTER" 2>/dev/null || echo 0)
  n=$((n + 1))
  echo "$n" > "$MIGRATE_COUNTER"
  if [ "$n" -le "$MIGRATE_FAILS" ]; then
    echo "SQLSTATE[08006] connection refused (attempt $n)" >&2
    exit 1
  fi
  echo "migrated ok (attempt $n)"
  exit 0
fi
exit 0
STUB
chmod +x "$tmp/bin/php"

# No real waiting in the harness.
printf '#!/bin/sh\nexit 0\n' > "$tmp/bin/sleep"
chmod +x "$tmp/bin/sleep"

run_case() {
  fails=$1
  expected_code=$2
  rm -f "$tmp/counter"
  MIGRATE_FAILS="$fails" MIGRATE_COUNTER="$tmp/counter" PATH="$tmp/bin:$PATH" \
    sh "$tmp/entrypoint-test.sh" > "$tmp/out" 2>&1
  code=$?
  echo "case fails=$fails exit=$code"
  [ "$code" -eq "$expected_code" ] || { echo "FAIL: wrong exit code"; cat "$tmp/out"; exit 1; }
}

# Recover after two failures.
run_case 2 0
grep -q 'MIGRATIONS_OK' "$tmp/out" || { echo 'FAIL: no success marker'; exit 1; }
grep -q 'SQLSTATE\[08006\] connection refused' "$tmp/out" || { echo 'FAIL: real error missing'; exit 1; }
grep -q 'attempt 3/12 failed' "$tmp/out" || true

# Always fails: bounded at 12 attempts and non-zero exit.
run_case 100 1
[ "$(cat "$tmp/counter")" -eq 12 ] || { echo "FAIL: expected 12 attempts, got $(cat "$tmp/counter")"; exit 1; }
grep -q 'after 12 attempts' "$tmp/out" || { echo 'FAIL: bounded message missing'; exit 1; }
grep -q 'SQLSTATE\[08006\] connection refused (attempt 12)' "$tmp/out" || { echo 'FAIL: last error missing'; exit 1; }

echo 'ENTRYPOINT_HARNESS_OK'
