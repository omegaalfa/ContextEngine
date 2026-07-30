#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.yml"
readonly PROFILE="integration"

if [[ -t 1 ]]; then
    readonly RESET='\033[0m'
    readonly BOLD='\033[1m'
    readonly DIM='\033[2m'
    readonly CYAN='\033[36m'
    readonly GREEN='\033[32m'
    readonly YELLOW='\033[33m'
    readonly RED='\033[31m'
else
    readonly RESET='' BOLD='' DIM='' CYAN='' GREEN='' YELLOW='' RED=''
fi

usage() {
    cat <<'HELP'
ContextEngine — controle dos serviços Docker

Uso:
  ./context-engine.sh <comando> [serviço...]

Comandos:
  up [serviço...]       Cria/inicia os containers e aguarda os healthchecks
  start [serviço...]    Inicia containers que já existem
  stop [serviço...]     Para containers sem removê-los
  restart [serviço...]  Para e recria os serviços, preservando os volumes
  down                  Remove containers e rede, preservando os volumes
  status                Mostra estado e saúde dos containers
  logs [serviço...]     Acompanha os logs (Ctrl+C para sair)
  pull [serviço...]     Baixa as imagens fixadas no docker-compose.yml
  config                Valida e exibe a configuração Compose resolvida
  help                  Mostra esta ajuda

Serviços disponíveis:
  pgvector
  redis

Variáveis opcionais do script:
  CONTEXT_ENGINE_ENV_FILE=/caminho/arquivo.env
  CONTEXT_ENGINE_LOG_TAIL=200

Por padrão, o script usa .env. Se ele não existir, usa .env.example sem criar
ou modificar arquivos. O comando down NÃO remove os volumes persistentes.

Exemplos:
  ./context-engine.sh up
  ./context-engine.sh up pgvector
  ./context-engine.sh restart redis
  ./context-engine.sh logs pgvector
  ./context-engine.sh status
  ./context-engine.sh down
HELP
}

fail() {
    printf 'Erro: %s\n' "$*" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail 'Docker não foi encontrado no PATH.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 não está disponível.'
[[ -f "${COMPOSE_FILE}" ]] || fail "Arquivo não encontrado: ${COMPOSE_FILE}"

if [[ -n "${CONTEXT_ENGINE_ENV_FILE:-}" ]]; then
    ENV_FILE="${CONTEXT_ENGINE_ENV_FILE}"
elif [[ -f "${SCRIPT_DIR}/.env" ]]; then
    ENV_FILE="${SCRIPT_DIR}/.env"
else
    ENV_FILE="${SCRIPT_DIR}/.env.example"
    printf 'Aviso: .env não encontrado; usando .env.example.\n' >&2
fi

[[ -f "${ENV_FILE}" ]] || fail "Arquivo de ambiente não encontrado: ${ENV_FILE}"

compose=(
    docker compose
    --file "${COMPOSE_FILE}"
    --env-file "${ENV_FILE}"
    --profile "${PROFILE}"
)

validate_services() {
    local service
    for service in "$@"; do
        case "${service}" in
            pgvector|redis) ;;
            *) fail "Serviço desconhecido: ${service}. Use pgvector ou redis." ;;
        esac
    done
}

run_action() {
    local command_name="${1:-help}"
    if (($# > 0)); then
        shift
    fi

    case "${command_name}" in
    up)
        validate_services "$@"
        "${compose[@]}" up --detach --wait "$@"
        "${compose[@]}" ps
        ;;
    start)
        validate_services "$@"
        "${compose[@]}" start "$@"
        "${compose[@]}" ps
        ;;
    stop)
        validate_services "$@"
        "${compose[@]}" stop "$@"
        "${compose[@]}" ps
        ;;
    restart)
        validate_services "$@"
        "${compose[@]}" stop "$@"
        "${compose[@]}" up --detach --wait --force-recreate "$@"
        "${compose[@]}" ps
        ;;
    down)
        (($# == 0)) || fail 'O comando down não recebe serviços individuais.'
        "${compose[@]}" down
        ;;
    status|ps)
        (($# == 0)) || fail 'O comando status não recebe argumentos.'
        "${compose[@]}" ps --all
        ;;
    logs)
        validate_services "$@"
        "${compose[@]}" logs --follow --tail "${CONTEXT_ENGINE_LOG_TAIL:-200}" "$@"
        ;;
    pull)
        validate_services "$@"
        "${compose[@]}" pull "$@"
        ;;
    config)
        (($# == 0)) || fail 'O comando config não recebe argumentos.'
        "${compose[@]}" config
        ;;
    help|-h|--help)
        usage
        ;;
    *)
        usage >&2
        fail "Comando desconhecido: ${command_name}"
        ;;
    esac
}

