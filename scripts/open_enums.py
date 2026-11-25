"""Opens the generated models' response enums, so a value the API adds reads as served.

php-nextgen refuses a value outside the pinned spec while it deserializes an inline enum,
in its setter, and again in `listInvalidProperties()`, which the transport walks after
reading an answer. Either fails the whole call. This drops both checks for each enum named
below, and fails the generation when either changed shape.

Only the user agent's `verdict` is opened so far: an SDK that sends a user agent must not
fail a lookup on a verdict the API adds. `tier`, `standing`, `license_type`, `outcome` and
the formats still fail the call on a value outside the pinned spec.

    python3 scripts/open_enums.py src/Internal/Model
"""

import re
import sys

model = sys.argv[1]

# The inline enums whose setter and listInvalidProperties() refuse a value outside the
# pinned spec, by file.
OPEN = {"UserAgentVerdict.php": 1}

SETTER = re.compile(
    r"\n {8}\$allowedValues = self::get\w+AllowableValues\(\);\n"
    r" {8}if \([^\n]*in_array\([^\n]*\)\) \{\n"
    r" {12}throw new InvalidArgumentException\(\n(?: {16}[^\n]*\n)+? {12}\);\n {8}\}"
)
LISTED = re.compile(
    r"\n {8}\$allowedValues = self::get\w+AllowableValues\(\);\n"
    r" {8}if \([^\n]*in_array\([^\n]*\)\) \{\n"
    r" {12}\$invalidProperties\[\] = sprintf\(\n(?: {16}[^\n]*\n)+? {12}\);\n {8}\}"
)

opened = 0
for name, want in OPEN.items():
    path = f"{model}/{name}"
    text = open(path).read()
    for check, what in ((SETTER, "setter"), (LISTED, "listInvalidProperties")):
        text, n = check.subn("", text)
        if n != want:
            sys.exit(f"{path}: {n} {what} checks found, want {want}: the model changed shape")
    open(path, "w").write(text)
    opened += want
print(f"  {opened} response enum(s) opened")
