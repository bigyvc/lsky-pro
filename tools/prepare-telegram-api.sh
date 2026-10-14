#!/usr/bin/env bash
# Prepare deployment files using the actual Compose service label; do not recreate containers.
set -euo pipefail
umask 077
container="${1:-lsky-pro}"
base="/opt/lsky/docker-compose.yaml"
override="/opt/lsky/docker-compose.archive.yaml"
api_dir="/opt/lsky/telegram-api"
command -v docker >/dev/null
command -v python3 >/dev/null
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
[[ -f "$script_dir/telegram-api/Dockerfile" ]] || { echo '请使用仓库中完整的 tools 目录，缺少 telegram-api/Dockerfile' >&2; exit 1; }
[[ -f "$base" ]] || { echo "未找到 $base，请先确认现有部署文件" >&2; exit 1; }
service=$(docker inspect "$container" --format '{{index .Config.Labels "com.docker.compose.service"}}')
[[ "$service" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo '无法从容器确认 Compose 服务名，请提供部署文件后再配置' >&2; exit 1; }
project=$(docker inspect "$container" --format '{{index .Config.Labels "com.docker.compose.project"}}')
[[ "$project" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo '无法确认原 Compose 项目名' >&2; exit 1; }
docker compose -p "$project" -f "$base" config --services | python3 -c 'import sys; name=sys.argv[1]; assert name in sys.stdin.read().splitlines(), "容器服务不在现有 Compose 文件中"' "$service"
mkdir -p "$api_dir/data"
chmod 755 "$api_dir" "$api_dir/data"
if [[ ! -e "$api_dir/.env" ]]; then
    umask 077
    printf 'TELEGRAM_API_ID=\nTELEGRAM_API_HASH=\n' > "$api_dir/.env"
fi
[[ ! -e "$override" ]] || { echo "配置文件已存在：$override（保留原文件，请检查后手动调整）"; exit 0; }
docker compose -p "$project" -f "$base" config --format json | python3 -c 'import json,sys; c=json.load(sys.stdin); service=c["services"][sys.argv[1]]; assert not service.get("network_mode"), "network_mode 部署需要按实际配置手动适配"; assert "telegram-bot-api" not in c["services"], "telegram-bot-api 服务名已被使用"; print(json.dumps(service.get("networks", {"default":{}})))' "$service" > "$api_dir/existing-networks.json"
python3 - "$service" "$override" "$api_dir" <<'PY' 
import json,sys
service,destination,api_dir=sys.argv[1:]
with open(api_dir+"/existing-networks.json") as f: existing_networks=json.load(f)
existing_networks["lsky-archive"]={}
config={"services":{
 service:{"volumes":[api_dir+"/data:/var/lib/telegram-bot-api:ro"],"networks":existing_networks},
 "telegram-bot-api":{"build":{"context":api_dir,"dockerfile":"Dockerfile"},"image":"lsky-telegram-api:local","restart":"unless-stopped",
                     "env_file":[api_dir+"/.env"],"environment":{"TELEGRAM_LOCAL":"1"},
                     "volumes":[api_dir+"/data:/var/lib/telegram-bot-api"],
                     "expose":["8081"],"networks":{"lsky-archive":{}}}},
 "networks":{"lsky-archive":{"name":"lsky-archive"}}}
with open(destination,'x') as f:json.dump(config,f,ensure_ascii=False,indent=2);f.write('\n')
PY
rm -f "$api_dir/existing-networks.json"
cp "$script_dir/telegram-api/Dockerfile" "$api_dir/Dockerfile"
echo "已按实际服务名 $service 准备 $override"
echo "先在 $api_dir/.env 填 API ID/API Hash；Bot Token 只保存在图床 TG 策略；可独立归档，也可按手册切换现有 Bot。"
echo '本脚本没有启动或更新容器。现有端口、数据库和数据卷没有删除。'
printf '检查合并配置：docker compose -p %q -f %q -f %q config --quiet\n' "$project" "$base" "$override"
printf '构建成功后先拉取图床镜像：docker compose -p %q -f %q -f %q pull %q\n' "$project" "$base" "$override" "$service"
printf '镜像构建成功后部署：docker compose -p %q -f %q -f %q up -d --build --no-deps telegram-bot-api %q\n' "$project" "$base" "$override" "$service"
