# AllMyPrinters Slicer — OrcaSlicer (CLI) + Slice-Schnittstelle der App AllMyPrinters.
# Bauen:   docker build -t allmyprinters-slicer .
# Starten: docker run -d -p 8080:8080 -v amp-slicer:/data --name amp-slicer allmyprinters-slicer
FROM ubuntu:24.04

ARG ORCA_VERSION=2.4.2
ARG ORCA_SHA256_AMD64=d12fb8c8eac1aecd2dfb6377acd48f994f8fa439ed5292fa532dd82880f029fd
ARG ORCA_SHA256_ARM64=e1a07275a25f176626c55a5df39e91bc4476d8c28ee4a3192ff758e29dd5c3ba
ENV DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8 LANG=C.UTF-8
# Optional näherer Paketspiegel beim Bauen (z. B. --build-arg APT_MIRROR=http://ftp.halifax.rwth-aachen.de/ubuntu)
ARG APT_MIRROR=

# Bibliotheken, gegen die OrcaSlicer gebaut ist (aus ldd des AppImage-Programms), dazu PHP und tini.
# Orca läuft ohne Bildschirm; GTK/WebKit werden nur geladen, nicht benutzt.
RUN if [ -n "$APT_MIRROR" ]; then sed -i "s|http://archive.ubuntu.com/ubuntu|$APT_MIRROR|g; s|http://security.ubuntu.com/ubuntu|$APT_MIRROR|g" /etc/apt/sources.list.d/ubuntu.sources; fi \
 && apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl tini fonts-dejavu-core squashfs-tools \
      php8.3-cli php8.3-zip php8.3-xml php8.3-mbstring php8.3-sqlite3 php8.3-gd \
      libatk1.0-0t64 libatk-bridge2.0-0t64 libatomic1 libatspi2.0-0t64 libblkid1 libbrotli1 libbsd0 libbz2-1.0 libcairo2 libcairo-gobject2 libcap2 libcom-err2 libdatrie1 libdbus-1-3 libdrm2 libdw1t64 libegl1 libelf1t64 libenchant-2-2 libepoxy0 libevdev2 libffi8 libfontconfig1 libfreetype6 libfribidi0 libgbm1 libgcrypt20 libgdk-pixbuf-2.0-0 libgl1 libglib2.0-0t64 libglu1-mesa libglvnd0 libglx0 libgpg-error0 libgraphite2-3 libgssapi-krb5-2 libgstreamer1.0-0 libgstreamer-gl1.0-0 libgstreamer-plugins-base1.0-0 libgtk-3-0t64 libgudev-1.0-0 libharfbuzz0b libharfbuzz-icu0 libhyphen0 libice6 libicu74 libidn2-0 libjavascriptcoregtk-4.1-0 libjpeg-turbo8 libk5crypto3 libkeyutils1 libkrb5-3 libkrb5support0 liblcms2-2 liblz4-1 libmanette-0.2-0 libmd0 libmount1 libnghttp2-14 libopengl0 liborc-0.4-0t64 libpango-1.0-0 libpangocairo-1.0-0 libpangoft2-1.0-0 libpcre2-8-0 libpixman-1-0 libpng16-16t64 libpsl5t64 libseccomp2 libsecret-1-0 libselinux1 libsharpyuv0 libsm6 libsoup-3.0-0 libsqlite3-0 libsystemd0 libtasn1-6 libthai0 libudev1 libunistring5 libunwind8 libuuid1 libwayland-client0 libwayland-cursor0 libwayland-egl1 libwayland-server0 libwebkit2gtk-4.1-0 libwebp7 libwebpdemux2 libwebpmux3 libx11-6 libx11-xcb1 libxau6 libxcb1 libxcb-render0 libxcb-shm0 libxcomposite1 libxcursor1 libxdamage1 libxdmcp6 libxext6 libxfixes3 libxi6 libxinerama1 libxkbcommon0 libxml2 libxrandr2 libxrender1 libxslt1.1 libzstd1 \
 && rm -rf /var/lib/apt/lists/*

# OrcaSlicer aus der offiziellen Veröffentlichung (Prüfsumme fest), entpackt nach /opt/orcaslicer/squashfs-root.
# Nicht per „--appimage-extract“: das AppImage trägt im ELF-Kopf eine Kennung (AI 02), an der die QEMU-Emulation beim
# ARM64-Bau auf GitHub scheitert (exit 126). Stattdessen das eingebettete squashfs ab dem Ende des ELF-Teils auspacken
# (Ende = Abschnittstabelle e_shoff + e_shentsize × e_shnum) — läuft für jede Architektur ohne das Programm zu starten.
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
      amd64) f="OrcaSlicer_Linux_AppImage_Ubuntu2404_V${ORCA_VERSION}.AppImage"; sum="$ORCA_SHA256_AMD64" ;; \
      arm64) f="OrcaSlicer_Linux_AppImage_Ubuntu2404_aarch64_V${ORCA_VERSION}.AppImage"; sum="$ORCA_SHA256_ARM64" ;; \
      *) echo "nicht unterstützt: $arch" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/orca.AppImage "https://github.com/OrcaSlicer/OrcaSlicer/releases/download/v${ORCA_VERSION}/$f"; \
    echo "$sum  /tmp/orca.AppImage" | sha256sum -c -; \
    shoff="$(od -An -t u8 -j 40 -N 8 /tmp/orca.AppImage | tr -d ' ')"; \
    shent="$(od -An -t u2 -j 58 -N 2 /tmp/orca.AppImage | tr -d ' ')"; \
    shnum="$(od -An -t u2 -j 60 -N 2 /tmp/orca.AppImage | tr -d ' ')"; \
    mkdir -p /opt/orcaslicer; \
    unsquashfs -q -n -d /opt/orcaslicer/squashfs-root -o "$((shoff + shent * shnum))" /tmp/orca.AppImage; \
    rm /tmp/orca.AppImage; \
    echo "$ORCA_VERSION" > /opt/orcaslicer/VERSION; \
    chmod -R a+rX /opt/orcaslicer; \
    missing="$(LD_LIBRARY_PATH=/opt/orcaslicer/squashfs-root/lib/orca-runtime:/opt/orcaslicer/squashfs-root/bin ldd /opt/orcaslicer/squashfs-root/bin/orca-slicer | grep 'not found' || true)"; \
    if [ -n "$missing" ]; then echo "$missing" >&2; exit 1; fi

COPY docker/php.ini /etc/php/8.3/cli/conf.d/99-allmyprinters.ini
COPY docker/entrypoint.sh /entrypoint.sh
COPY app/ /app/
COPY LICENSE /app/LICENSE
RUN useradd --uid 10001 --create-home --home-dir /home/slicer slicer \
 && mkdir -p /data && chown slicer:slicer /data && chmod 0750 /data

USER slicer
VOLUME /data
EXPOSE 8080
HEALTHCHECK --interval=60s --timeout=5s --start-period=20s \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . (getenv("AMP_PORT") ?: "8080") . "/?a=version") ? 0 : 1);'
ENTRYPOINT ["/usr/bin/tini", "-g", "--", "/entrypoint.sh"]
