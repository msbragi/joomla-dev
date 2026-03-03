# nospace.net — Joomla Extensions

This repository contains the Joomla extensions developed for [nospace.net](https://www.nospace.net).

---

## Plugins

### plg_system_nsprism

A system plugin that integrates [Prism.js](https://prismjs.com/) into Joomla.

Features:
- Bundles Prism.js (no CDN dependency)
- Switchable syntax highlight themes (dark, default, coy, okaida, tomorrow, …)
- Config-driven Prism CSS classes: line numbers, match braces, rainbow braces, show language, diff highlight
- Layout stylesheet for toolbar styling and copy-to-clipboard feedback
- Optional TinyMCE language list patch for the backend editor
- Custom JSON field for the language map

**Location:** [`plugins/nsprism/`](plugins/nsprism/)

#### Requirements
- Joomla 4.x / 5.x / 6.x
- PHP 8.1+

#### Installation
1. Run `./build.sh` from `plugins/nsprism/` to produce the zip package.
2. Install through Joomla → **System → Install → Extensions**.

#### Build
```bash
cd plugins/nsprism
./build.sh           # creates plg_system_nsprism_vX.Y.Z.zip
./build.sh --copy    # also copies the zip to /mnt/c/Data/_Backups
```

---

## License

GNU General Public License version 2 or later.  
See [plugins/nsprism/LICENSE.txt](plugins/nsprism/LICENSE.txt).
