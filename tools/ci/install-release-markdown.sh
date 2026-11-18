#!/usr/bin/env bash

set -euo pipefail

unset CDPATH

if [[ $# -ne 1 || -z "${1:-}" ]]; then
    echo 'Usage: bash tools/ci/install-release-markdown.sh INSTALL_DIR' >&2
    exit 2
fi

for command in curl shasum tar cmake install; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Installing cmark-gfm requires $command." >&2
        exit 1
    fi
done

install_dir="$1"
version='0.29.0.gfm.13'
source_commit='587a12bb54d95ac37241377e6ddc93ea0e45439b'
source_sha256='9214b42f13a471fc2984e9806c37bf0f42a13027854ab19103bd6d8085eb3416'

temporary_root="${TMPDIR:-/tmp}"
if [[ "$temporary_root" != /* ]]; then
    temporary_root="./$temporary_root"
fi
temporary_root="$(cd -- "$temporary_root" && pwd -P)"
export TMPDIR="$temporary_root"
scratch="$(mktemp -d "$temporary_root/slug-release-markdown.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT

curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    --retry 2 --max-time 60 --output "$scratch/source.tar.gz" \
    "https://codeload.github.com/github/cmark-gfm/tar.gz/$source_commit"
(
    cd "$scratch"
    printf '%s  source.tar.gz\n' "$source_sha256" | shasum -a 256 -c -
    tar -xzf source.tar.gz
)

CMAKE_POLICY_VERSION_MINIMUM=3.5 cmake \
    -S "$scratch/cmark-gfm-$source_commit" \
    -B "$scratch/build" \
    -DCMAKE_BUILD_TYPE=Release \
    -DCMARK_TESTS=OFF \
    -DCMARK_STATIC=ON \
    -DCMARK_SHARED=OFF
cmake --build "$scratch/build" --target cmark-gfm --parallel 2

binary="$scratch/build/src/cmark-gfm"
built_version="$("$binary" --version)"
if [[ "$built_version" != "cmark-gfm $version - "* ]]; then
    printf 'Unexpected cmark-gfm version: %s\n' "$built_version" >&2
    exit 1
fi

install -d "$install_dir/bin"
install -m 0755 "$binary" "$install_dir/bin/cmark-gfm"
printf '%s\n' "$built_version"
