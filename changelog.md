# Changelog

## 1.1.5 — 复兴版（2026-09-24）

### 环境与验证基线

- 目标环境改为 **Debian 12 / PHP 8.2 / Swoole 6.2.x**（实测 6.2.3）。`composer.json`：
  `php >= 7.4`、新增 `ext-swoole >= 4.2`、`require-dev` 加 phpunit、`suggest` 指向 duckphp。
- 新增 `dev/` 验证体系：`dev/test.sh`（28 项）+ `dev/test-duckphp.sh`（22 项，含并发不串数据），
  全部**真起服务器、真发 HTTP 请求**；另有 3 个 `probe-*.php` 记录版本相关的行为假设。
- 删除 `tests/` 下由 `TestFileGenerator` 自动生成的空壳测试（断言全在注释里、且引用 2021 年
  已删除的类）、`phpunit.xml`（PHPUnit 8/9 语法）、`.php_cs`（php-cs-fixer 2.x 语法）。

### 新增

- **`HttpServerForDuckPhp`** —— 实现 DuckPhp 官方的 `DuckPhp\HttpServer\HttpServerInterface`，
  可用 `php cli.php run --http_server=SwooleHttpd/HttpServerForDuckPhp` 直接替换框架内置服务器。
- **`CoroutinePhaseContainer`** —— 按协程分身的 `PhaseContainer`：每个请求协程一份**完全独立**的
  组件容器（含 `#public` 桶），结构信息播种 + 组件 `clone`；应用实例按引用共享；
  连接类组件用 `http_app_renew_classes` 声明为每协程新建连接。
- **`Swoole404Exception`** —— 恢复（2021 年被删但 README/测试/文档仍在引用）。
- **`docs/duckphp-integration.md`** —— DuckPhp 集成与协程模型的完整说明。

### 修复（均为实测确认的缺陷）

- `SwooleContext`：`use Swool\Coroutine`（少个 e）→ 会话功能必然 `Class not found`。
- `session_start()` / `session_destroy()` / `session_set_save_handler()` 门面调用了
  `SwooleSuperGlobal` 上不存在的方法 → 改为路由到 `SwooleContext`。
- `onShutdown()` 把 `[类名,'方法']` 当函数名调用 → **整个 worker 被杀**；重写为
  `[callable, args]` 形状并兼容旧的 `func_get_args()` 形式，且注册为实例可调用。
- `ob_start()` 回调里调 `response->end()` → 大响应二次 `end()` 丢数据、空响应不 `end()` 挂到超时。
  改为普通缓冲 + 收尾发一次，`is_response_ended` 保证幂等，`sendfile()` 路径提前标记。
- 请求收尾的 defer 不再可能抛异常（用户 shutdown 回调出错不会带走 worker）。
- `setcookie()` / `mt_rand()` 收到 `ini_get()` 的字符串 → 补 `(int)`/`(bool)` 转换。
- WebSocket 事件名 `'mesage'` → `'message'`。
- `http_handler_file` 模式返回 `null` → 入口文件跑完后又追加 404；改为 `return true`。
- `onHttpClean()` 开头的裸 `return;` → 删掉，autoload 清理逻辑恢复生效。
- `StaticReplacer` 补进 `getDynamicComponentClasses()` → `GLOBALS()`/`STATICS()`/
  `CLASS_STATICS()` 不再跨请求泄漏。
- `SwooleCoroutineSingleton`：`$cid_map` 漏 `self::`；`EnableCurrentCoSingleton($cid)` 的映射方向写反
  （导致该重载静默失效）；新增 `GetOwnerCid()` 沿 `Coroutine::getPcid()` 上溯，
  使请求内 `go()` 出来的子协程共用父请求的实例空间。
- `Coroutine::getuid()` → `getCid()`（6.x 下推荐用法）。
- `SwooleSuperGlobal`：`is_inited` 时机修正（无请求时不再错误标记已初始化）、
  公开属性给默认值、`REQUEST_URI` 只在确实缺 query 时才拼接（原实现会产出 `?a=1?a=1`）。
- `SwooleSessionHandler`：补 `#[\ReturnTypeWillChange]`（PHP 8.1+ 的
  `SessionHandlerInterface` 试验性返回类型）、空 save path 回退到临时目录、`gc()` 返回删除数量、
  session id 过滤后再拼路径。
- `mime_content_type` 走 `SwooleContext`（不硬依赖 fileinfo），并接入系统函数表。
- `header()` 解析后 `trim` 值（原实现产出 `X-Probe:  yes` 双空格）。

### 新增/补齐的 API（此前是"文档和测试有、代码没有"的幻影 API）

`SG()`、`ThrowOn()`、`Throw404()`、`exit_request()`、`set_http_404_handler()`、
`Swoole404Exception`；`init()` 现在真正支持 `$server` 形参与 `swoole_server` 选项；
`base_class` 选项现在真正实现；`system_wrapper_get_providers()` 补齐到 DuckPhp 要求的
10 个键（新增 `session_id`、`mime_content_type`）。

### 删除

- `src/SimpleHttpd.php`（旧 trait，调用不存在的 `mapToGlobal()` 和裸 `\defer()`，且无人使用）。
- 3 行指向不存在 trait 的残余 import（仅是 import 别名，从未在 class body 里 `use`）。
- `SwooleExt*` 那套旧接口（半成品，且其设计前提已被新版 DuckPhp 废弃）。

### 文档

- README 新增「1.1.5 复兴版」章节，并订正选项名（`swoole_options`→`swoole_server_options`、
  `enable_not_php_file`→`enable_resource_file`）、命名空间、宏名等处与代码不一致的描述。

### 已知限制

- **真超全局变量在协程间不隔离**（实测；PHP 超全局在 Swoole 下没有按协程虚拟化）。
  请求开始时会同步一份进真超全局，仅供"让出之前就读"的传统代码使用；
  协程安全的唯一真相是 `SwooleHttpd::SG()->_*`（DuckPhp 经 `__SUPERGLOBAL_CONTEXT` 走同一条路）。
- **自己注册闭包式路由钩子会破坏协程隔离**（闭包捕获 `$this`，clone 后仍指向主进程对象）；
  请改用 `[类名, '方法名']` 静态可调用。见 `docs/duckphp-integration.md` §4。
- `KernelTrait::SwitchRootPhase()` 直接写 `PhaseContainer` 的 public 属性，子类无法拦截，
  因此嵌套应用重设"谁是根"表现为进程级生效。

---

从 1.0.2 到 1.1.0 版本变更
命名空间又改回 DNMVCS\SwooleHttpd 和 SwoleHttpd 搭配使用

从1.01 版本到 1.02 版本的变更
2019-04-08 10:04:31
命名空间由 DNMVCS 变更为 SwooleHttpd
修复 autoload 的 dump

从1.01 版本到 1.02 版本的变更
2019-04-01 22:04:15
分拆文件
删除 SessionImpelement 类
其他一些更新
