#!/usr/bin/env bash
# Push dmc/ and zap/ from dashboards monorepo to separate GitHub repos.
# Prerequisites: empty public repos must exist:
#   https://github.com/ZAPThomasIkemann/dmc
#   https://github.com/ZAPThomasIkemann/zap

set -euo pipefail
cd "$(dirname "$0")"

OWNER="${GITHUB_OWNER:-ZAPThomasIkemann}"
DMC_REPO="${DMC_REPO:-${OWNER}/dmc}"
ZAP_REPO="${ZAP_REPO:-${OWNER}/zap}"

if ! git rev-parse --verify split-dmc >/dev/null 2>&1; then
  echo "Creating split-dmc branch..."
  git subtree split --prefix=dmc -b split-dmc
fi
if ! git rev-parse --verify split-zap >/dev/null 2>&1; then
  echo "Creating split-zap branch..."
  git subtree split --prefix=zap -b split-zap
fi

TOKEN=""
if command -v gh >/dev/null 2>&1; then
  TOKEN="$(gh auth token 2>/dev/null || true)"
fi
if [[ -z "${TOKEN}" ]]; then
  ORIGIN_URL="$(git remote get-url origin 2>/dev/null || true)"
  TOKEN="$(echo "${ORIGIN_URL}" | sed -n 's|https://x-access-token:\([^@]*\)@.*|\1|p')"
fi
if [[ -z "${TOKEN}" ]]; then
  echo "Error: no GitHub token (gh auth token or dashboards origin remote)." >&2
  exit 1
fi

push_branch() {
  local repo="$1"
  local branch="$2"
  local url="https://x-access-token:${TOKEN}@github.com/${repo}.git"
  echo "Pushing ${branch} -> ${repo} (main)..."
  git push "${url}" "${branch}:main"
}

for repo in "${DMC_REPO}" "${ZAP_REPO}"; do
  if ! curl -fsS -H "Authorization: token ${TOKEN}" "https://api.github.com/repos/${repo}" >/dev/null; then
    echo "Error: repository https://github.com/${repo} not found or not accessible." >&2
    echo "Create an empty public repo first (no README/license/.gitignore)." >&2
    exit 1
  fi
done

push_branch "${DMC_REPO}" split-dmc
push_branch "${ZAP_REPO}" split-zap
echo "Done. dmc and zap are on GitHub."
