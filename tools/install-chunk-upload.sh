#!/usr/bin/env bash
set -euo pipefail
container=${1:-lsky-pro}
[[ "$container" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || { echo '容器名称无效'; exit 1; }
root=$(cd "$(dirname "$0")/.." && pwd)
manifest="$root/deployment-manifest.tsv"
[[ -f "$manifest" ]] || { echo '请使用完整补丁包，缺少 deployment-manifest.tsv'; exit 1; }
docker inspect "$container" >/dev/null
# Preflight EVERY file before replacing any application code. Keep deployment-specific edits intact.
while IFS=$'\t' read -r oldhash newhash path; do
    current=$(docker exec "$container" sh -c 'if [ -f "/var/www/html/$1" ]; then md5sum "/var/www/html/$1"; fi' sh "$path" | awk '{print $1}')
    if [[ -n "$current" && "$current" != "$oldhash" && "$current" != "$newhash" ]]; then
        echo "停止：$path 与审核的仓库版本不同。未覆盖任何代码，请先合并服务器的自定义改动。"
        exit 1
    fi
    if [[ "$oldhash" != '-' && -z "$current" ]]; then echo "缺少原文件：$path"; exit 1; fi
    actual=$(md5sum "$root/src/$path" | awk '{print $1}')
    [[ "$actual" == "$newhash" ]] || { echo "补丁文件校验失败：$path"; exit 1; }
done < "$manifest"
backup="/var/www/html/storage/app/chunk-patch-backups/$(date +%Y%m%d-%H%M%S)"
docker exec "$container" mkdir -p "$backup"
while IFS=$'\t' read -r oldhash newhash path; do
    docker exec "$container" sh -c 'if [ -f "/var/www/html/$1" ]; then mkdir -p "$2/$(dirname "$1")"; cp -p "/var/www/html/$1" "$2/$1"; else printf "%s\n" "$1" >> "$2/new-files.txt"; fi' sh "$path" "$backup"
    docker exec "$container" mkdir -p "/var/www/html/$(dirname "$path")"
    docker cp "$root/src/$path" "$container:/var/www/html/$path"
    docker exec "$container" chown www-data:www-data "/var/www/html/$path"
done < "$manifest"
# Runtime PHP configuration; preserve the current memory setting and other INI values.
docker exec "$container" sh -c 'if [ -f /usr/local/etc/php/conf.d/zz-upload-chunks.ini ]; then cp -p /usr/local/etc/php/conf.d/zz-upload-chunks.ini "$1/php-previous.ini"; fi; printf "post_max_size=544M\n" > /usr/local/etc/php/conf.d/zz-upload-chunks.ini' sh "$backup"
docker exec -u www-data -w /var/www/html "$container" php artisan view:clear
docker exec -u www-data -w /var/www/html "$container" php artisan route:clear
docker exec -w /var/www/html "$container" php artisan uploads:prune-chunks
docker exec "$container" apachectl -k graceful
# Hot patches do not replace the image entrypoint. Use an hourly host cron job for this deployment.
if [[ "$(id -u)" == 0 ]] && [[ -d /etc/cron.d ]]; then
    docker_path=$(command -v docker)
    cronfile="/etc/cron.d/lsky-upload-chunks-${container}"
    if [[ ! -e "$cronfile" ]] || grep -q '^# lsky-chunk-upload-patch$' "$cronfile"; then
        printf '# lsky-chunk-upload-patch\n0 * * * * root %s exec -u www-data -w /var/www/html %s php artisan uploads:prune-chunks >/dev/null 2>&1\n' "$docker_path" "$container" > "$cronfile"
        chmod 644 "$cronfile"
        if ! pgrep -x cron >/dev/null; then echo '提示：cron 未运行，请启用 cron，或按说明手动清理分片。'; fi
    else echo '已有同名 cron 文件，未覆盖，请按说明配置分片清理。'; fi
else echo '请按说明配置每小时分片清理任务。'; fi
printf '补丁已应用；原文件备份：%s\n请刷新网页并重新选择大文件上传。\n' "$backup"
echo '此为当前容器热补丁；以后重建镜像前，请把同一补丁合并到 GitHub 仓库。'
