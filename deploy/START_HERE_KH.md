# ចាប់ផ្ដើម deploy CodeBridge លើ VPS ដែលមាន Docker រួច

Configuration នេះនៅក្នុង repository ផ្ទាល់។ វារក្សា API gateway និង routes ដើម ហើយបន្ថែម Docker images និង K3s deployment សម្រាប់ Auth និង Operations។ មិនទាន់មានការភ្ជាប់ ឬ deploy លើ VPS របស់អ្នកទេ។

| ផ្នែក | តួនាទី |
| --- | --- |
| Docker | Build images |
| Registry | រក្សាទុក images ឲ្យ VPS ទាញយក |
| K3s | គ្រប់គ្រង Pods និង rolling update |
| Traefik + Gateway | HTTPS និង routes ដើមលើ domain តែមួយ |
| MySQL / Redis / MinIO | នៅក្នុង Docker ឬ private endpoint ដដែលសិន |
| Worker / Scheduler | ចាប់ផ្ដើម paused ដើម្បីត្រៀមប្ដូរពី services ចាស់ |

## លំដាប់ធ្វើ

1. នៅ VPS run `bash deploy/scripts/preflight.sh` ដើម្បីមើល OS, RAM, ports និង Docker network។
2. Copy `deploy/config.example.json` ទៅ `deploy/config.json` ហើយកែ domain និង image names។
3. បំពេញ Secret files តាម README។ រក្សា APP_KEY/JWT_SECRET ចាស់ និង database volumes។
4. ធ្វើឲ្យ MySQL/Redis មាន private endpoint ដែល Pods អាចទៅដល់។ `localhost` ក្នុង Pod មិនមែនជា VPS ទេ។
5. Build images ឬ run manual GitHub workflow បន្ទាប់ពី merge។
6. Install K3s តាម README; Traefik ត្រូវបាន disable ដំបូង ដើម្បីមិនដណ្ដើម ports 80/443 ពី Docker។
7. Apply base និង Secrets ហើយ validate។ Backup/review database migrations មុន run migration Jobs។
8. Start web Pods ហើយ test តាម port-forward។ Copy/verify uploads ចាស់មុនប្ដូរ traffic។
9. រៀបចំ DNS/TLS និង port ownership រួចទើប enable Traefik និង expose gateway។
10. បញ្ឈប់ workers/scheduler ចាស់តាមផែនការ រួចកែ `activate_background` ទៅ `true` សម្រាប់ Kubernetes។

អាន [README.md](README.md) សម្រាប់ commands ពេញលេញ។ កុំ apply generated folder ទាំងមូលក្នុង command តែមួយ ព្រោះ migrations និង public traffic ត្រូវធ្វើជាដំណាក់កាល។

## ដំបូងនៅ VPS

```bash
bash deploy/scripts/preflight.sh
```

បន្ទាប់ពីកែ configuration៖

```bash
python3 deploy/scripts/render.py
```

Script នេះបង្កើត Kubernetes YAML ក្នុង `deploy/rendered/`។ Example host/images ត្រូវប្ដូរជាមុន។ Credentials មិនត្រូវ commit ឬផ្ញើក្នុង chat ទេ។

Pods ច្រើនលើ VPS មួយមិនអាចការពារពេល VPS ខូចទាំងមូលបានទេ។ ត្រូវមាន database/uploads backup នៅក្រៅ VPS។ កញ្ចប់នេះមិនលុបទិន្នន័យ មិន run migrate:fresh និងមិន deploy ដោយស្វ័យប្រវត្តិពេល push code ទេ។
