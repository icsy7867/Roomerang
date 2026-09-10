IMAGE ?= roomerang:local
CONTAINER ?= roomerang

.PHONY: build run healthcheck

# Podman's OCI image format does not retain Dockerfile HEALTHCHECK metadata.
build:
	podman build --format docker -t $(IMAGE) .

run:
	podman run --rm -p 8080:8080 -v paste-chat-data:/app/data $(IMAGE)

healthcheck:
	podman healthcheck run $(CONTAINER)
