# 浏览器与 iPhone 图标

两个站点使用统一镜像中的 Apple「照片」App 彩色花瓣图标，来源为 [Apple App Store 的 Photos 页面](https://apps.apple.com/us/app/photos/id1584215428) 对应的 Apple CDN artwork。图标素材属于 Apple；它仅用于站点图标，不表示本项目与 Apple 有关联。

- `public/favicon.ico`：32px PNG 封装的浏览器兼容图标。
- `public/favicon-32x32.png`：浏览器标签页 PNG。
- `public/apple-touch-icon.png`：180px iPhone 收藏与添加主屏幕图标。
- `resources/views/layouts/site-icons.blade.php`：统一引用，URL 使用内容指纹。
- 游客、登录、管理后台及安装页面均引用此片段；图床顶栏/侧栏内的 Lsky Logo 未更换。

更新正式镜像后清除 Blade 视图缓存并重开网页。iPhone 已保存的主屏幕快捷方式可能保留旧图标，可删除快捷方式后从 Safari 重新添加；删除快捷方式不会删除服务器图片。只 git pull 不会把源码复制到正在运行的图床数据目录。

HK 使用原来的双 Compose，SJC 使用统一 stack，更新命令见主 README 与既有部署说明。两站均需各自应用更新。
