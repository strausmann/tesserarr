# Stage 1 — web interface. Node 22 and pnpm, deliberately not Bun.
# Teil A ships no web interface yet; the stage exists so Teil B only has to fill
# it, and so the build works today with an empty output directory.
FROM node:22-bookworm-slim AS web
WORKDIR /src/web
RUN corepack enable
COPY web/ ./
RUN if [ -f package.json ]; then pnpm install --frozen-lockfile && pnpm run build; else mkdir -p out; fi

# Stage 2 — Go binary with the web output embedded.
# The toolchain must match or exceed go.mod's `go 1.26.0` / `toolchain
# go1.26.6` line — the official golang images set GOTOOLCHAIN=local, so an
# older image cannot auto-fetch a newer toolchain the way `go` on a
# developer machine would.
FROM golang:1.26-bookworm AS build
WORKDIR /src
COPY go.mod go.sum* ./
RUN go mod download
COPY . .
COPY --from=web /src/web/out ./web/out

ARG VERSION=dev
ARG REVISION=unknown
# CGO_ENABLED=0 is a hard requirement: it is why the SQLite driver is pure Go.
RUN CGO_ENABLED=0 GOOS=linux go build \
    -trimpath \
    -ldflags="-s -w -X main.version=${VERSION}" \
    -o /out/tesserarr ./cmd/tesserarr

# Stage 3 — runtime. Slim Debian rather than scratch, because the entrypoint
# needs a shell for the secret service CLI.
FROM debian:bookworm-slim AS runtime

ARG INFISICAL_CLI_VERSION=0.41.90
ARG VERSION=dev
ARG REVISION=unknown
ARG CREATED=unknown

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && curl -fsSL "https://github.com/Infisical/infisical/releases/download/infisical-cli%2Fv${INFISICAL_CLI_VERSION}/infisical_${INFISICAL_CLI_VERSION}_linux_amd64.tar.gz" \
       | tar -xz -C /usr/local/bin infisical \
    && apt-get purge -y curl \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /out/tesserarr /usr/local/bin/tesserarr
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN useradd --system --uid 1000 --user-group tesserarr \
    && mkdir -p /data \
    && chown tesserarr:tesserarr /data
USER 1000:1000

EXPOSE 8080

LABEL org.opencontainers.image.title="tesserarr" \
      org.opencontainers.image.description="Share parts of your media library with friends over Syncthing" \
      org.opencontainers.image.source="https://github.com/example/tesserarr" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.created="${CREATED}"

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["serve"]
