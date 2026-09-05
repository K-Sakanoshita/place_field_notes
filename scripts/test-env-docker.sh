#!/usr/bin/env bash
set -euo pipefail

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH= cd -- "$script_dir/.." && pwd)
compose_file="$project_root/docker/local/compose.yml"
compose_project=${PFN_TEST_PROJECT_NAME:-place-field-notes-test}
web_port=${PFN_TEST_WEB_PORT:-8082}

export PFN_TEST_WEB_PORT="$web_port"

compose() {
    docker compose --project-name "$compose_project" --file "$compose_file" "$@"
}

usage() {
    cat <<'EOF'
Usage: ./scripts/test-env-docker.sh COMMAND

Commands:
  start          Build and start the test DB and Web server
  restart        Restart the Web server while preserving test DB data
  status         Show container, HTTP, and test DB status
  test           Verify the Web API and test DB schema
  logs           Follow Web server logs
  stop           Stop the Web server; test DB data is preserved
  reset --yes    Delete and recreate the test DB
  help           Show this help

Optional environment variables:
  PFN_TEST_WEB_PORT       Host Web port (default: 8082)
  PFN_TEST_PROJECT_NAME   Docker Compose project name
EOF
}

require_tools() {
    command -v docker >/dev/null 2>&1 || { echo 'docker is required.' >&2; exit 1; }
    docker compose version >/dev/null 2>&1 || { echo 'Docker Compose v2 is required.' >&2; exit 1; }
    command -v curl >/dev/null 2>&1 || { echo 'curl is required.' >&2; exit 1; }
}

wait_for_http() {
    local url="http://127.0.0.1:$web_port/api/health"
    local attempt
    for attempt in $(seq 1 30); do
        if curl --fail --silent --show-error "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Web readiness check failed: $url" >&2
    compose logs --tail=80 web >&2
    return 1
}

print_access() {
    cat <<EOF
Web API:  http://127.0.0.1:$web_port/
Health:   http://127.0.0.1:$web_port/api/health
Test DB:  Docker volume ${compose_project}_test-database
EOF
}

start_stack() {
    require_tools
    compose up --detach --wait
    wait_for_http
    echo 'Local test DB and Web server are ready.'
    print_access
}

test_stack() {
    require_tools
    local health
    health=$(curl --fail --silent --show-error "http://127.0.0.1:$web_port/api/health")
    if [[ "$health" != '{"status":"ok"}' ]]; then
        echo "Unexpected health response: $health" >&2
        exit 1
    fi

    compose exec --no-TTY web php -r '
        $pdo = new PDO("sqlite:" . getenv("PLACE_FIELD_NOTES_DB_PATH"));
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = \"table\" AND name IN (\"projects\", \"diffs\") ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        exit($tables === ["diffs", "projects"] ? 0 : 1);
    '
    echo 'Web API and SQLite test DB schema: ok'
}

command_name=${1:-help}
case "$command_name" in
    start)
        start_stack
        ;;
    restart)
        require_tools
        compose down --remove-orphans
        start_stack
        ;;
    status)
        require_tools
        compose ps
        if curl --fail --silent --show-error "http://127.0.0.1:$web_port/api/health" >/dev/null 2>&1; then
            echo "HTTP: healthy (http://127.0.0.1:$web_port/)"
        else
            echo 'HTTP: unavailable'
        fi
        if compose exec --no-TTY web test -f /data/place_field_notes_test.sqlite >/dev/null 2>&1; then
            echo 'Test DB: initialized'
        else
            echo 'Test DB: unavailable'
        fi
        ;;
    test)
        test_stack
        ;;
    logs)
        require_tools
        compose logs --follow --tail=100 web
        ;;
    stop)
        require_tools
        compose down --remove-orphans
        echo 'Local Web server stopped. Test DB data was preserved.'
        ;;
    reset)
        if [[ ${2:-} != '--yes' ]]; then
            echo 'reset deletes the local test DB. Run: ./scripts/test-env-docker.sh reset --yes' >&2
            exit 2
        fi
        require_tools
        compose down --volumes --remove-orphans
        start_stack
        ;;
    help|-h|--help)
        usage
        ;;
    *)
        echo "Unknown command: $command_name" >&2
        usage >&2
        exit 2
        ;;
esac
