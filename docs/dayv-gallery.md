# dayv.org：Multiverse 风格套图库

图库复用 SJC 的 Lsky 容器和数据库，使用独立域名`dayv.org`。界面改编自Multiverse的沉浸式图片展示方向，前端、分组接口和图床接入为本项目定制；页脚及关于页保留HTML5 UP署名。没有打包示例写真或第三方图片。

## 展示与公开范围

- 首页每个封面对应一个源帖照片组，显示照片数；支持最新、最早、随机和每页24组的继续加载。
- 手机双列封面，电脑多列。详情保持组内顺序，大图支持左右按钮、键盘方向键、手机单指横滑及放大/适应屏幕。
- 首页和套图缩略图只读取图床已有缩略图；缺失时显示占位，不自动下载全部原图。点开大图才通过既有流式服务读取原图。
- 默认关闭。只有执行配置命令选定的账号和唯一相册中的**已完成采集照片组**才可公开；这项操作会公开这些组，即使其图床记录的默认权限为私有。其他相册、游客上传、未建立采集组索引的普通上传不会自动展示。
- 健康状态异常、已经删除或移出目标相册的图片即时退出图库；整组没有可展示图片时隐藏该组。正常访客不会得到Bot Token、file_id、存储策略配置、账号邮箱、上传IP或源频道内部标识。
- 图库域名不能调用图床后台、登录页或原来的API。主图床域名`dayv.io`继续原样使用。
- 随机排序同一轮保持固定种子；分页固定当前索引上限，新搬入的组刷新后出现。搬运进度不需要重置。

## 数据与已有图片

新增`gallery_posts`、`gallery_post_images`和`gallery_site_settings`，只存分组元数据、已有图片ID和图库配置，不复制原图。新的照片组在TG验证通过后、与图片记录同一事务内建立索引。

`gallery:sync`从`storage/app/tg-photo-import`内现有**完整且完成**的JSON状态补录组关系，不读取已退休状态、不下载媒体、不发TG消息、不改图床权限或采集游标。只处理配置账号与相册里的完整记录；缺失或部分已删除的旧组跳过，损坏状态计入failed。可重复执行，不重复建立套图。新版本上线后新搬运的组会自动索引，无需定时执行sync。

## 1. 更新前：在完整原帖边界暂停搬运

以下沿用SJC现有部署：容器`lsky-pro`、`/opt/lsky/stack.env`、基础Compose和镜像覆盖文件。不要套用于HK，也不要删除数据卷。

如果`tg-import-oldest-fast`正在运行，在一帖成功保存后的等待阶段暂停，避免在TG发送中替换图床容器：

```bash
python3 -u - <<'PY'
import json,subprocess
name='tg-import-oldest-fast'
p=subprocess.Popen(['docker','logs','--tail','0','--follow',name],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
try:
    print('等待一帖保存完成再暂停……')
    for line in p.stdout:
        try: data=json.loads(line)
        except ValueError: continue
        if 'imported' in data:
            subprocess.run(['docker','stop','-t','120',name],check=True)
            break
    else:
        raise SystemExit('任务已经退出，请先核对日志')
finally:
    p.terminate()
PY
```

若任务已停止或完成，不运行上述等待脚本。其他容器名要以实际`docker ps`为准。不要reset采集进度。

## 2. 备份、更新对应成功构建的图床镜像

先运行当前已配置的私有备份。然后将`GALLERY_IMAGE`改为本次GitHub Actions成功构建的完整sha镜像，不使用latest替代测试分支：

```bash
bash /opt/lsky/source/tools/backup-github.sh

# 把占位符替换为成功构建的40位提交SHA：
GALLERY_IMAGE='ghcr.io/bigyvc/lsky-pro-docker:sha-替换为40位提交SHA'

cp -a /opt/lsky/docker-compose.tg-import.yaml \
  "/opt/lsky/docker-compose.tg-import.yaml.bak-$(date +%Y%m%d-%H%M%S)"

printf 'services:\n  lsky-pro:\n    image: %s\n' "$GALLERY_IMAGE" \
  > /opt/lsky/docker-compose.tg-import.yaml

args=(-p lsky --env-file /opt/lsky/stack.env \
  -f /opt/lsky/source/deploy/compose.stack.yaml)
if [ -f /opt/lsky/docker-compose.media-test.yaml ]; then
  args+=(-f /opt/lsky/docker-compose.media-test.yaml)
fi
args+=(-f /opt/lsky/docker-compose.tg-import.yaml)

docker compose "${args[@]}" config --quiet
docker compose "${args[@]}" pull lsky-pro
docker compose "${args[@]}" up -d --no-deps lsky-pro
```

观察健康状态和自动迁移输出：

