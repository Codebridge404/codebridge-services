# CodeBridge infrastructure foundation

This folder contains the initial host preparation playbook. The additive Docker/K3s application deployment now lives in [`deploy/`](../deploy/README.md); start there for the VPS runbook. Existing Compose services and volumes are not automatically changed.

## Existing project

- `docker-compose.yml` includes the gateway, Auth, Operations, and storage Compose files.
- `gateway/` routes requests; `auth-service/` and `operations-service/` are Laravel services.
- `storage/` defines MinIO. Each Laravel service has its own Dockerfile and Compose configuration.

## What goes where

- `ansible/`: configure an existing VPS over SSH. The example installs basic packages and creates an application directory; Docker is assumed to be installed.
- Terraform is optional. Add `terraform/` after deciding whether to provision a new VPS or import an existing one and reviewing the Contabo provider.
- [`../deploy/`](../deploy/README.md): production image definitions, a K3s configuration, manifest renderer, staged deployment commands, migration jobs, and a rollback runbook. It preserves the gateway routes and keeps background processing paused until cutover. PHP-FPM has an Nginx sidecar for HTTP readiness checks.

## Try Ansible

Install Ansible on your own computer. Copy the inventory example outside the repository, replace the address and user, and verify your SSH key and sudo access. Keep real inventory and secrets out of Git.

```bash
cp infrastructure/ansible/inventory.example.ini ~/codebridge-inventory.ini
# Edit ~/codebridge-inventory.ini
ansible -i ~/codebridge-inventory.ini codebridge -m ping
ansible-playbook -i ~/codebridge-inventory.ini infrastructure/ansible/setup.yml --syntax-check
ansible-playbook -i ~/codebridge-inventory.ini infrastructure/ansible/setup.yml --check --diff
ansible-playbook -i ~/codebridge-inventory.ini infrastructure/ansible/setup.yml
```

If sudo requires a password, add `--ask-become-pass` to the playbook command. The playbook changes packages and creates a root-owned directory; it does not create users, install Docker, configure a firewall, copy code, run migrations, or start containers.

## Before using the current Compose stack on a VPS

The Compose files are currently development-oriented. Auth and Operations MySQL and Redis publish host ports, and MinIO publishes API and console ports. Defaults include weak database and MinIO credentials, and Redis can run without a password. Restrict network access and replace defaults before exposing the stack. Existing bind mounts and fixed container names also need review before scaling or blue-green deployments. Do not expose phpMyAdmin publicly.

Keep existing APP_KEY values and persistent data; do not run destructive database commands. Back up and test restores. Use TLS, a reviewed firewall and production secrets. Review Compose interpolation and per-service environment files carefully before starting, since credentials must match and service env files do not automatically set every Compose interpolation variable.

## Deployment stages

1. Confirm SSH access, backups, server capacity, and the current production configuration.
2. Test the Ansible playbook on a non-production server.
3. Design and test a production Compose configuration with private data services, HTTPS, and managed secrets.
4. Add automated tests, a rollback plan, and a production deployment workflow.
5. Follow the staged K3s runbook after validating private data connectivity, TLS, backups and server capacity. Terraform remains optional for infrastructure provisioning.

A single VPS remains a single point of failure. Blue-green deployments require separate application versions, traffic switching, compatible migrations, and careful worker coordination; this foundation does not implement them.
