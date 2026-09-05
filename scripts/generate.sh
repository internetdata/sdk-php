#!/bin/bash

# Regenerates the wire layer from the PINNED spec in spec/openapi.yaml.
#
# The generator runs in its official container, so the box needs no PHP codegen
# toolchain, and it reads the committed spec rather than a URL, so the build is
# reproducible and offline. Refresh the spec with scripts/download-spec.sh, run
# this, and commit both together so a reviewer sees which spec produced which
# client.
#
# The output is COMMITTED. Composer installs a library from source with no build
# step, so a gitignored tree would ship a package that cannot autoload itself.

set -euo pipefail

cd "$(dirname "$0")/.."

# `latest` is a pre-release snapshot, so pin the newest release tag instead.
GENERATOR_IMAGE="${GENERATOR_IMAGE:-openapitools/openapi-generator-cli:v7.25.0}"

# The spec still carries /api/v1, which is a different credential vocabulary
# (`?apikey=<uuid>`) serving a fixed set of customers who cannot be moved. This
# library targets v2 alone, and the selection below makes that structural rather
# than a convention: v1's API class and its eight models are never WRITTEN, so
# there is nothing to reach for in the internal namespace and nothing left
# behind stale by a later spec that drops them.
#
# The model list is keyed by the name the generator settles on, so the three
# inline wrappers appear here under their MAPPED names rather than the
# `listDatabases_200_response` placeholders the mapping is keyed by. Spelled the
# other way they are silently skipped and the API class references classes that
# were never emitted.
SELECT="apis=DatabaseV2"
SELECT="${SELECT},models=Database:DatabaseVersion:DatabaseMetadata:DatabaseMetadataColumn"
SELECT="${SELECT}:DbChecksums:Download:Error:DatabaseList:DownloadList:ChecksumsResponse"
SELECT="${SELECT},supportingFiles"

# Two escaping traps in one line. apiPackage and modelPackage are RELATIVE to
# invokerPackage, so spelling them out in full produces
# InternetData\Internal\InternetData\Internal\Model\... in every signature. And the
# generator takes the namespace VERBATIM, so a backslash that survives the shell
# doubled emits `namespace InternetData\\Internal;`, which does not parse at all.
PROPS="composerPackageName=internetdata/internetdata"
PROPS="${PROPS},invokerPackage=InternetData\\Internal"
PROPS="${PROPS},apiPackage=Api,modelPackage=Model"
PROPS="${PROPS},artifactVersion=1.0.0,licenseName=MIT"

# The three wrapper schemas are inline in the spec, so the generator names them
# after the operation and status code (DatabaseChecksumV2200Response).
# --model-name-mappings does NOT reach an inline schema; only
# --inline-schema-name-mappings does, keyed by the generator's own placeholder.
NAMES="listDatabases_200_response=DatabaseList"
NAMES="${NAMES},listDownloads_200_response=DownloadList"
NAMES="${NAMES},databaseChecksumV2_200_response=ChecksumsResponse"

rm -rf .gen
mkdir -p .gen

docker run --rm \
    -v "$PWD/spec:/spec:ro" \
    -v "$PWD/.gen:/out" \
    "$GENERATOR_IMAGE" generate \
    -i /spec/openapi.yaml \
    -g php-nextgen \
    -o /out \
    --global-property "$SELECT" \
    --inline-schema-name-mappings "$NAMES" \
    --additional-properties="$PROPS" \
    >/dev/null

# Only src/ is taken. The generator also emits its own composer.json, README.md,
# .gitignore, phpunit.xml.dist, .travis.yml and a git_push.sh, every one of which
# would overwrite ours if the output landed on the repo directly. Its composer.json
# in particular declares the Unlicense and credits the generator's authors.
rm -rf src/Internal
mkdir -p src/Internal
cp -R .gen/src/. src/Internal/

rm -rf .gen
echo "regenerated src/Internal from spec/openapi.yaml"
grep -m1 '^  version:' spec/openapi.yaml
