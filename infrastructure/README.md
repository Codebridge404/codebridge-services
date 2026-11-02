# CodeBridge infrastructure foundation

This folder starts infrastructure configuration for the existing repository. It is based on the demo branch and does not deploy or modify the existing services.

## Existing project

- `docker-compose.yml` includes the gateway, Auth, Operations, and storage Compose files.
- `gateway/` routes requests; `auth-service/` and `operations-service/` are Laravel services.
- `storage/` defines MinIO. Each Laravel service has its own Dockerfile and Compose configuration.

## What goes where

- `ansible/`: configure an existing VPS over SSH. The example installs basic packages and creates an application directory; Docker is assumed to be installed.
- Terraform is optional. Add `terraform/` after deciding whether to provision a new VPS or import an existing one and reviewing the Contabo provider.
- K3s is a later step. Add `k3s/` only after defining registry images, secrets, database storage, gateway, workers, migration and rollback procedures. PHP-FPM does not serve HTTP directly; its health checks require an HTTP server or another appropriate probe.

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
5. Consider Terraform for infrastructure provisioning and K3s for orchestration after the single-server deployment is reliable.

A single VPS remains a single point of failure. Blue-green deployments require separate application versions, traffic switching, compatible migrations, and careful worker coordination; this foundation does not implement them.
