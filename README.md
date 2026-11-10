# AllMyPrinters Slicer

Self-hosted slicing for the **AllMyPrinters** Android app: OrcaSlicer (command line) plus the small HTTP interface the app talks to,
in one Docker container. Run it at home and your models never leave your network. No internet connection is needed for slicing.

The app slices on our server by default. With this container you can switch to your own:
**AllMyPrinters → More → Settings → Slicer → Own server → `http://<IP of your computer>:8080` → Connect.**

## Quick start

```sh
docker run -d --name allmyprinters-slicer --restart unless-stopped \
  -p 8080:8080 -v allmyprinters-slicer:/data \
  ghcr.io/nolimitek/allmyprinters-slicer:latest
```

or with Docker Compose (see [`compose.example.yml`](compose.example.yml)):

```sh
docker compose -f compose.example.yml up -d
```

Check that it runs: open `http://<IP>:8080/?a=version` in a browser. You should see `"name":"allmyprinters-slicer"`.

**Runs on** x86-64 (PC, NAS, mini PC) and 64-bit ARM (Raspberry Pi 4/5 with a 64-bit OS). 4 GB RAM or more recommended;
big multi-color models need more.

## What's inside

- **OrcaSlicer 2.4.2** (official Linux AppImage, checksum-verified at build time) with all bundled printer, process and filament profiles.
- The same slicing pipeline the app uses on our server: profile selection per printer, multi-color slot mapping, multi-plate 3MF,
  supports, expert settings, pause at height, G-code preview.
- No account, no telemetry. The container never connects anywhere on its own.

## Settings (environment variables, all optional)

| Variable | Default | Meaning |
|---|---|---|
| `AMP_PORT` | `8080` | Port inside the container |
| `AMP_QUEUE_MAX` | `8` | Jobs waiting or slicing at the same time (one per phone) |
| `AMP_LIMITS` | off | `1` = daily limits per phone (40) and per address (80) — only needed for public servers |
| `AMP_LOG` | off | `1` = request log in `/data/log/slice.log`, kept 30 days |
| `AMP_HTTP_WORKERS` | `4` | Parallel HTTP processes |
| `AMP_TRUST_PROXY` | off | `1` = take the client address from `X-Real-IP` (only behind your own reverse proxy) |

Jobs (model, G-code, preview) live in `/data/jobs` and are deleted after 24 hours.

## Security

- The interface has **no password**. Anyone who can reach the port can slice. Keep it in your home network;
  don't forward the port to the internet. For remote access, put it behind a VPN or a reverse proxy with authentication.
- The container runs as an unprivileged user. The example compose file also makes the filesystem read-only and drops all capabilities.

## Updating

```sh
docker pull ghcr.io/nolimitek/allmyprinters-slicer:latest
docker rm -f allmyprinters-slicer   # then run the start command again
```

If the app says the slicer is too old, update the image.

## Building it yourself

```sh
git clone https://github.com/nolimitek/allmyprinters-slicer
cd allmyprinters-slicer
docker build -t allmyprinters-slicer .
```

`app/slice_engine.php` is generated from the slicing code of [3dsearch.net](https://3dsearch.net) (`tools/sync_engine.php`) —
please report changes as issues rather than editing that file.

## License

The code in this repository is licensed under the **GNU Affero General Public License v3.0** ([`LICENSE`](LICENSE)), like OrcaSlicer.
If you run a modified version as a network service, you must offer its source code to its users.

OrcaSlicer is licensed under the GNU AGPL v3.0 as well; the source code of the bundled version is at
<https://github.com/OrcaSlicer/OrcaSlicer/tree/v2.4.2>.
