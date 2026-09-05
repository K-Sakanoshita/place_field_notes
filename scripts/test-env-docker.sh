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
json_value(){
  local path=$1
  compose exec -T web php -r '
    $data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $value=$data;
    foreach(explode(".",$argv[1]) as $part){
      if($part==="") continue;
      $value=is_array($value) && array_key_exists($part,$value) ? $value[$part] : null;
    }
    if(is_bool($value)) echo $value ? "1" : "0";
    elseif(is_scalar($value)) echo $value;
  ' "$path"
}

run_tests(){
  require
  wait_http
  local base="http://127.0.0.1:$port"
  local cookie_jar project_json public_id edit_url token patch_json editor_json place_id photo_json public_json unauthorized health
  cookie_jar=$(mktemp)
  trap 'rm -f "$cookie_jar"' EXIT

  health=$(curl -fsS "$base/api/health")
  [[ "$health" == *'"database":"mysql"'* ]]
  curl -fsS "$base/enhancements.js" >/dev/null
  curl -fsS "$base/enhancements.css" >/dev/null

  compose exec -T web sh -lc 'find /app -maxdepth 2 -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'

  project_json=$(curl -fsS -X POST "$base/api/projects" -H 'Content-Type: application/json' -d '{"title":"Wikipedia Town test","description":"smoke","activity_type":"wikipedia","bbox":[135.49,34.68,135.50,34.69],"start_at":"2026-09-01T10:00","end_at":"2026-09-01T12:00","timezone":"Asia/Tokyo","base_map":"2026-01-01","entries":[],"place_results":[]}')
  public_id=$(printf '%s' "$project_json" | json_value public_id)
  edit_url=$(printf '%s' "$project_json" | json_value edit_url)
  [[ -n "$public_id" && -n "$edit_url" ]]
  token=${edit_url##*token=}
  [[ -n "$token" ]]

  curl -fsS -c "$cookie_jar" -X POST "$base/api/projects/$public_id/edit-session" -H 'Content-Type: application/json' -d "{\"token\":\"$token\"}" >/dev/null
  editor_json=$(curl -fsS -b "$cookie_jar" "$base/api/projects/$public_id?editor=1")
  [[ "$(printf '%s' "$editor_json" | json_value title)" == 'Wikipedia Town test' ]]

  patch_json=$(curl -fsS -b "$cookie_jar" -X PATCH "$base/api/projects/$public_id" -H 'Content-Type: application/json' -d '{"title":"Updated project","description":"edited","activity_type":"wikipedia","featured_objects":[],"entries":[{"body":"Field note"}],"place_results":[{"title":"Test place","lat":34.685,"lon":135.495,"comment":"Place comment","links":[{"source_type":"wikidata","source_key":"Q123","source_url":"https://www.wikidata.org/wiki/Q123"}]}]}')
  [[ "$patch_json" == *'"status":"ok"'* ]]

  editor_json=$(curl -fsS -b "$cookie_jar" "$base/api/projects/$public_id?editor=1")
  place_id=$(printf '%s' "$editor_json" | json_value place_results.0.id)
  [[ -n "$place_id" ]]

  photo_json=$(curl -fsS -b "$cookie_jar" -X POST "$base/api/projects/$public_id/photos" \
    -F 'source_type=commons' \
    -F 'commons_file=File:Example.jpg' \
    -F 'caption=Commons test' \
    -F 'creator=Example' \
    -F 'credit=Wikimedia Commons' \
    -F 'license=CC BY 4.0' \
    -F "place_result_id=$place_id")
  [[ "$photo_json" == *'"id"'* ]]

  public_json=$(curl -fsS "$base/api/projects/$public_id")
  [[ "$(printf '%s' "$public_json" | json_value title)" == 'Updated project' ]]
  [[ "$(printf '%s' "$public_json" | json_value place_results.0.title)" == 'Test place' ]]
  [[ "$(printf '%s' "$public_json" | json_value photos.0.source_type)" == 'commons' ]]

  unauthorized=$(curl -sS -o /dev/null -w '%{http_code}' -X PATCH "$base/api/projects/$public_id" -H 'Content-Type: application/json' -d '{"title":"Blocked","activity_type":"wikipedia","featured_objects":[],"entries":[],"place_results":[]}')
  [[ "$unauthorized" == '401' ]]

  compose exec -T db mysql -uplace_field_notes -ppfn-local place_field_notes -Nse \
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ("projects","diffs","edit_sessions","featured_objects","entries","place_results","result_links","photos")' \
    | grep -qx '8'

  echo 'API + MySQL + edit session + photo smoke tests: ok'
}

case ${1:-help} in
  start) require; compose up -d --build --wait; wait_http; echo "http://127.0.0.1:$port/" ;;
  test) run_tests ;;
  status) require; compose ps ;;
  logs) require; compose logs -f --tail=100 web db ;;
  stop) require; compose down --remove-orphans ;;
  reset) [[ ${2:-} == --yes ]] || { echo 'Use: reset --yes' >&2; exit 2; }; require; compose down -v --remove-orphans; compose up -d --build --wait ;;
  *) echo 'Usage: ./scripts/test-env-docker.sh {start|test|status|logs|stop|reset --yes}' ;;
esac
