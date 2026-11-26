Libraries from other projects

This directory is not named `vendor`: the packaging step leaves out any path
with a part of that name, and the repository ignores it, so a release would
reach a site without these files.

maplibre-gl 5.24.1 (BSD-3-Clause)
  Files: maplibre-gl.js, maplibre-gl.css
  License: maplibre-gl.LICENSE.txt

pmtiles 4.5.0 (BSD-3-Clause)
  File: pmtiles.js
  License: https://github.com/protomaps/PMTiles/blob/main/LICENSE

Both are copied unmodified from the npm packages.
