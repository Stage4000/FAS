set -euo pipefail
# No namespace/browser fallback. Both variants must complete.
for variant in baseline candidate; do
  python3 tests/zoom-browser/run.py --variant "$variant" --assets "$1/fas-zoom-assets" --output "$1/fas-zoom-output/$variant"
done
