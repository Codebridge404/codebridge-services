# Kubernetes Deployment Guide for CodeBridges Auth Service

This guide explains how to deploy **CodeBridges Auth & Identity Service** to a **Kubernetes (K8s)** cluster (Minikube, K3s, AWS EKS, GCP GKE, DigitalOcean, or Azure AKS).

---

## Architecture Overview

```
                      [ External Ingress (ingress-nginx) ]
                                       │
                  Host: auth.codebridge.local / Path: /api/v1/auth
                                       │
                                       ▼
                        [ Service: auth-service (Port 80) ]
                                       │
                        ┌──────────────┴──────────────┐
                        ▼                             ▼
               [ Pod 1 (Replica) ]           [ Pod 2 (Replica) ]
           ┌─────────────────────────┐   ┌─────────────────────────┐
           │ Nginx Sidecar (:80)     │   │ Nginx Sidecar (:80)     │
           │ FastCGI Pass (127.0.0.1)│   │ FastCGI Pass (127.0.0.1)│
           │ PHP 8.4-FPM (:9000)     │   │ PHP 8.4-FPM (:9000)     │
           └─────────────────────────┘   └─────────────────────────┘
                        │                             │
                        └──────────────┬──────────────┘
                                       │
                ┌──────────────────────┼──────────────────────┐
                ▼                      ▼                      ▼
        [ Service: auth-mysql ] [ Service: auth-redis ] [ Service: minio ]
          StatefulSet (MySQL)     Deployment (Redis)     Deployment (S3)
```

---

## 1. Quick Start: Deploying to Minikube (Local Testing)

### Step 1: Start Minikube & Enable Ingress
```bash
minikube start --driver=docker
minikube addons enable ingress
```

### Step 2: Build & Load Docker Image
```bash
# Build the local auth-service image
docker build -t codebridge-auth-service:latest codebridge-services/auth-service

# Load image into minikube cache
minikube image load codebridge-auth-service:latest
```

### Step 3: Deploy All Manifests via Kustomize
```bash
cd codebridge-services/auth-service/k8s
./deploy.sh
```
Or manually:
```bash
kubectl apply -k codebridge-services/auth-service/k8s
```

### Step 4: Run Database Migrations
```bash
kubectl apply -f codebridge-services/auth-service/k8s/migration-job.yaml
```

---

## 2. Inspecting the Cluster

Check pods in the `codebridge` namespace:
```bash
kubectl get pods -n codebridge
```
Expected output:
```text
NAME                                  READY   STATUS    RESTARTS   AGE
auth-deployment-xxxx-yyyy             2/2     Running   0          60s
auth-deployment-xxxx-zzzz             2/2     Running   0          60s
auth-horizon-xxxx-wwww                1/1     Running   0          60s
auth-mysql-0                          1/1     Running   0          2m
auth-redis-xxxx-aaaa                  1/1     Running   0          2m
minio-xxxx-bbbb                       1/1     Running   0          2m
```

Check services:
```bash
kubectl get svc -n codebridge
```

---

## 3. Testing the Endpoints Locally

### Via Port-Forward:
```bash
kubectl port-forward svc/auth-service 8080:80 -n codebridge
```

In another terminal, test health check:
```bash
curl http://localhost:8080/api/v1/health
```

Test OpenAPI documentation:
```bash
open http://localhost:8080/docs/api
```

---

## 4. Production Cloud Deployment (AWS EKS / GCP GKE)

For managed cloud providers:
1. Update `codebridge-services/auth-service/k8s/secret.yaml` with your production `APP_KEY`, `JWT_SECRET`, and managed database credentials.
2. If using managed AWS RDS or GCP Cloud SQL:
   - Remove `mysql.yaml` from `kustomization.yaml`.
   - Update `DB_HOST` in `configmap.yaml` with your RDS endpoint.
3. If using AWS S3 instead of MinIO:
   - Remove `minio.yaml` from `kustomization.yaml`.
   - Update `FILESYSTEM_DISK=s3` and S3 bucket credentials in `configmap.yaml` and `secret.yaml`.
4. Apply to your cluster:
   ```bash
   kubectl apply -k codebridge-services/auth-service/k8s
   ```

