#!/usr/bin/env bash
# Read-only: run on the target VPS; never dump environment files or container env.
set -euo pipefail
printf 'OS and architecture\n'
cat /etc/os-release
uname -m
printf '\nMemory and disk\n'
free -h
df -h / /var/lib/docker
printf '\nDocker version and published ports\n'
docker version --format '{{.Server.Version}}'
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
printf '\nListening TCP ports\n'
ss -lnt
printf '\nDocker subnets (check overlap with K3s 10.42.0.0/16 and 10.43.0.0/16)\n'
docker network ls -q | xargs -r docker network inspect --format '{{.Name}} {{range .IPAM.Config}}{{.Subnet}} {{end}}'
printf '\nRead deploy/README.md before installing K3s or changing any live service.\n'
