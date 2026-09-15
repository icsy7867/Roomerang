podman rm -f roomerang
podman run -itd -p 8080:8080 -e ADMIN_PASSWORD="abcdefg12345" --name roomerang roomerang:latest