```bash
docker inspect lsky-pro --format '状态={{.State.Status}} 健康={{.State.Health.Status}}'
docker logs --tail 80 lsky-pro
docker exec -u www-data -w /var/www/html lsky-pro php artisan migrate:status
```

确认`2026_10_18_120000_create_gallery_posts`已执行。只新增索引表，不重装或替换SQLite；若迁移失败，先排查，勿启动搬运。

## 3. 选择公开相册并补录旧套图

用已创建“频道图片”相册的图床账号邮箱，不能猜用户或相册ID。相册重名时先在后台改成唯一名称。

```bash
docker exec -u www-data -w /var/www/html lsky-pro \
  php artisan gallery:configure \
  --user=bigyvc@gmail.com --album='频道图片' \
  --host=dayv.org --title='DAYV · 影像之间' --enable

docker exec -u www-data -w /var/www/html lsky-pro \
  php artisan gallery:sync
```

补录输出`indexed/skipped/failed`。skipped包括未完成、已删除及其他账号/相册的状态；failed应为0，非0需先核对日志/状态。后台曾有的单张导入记录如果不含完整组状态，不会凭文件名猜组。

图库配置和组关系存放于现有SQLite的新表，自动包含在当前数据库备份中，不需要更换备份定时器。镜像覆盖文件及采集器会话/游标仍需要单独私密备份。

## 4. dayv.org DNS和HTTPS

在域名DNS管理处，把`dayv.org`的A记录指向**SJC当前公网IPv4**。如无可用IPv6，不添加AAAA；已有错误AAAA要修正。为排查方便可以先仅DNS，HTTPS正常后再按需要开启Cloudflare代理，使用Full (strict)。

不覆盖现有Caddyfile。在确认没有重复的`dayv.org`站点块后，为其添加：

```caddyfile
dayv.org {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8089
}
```

如果需要`www.dayv.org`，先配置其DNS，再另加`www.dayv.org { redir https://dayv.org{uri} permanent }`。只配根域名也可以。

```bash
caddy validate --config /etc/caddy/Caddyfile
systemctl reload caddy
```

本机检查（绕过DNS与Caddy）：

```bash
curl -sS -o /dev/null -w '图库首页 HTTP=%{http_code}\n' \
  -H 'Host: dayv.org' http://127.0.0.1:8089/
curl -sS -H 'Host: dayv.org' \
  http://127.0.0.1:8089/gallery-api/sets
curl -I https://dayv.org/
```

未配置时404是预期保护；启用后首页和列表应为200，`https://dayv.org/login`和`/api/v1/strategies`应为404。APIJSON只含公开影集元数据，不能包含凭据。

## 5. 恢复原搬运和验收

无需更新或重建采集器，无需更换Token，不改变5秒间隔和原游标。确认图床健康、迁移与补录正常后：

```bash
docker start tg-import-oldest-fast
docker logs --tail 20 -f tg-import-oldest-fast
```

如果这个容器原本已经正常完成，启动后会显示already_completed，不会重复导入；如之前有发送结果不确定的记录，先人工核对，不能通过删除游标处理。

验收：首页手机双列与电脑多列、最新/最早/随机、继续加载、深链接刷新、套图组内顺序、大图滑动/键盘/放大；抽查新搬一组后刷新出现；抽查其他相册和游客文件不出现在图库。观察VPS CPU、内存和流量，首页不会主动读取全部TG原图。

## 运维、关闭与限制

```bash
# 查看当前图库配置：
docker exec -u www-data -w /var/www/html lsky-pro php artisan gallery:configure
# 立即关闭公开图库（不删除图片，不影响搬运）：
docker exec -u www-data -w /var/www/html lsky-pro php artisan gallery:configure --disable
```

- 原图仍由SJC流式中转TG，访问速度取决于线路和缓存；主题不能把TG变成浏览器直传或无限带宽CDN。清理器继续沿用现有15GiB压力设置。
- 已发出的图片响应可能受浏览器/CDN缓存影响，删除或关闭不能撤回别人已保存的内容；图库接口使用no-store，删除记录后不再生成展示链接。
- 如果在Cloudflare设置缓存规则，不缓存`/gallery-api/`及后台/API；缩略图可单独按源站Cache-Control缓存。
- 没有增加游客投稿、用户登录、广告、下载开关、评论、追踪脚本或网站管理账号。更改展示内容继续在原图床相册管理。
- 与模板原始单页示例不同，本定制版新增动态套图、分页及相册公开边界。保留HTML5 UP设计署名；图片授权不由主题许可证授予。
- 本地验证使用Laravel HTTP/数据库回归与jsdom交互测试；本环境没有可用的受支持浏览器预览，手机/桌面视觉与真实TG线路验收需在部署后完成。