pause_menu() {
    printf '\n%bPressione ENTER para voltar ao menu...%b' "${DIM}" "${RESET}"
    read -r _ || true
}

show_banner() {
    if [[ -t 1 ]] && command -v clear >/dev/null 2>&1; then
        clear
    fi
    printf '%b' "${CYAN}${BOLD}"
    cat <<'BANNER'
╔══════════════════════════════════════════════════════════════╗
║                  Ω  CONTEXT ENGINE                          ║
║               Central de serviços Docker                   ║
╚══════════════════════════════════════════════════════════════╝
BANNER
    printf '%b\n' "${RESET}"
    printf '  %bAmbiente:%b %s\n' "${DIM}" "${RESET}" "${ENV_FILE}"
    printf '  %bServiços:%b pgvector + redis\n\n' "${DIM}" "${RESET}"
}

show_menu() {
    printf '%bSERVIÇOS%b\n' "${BOLD}" "${RESET}"
    printf '  %b1)%b  ▲ Subir todos os serviços\n' "${GREEN}" "${RESET}"
    printf '  %b2)%b  ▲ Subir somente pgvector\n' "${GREEN}" "${RESET}"
    printf '  %b3)%b  ▲ Subir somente Redis\n' "${GREEN}" "${RESET}"
    printf '  %b4)%b  ■ Parar todos os serviços\n' "${YELLOW}" "${RESET}"
    printf '  %b5)%b  ↻ Reiniciar todos os serviços\n' "${YELLOW}" "${RESET}"
    printf '  %b6)%b  ▼ Remover containers (preserva dados)\n\n' "${RED}" "${RESET}"

    printf '%bMONITORAMENTO%b\n' "${BOLD}" "${RESET}"
    printf '  %b7)%b  ● Ver status e saúde\n' "${CYAN}" "${RESET}"
    printf '  %b8)%b  ≡ Acompanhar logs\n' "${CYAN}" "${RESET}"
    printf '  %b9)%b  ✓ Validar configuração\n\n' "${CYAN}" "${RESET}"

    printf '%bOUTROS%b\n' "${BOLD}" "${RESET}"
    printf '  %b10)%b ↓ Baixar/atualizar imagens fixadas\n' "${CYAN}" "${RESET}"
    printf '  %b0)%b  × Sair\n' "${DIM}" "${RESET}"
}

choose_logs() {
    printf '\n%bQual log deseja acompanhar?%b\n' "${BOLD}" "${RESET}"
    printf '  1) Todos\n  2) pgvector\n  3) Redis\n  0) Voltar\n\n'
    read -r -p 'Escolha: ' log_choice
    case "${log_choice}" in
        1) run_action logs ;;
        2) run_action logs pgvector ;;
        3) run_action logs redis ;;
        0) return ;;
        *) printf '%bOpção inválida.%b\n' "${RED}" "${RESET}" ;;
    esac
}

interactive_menu() {
    # No modo interativo, falhas do Docker voltam ao menu em vez de fechar o painel.
    set +e
    while true; do
        show_banner
        show_menu
        printf '\n'
        read -r -p 'Escolha uma opção: ' choice || exit 0
        printf '\n'

        case "${choice}" in
            1) run_action up; pause_menu ;;
            2) run_action up pgvector; pause_menu ;;
            3) run_action up redis; pause_menu ;;
            4) run_action stop; pause_menu ;;
            5) run_action restart; pause_menu ;;
            6)
                printf '%bOs containers e a rede serão removidos; os volumes serão preservados.%b\n' "${YELLOW}" "${RESET}"
                read -r -p 'Continuar? [s/N]: ' confirmation
                if [[ "${confirmation,,}" == 's' || "${confirmation,,}" == 'sim' ]]; then
                    run_action down
                else
                    printf 'Operação cancelada.\n'
                fi
                pause_menu
                ;;
            7) run_action status; pause_menu ;;
            8)
                printf '%bUse Ctrl+C para encerrar os logs.%b\n' "${DIM}" "${RESET}"
                choose_logs || true
                pause_menu
                ;;
            9) run_action config; pause_menu ;;
            10) run_action pull; pause_menu ;;
            0|q|quit|exit)
                printf '%bAté a próxima!%b\n' "${CYAN}" "${RESET}"
                exit 0
                ;;
            *)
                printf '%bOpção inválida. Escolha um número do menu.%b\n' "${RED}" "${RESET}"
                pause_menu
                ;;
        esac
    done
}

if (($# == 0)); then
    interactive_menu
else
    run_action "$@"
fi
