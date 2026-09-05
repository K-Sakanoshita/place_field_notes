#!/usr/bin/env bash
set -euo pipefail
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
root=$(CDPATH= cd -- "$script_dir/.." && pwd)
compose_file="$root/docker/local/compose.yml"
project=${PFN_TEST_PROJECT_NAME:-place-field-notes-test}
port=${PFN_TEST_WEB_PORT:-8082}
export PFN_TEST_WEB_PORT="$port"
compose(){ docker compose --project-name "$project" --file "$compose_file" "$@"; }
require(){ command -v docker >/dev/null; docker compose version >/dev/null; command -v curl >/dev/null; }
wait_http(){ for _ in $(seq 1 45); do curl -fsS "http://127.0.0.1:$port/api/health" >/dev/null 2>&1 && return 0; sleep 1; done; compose logs --tail=100 web; return 1; }
case ${1:-help} in
  start) require; compose up -d --build --wait; wait_http; echo "http://127.0.0.1:$port/" ;;
  test)
    require; wait_http
    health=$(curl -fsS "http://127.0.0.1:$port/api/health")
    [[ "$health" == *'"database":"mysql"'* ]]
    project_json=$(curl -fsS -X POST "http://127.0.0.1:$port/api/projects" -H 'Content-Type: application/json' -d '{"title":"Wikipedia Town test","activity_type":"wikipedia","bbox":[135.49,34.68,135.50,34.69],"start_at":"2026-09-01T10:00","end_at":"2026-09-01T12:00","timezone":"Asia/Tokyo","base_map":"2026-01-01","entries":[],"place_results":[]}')
    [[ "$project_json" == *'"public_id"'* && "$project_json" == *'"edit_url"'* ]]
    compose exec -T db mysql -uplace_field_notes -ppfn-local place_field_notes -Nse 'SELECT COUNT(*) FROM projects' | grep -Eq '^[1-9][0-9]*$'
    echo 'API + MySQL smoke test: ok'
    ;;
  status) require; compose ps ;;
  logs) require; compose logs -f --tail=100 web db ;;
  stop) require; compose down --remove-orphans ;;
  reset) [[ ${2:-} == --yes ]] || { echo 'Use: reset --yes' >&2; exit 2; }; require; compose down -v --remove-orphans; compose up -d --build --wait ;;
  *) echo 'Usage: ./scripts/test-env-docker.sh {start|test|status|logs|stop|reset --yes}' ;;
esac
