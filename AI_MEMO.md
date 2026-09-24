# SwooleHttpd 复活备忘（给后续 AI 的交接文档）

> 用途：本文件是 `E:\ProjectGoat\swoolehttpd` 这个库的**交接备忘**。
> 目标：让后续 AI 不用重新考古，就能直接动手把这个库改造成**能和新版 DuckPhp（`E:\ProjectGoat\DNMVCS`，v1.4.1）协同工作**的 Swoole 服务器适配层。
>
> 本文件里所有"事实"都带 `文件:行号`；未经实测的推断会显式标注 **【待验证】**。
> 分析时的实测环境：Windows，`PHP 7.4.33 (cli)`，**swoole 扩展未安装**，无 `vendor/`，无 phpunit。

---

## 0. TL;DR（先看这段）

1. **这个库确实跑不起来**，但原因不是"一个 bug"，而是三层叠加：
   - **L1 环境缺失**：没有 swoole 扩展、没有 `vendor/`、没有 phpunit，连 lint 之外的事都做不了。
   - **L2 代码有真实 bug**：至少 1 个必然致命（会话功能）、若干个功能级坏死（WebSocket、`http_handler_file` 模式、`base_class`）、一段死代码。
   - **L3 与 DuckPhp 的对接层整体失效**：库里的 DuckPhp 适配代码写的是 **2019 年 DuckPhp 的 API**（`::G()`、`getDynamicComponentClasses()`、`skip_404_handler`、`forkMasterInstances()`），这些在新版 DuckPhp 里**全部不存在**（已逐项 grep 验证）。库自己的 `SwooleExt*` / `Swoole404Exception` / `ServerForDuckPhp` 四个文件在 2021-03-27 被删掉了，但 `tests/` 和 `README.md` 还在引用它们。

2. **好消息**：新版 DuckPhp **主动留了一个官方插件位**给外部 HTTP 服务器 —— `DuckPhp\HttpServer\HttpServerInterface`（只有 4 个方法）+ CLI 开关 `--http_server=...`。这个库天生就是该接口的实现者。见 §4.2。

3. **真正的硬骨头**（不是修 bug 能解决的，是架构问题）：新版 DuckPhp 的所有组件单例都挂在 **`PhaseContainer::$instance` 这个进程级全局静态**上，而 `KernelTrait::serve()` 里的 `prepareServe()` 把动态组件列表留成空（`KernelTrait.php:501-506` 的 `$classes = []`）——**隔离与每请求重置都需要服务器实现方自己承担**，这正是 Swoole 常驻进程的前提。详见 §5。
   > ✅ **已由用户裁定解决方案**（2026-09-24）：**override `PhaseContainer`**（换实例、含 `#public` 的完全独立容器）+ **`options['on_request']`** 作每请求钩子（**不碰** `prepareServe()`）。**完整设计、可覆写表面、4 个坑见 §12 —— 动手前必读。**

4. **建议的动手顺序**：先修 L2 的 bug 让单请求能跑通（可验证、低风险）→ 再按 `HttpServerInterface` 重写对接层（L3）→ 最后做协程隔离（§12 已给定方案）。**不要**一上来就改协程隔离，那时候你连"请求能跑通"都还没验证。

---

## 1. 这个库是什么 / 想干什么

`README.md:3-27` 说得很清楚：

- 它是 `swoole_http_server` 的一层包裹，目标是让**同一份 PHP 代码在 Swoole 和 php-fpm 两个平台几乎不改就能跑**。
- 特色是"**直接用超全局变量、直接 echo 输出**" —— 为旧代码迁移服务。
- 它不依赖任何外部 PHP 代码（`composer.json:20-22` 只要求 `php >= 7.0.0`，无任何 require）。
- 命名空间 `SwooleHttpd\` → `src/`（`composer.json:15-19`，另有独立的 `autoload.php` 不依赖 composer）。

它解决的四类"fpm → Swoole 迁移痛点"（README 顺序）：

| 痛点 | 本库的解法 | 实现位置 |
|---|---|---|
| 超全局变量在协程间串数据 | `SwooleSuperGlobal`，可选替换 `__SUPERGLOBAL_CONTEXT` | `src/SwooleSuperGlobal.php` |
| `global` / `static` / 类内 `static` 在协程间串数据 | `GLOBALS()` / `STATICS()` / `CLASS_STATICS()` | `src/StaticReplacer.php` |
| 同名系统函数（`header`/`setcookie`/`session_*`）在 Swoole 下不可用 | 一组静态封装 + provider 表 | `src/SwooleHttpd.php:366-416` |
| `session_start()` 在 Swoole 下没法用 | 自写文件版 `SessionHandler` + 协程 session | `src/SwooleContext.php` + `src/SwooleSessionHandler.php` |
| `require`/`include` 重复包含导致函数重复定义 | **明确没解决**（README:25-27 承认，需要 php-parser） | —— |

三种运行模式（README:106-124，对应 `SwooleHttpd_Runner::onHttpRun()`，`src/SwooleHttpd.php:470-512`）：

1. `http_handler`：所有 URL 都进一个回调（主模式，不搜文件）。
2. `http_handler_root`：等价 `document_root`，按路径找 PHP 文件跑（含 path_info、资源文件）。
3. `http_handler_file`：所有 URL 都转向单一入口文件（如 `index.php`）。

三者可叠加（`with_http_handler_root` / `with_http_handler_file`）。

### 血缘关系（很重要的背景）

- 它从 DuckPhp 的前身 DNMVCS 里剥离出来（README:8-9）。
- `changelog.md` 记录了命名空间反复横跳：`DNMVCS\SwooleHttpd` → `SwooleHttpd` → 又改回 `DNMVCS\SwooleHttpd`（`changelog.md:1-12`）。**注意 `changelog.md` 与代码现状不一致**：现在代码是 `SwooleHttpd\`（单段命名空间），`composer.json:17` 与 `autoload.php:4` 都证实。
- DNMVCS 侧的 `docs/old/ChangeLog.txt:372-393` 有这段历史的另一面（`DNSwooleHttpServer` → 拆成 `SwooleHttpServer` → 独立成 `SwooleHttpd` 项目）。

### 仓库时间线（实测 git）

```
3002bf2 2019-03-13 Initial commit
...
7871ecf 2021-04-09 能跑通demo 的版本      ← HEAD
tags: v1.0.1 v1.0.2 v1.0.3 v1.0.4
```

**HEAD 停在 2021-04-09**，工作区干净（`git status` 无改动）。也就是说这是一个 5 年前的快照。

---

## 2. 代码结构总览（实测真实 API）

### 2.1 文件清单

| 文件 | 行 | 声明的东西 | 状态 |
|---|---|---|---|
| `src/SwooleHttpd.php` | 614 | `class SwooleHttpd` + 6 个 trait（`SwooleHttpd_RunFile`(空)、`_SimpleHttpd`、`_Handler`、`_Glue`、`_SystemWrapper`、`_SingletonHandle`、`_Runner`） | 主体，可加载 |
| `src/SwooleContext.php` | 176 | `class SwooleContext` | 有致命 bug（§3.2-#1） |
| `src/SwooleSuperGlobal.php` | 119 | `class SwooleSuperGlobal` | 可用 |
| `src/SwooleCoroutineSingleton.php` | 184 | `class SwooleCoroutineSingleton` | 有隐蔽 bug（§3.2-#2） |
| `src/SwooleSingleton.php` | 27 | `trait SwooleSingleton`（提供 `G()`） | 可用 |
| `src/SwooleSessionHandler.php` | 57 | `class SwooleSessionHandler implements SessionHandlerInterface` | 可用，但有 PHP 8.1 兼容问题 |
| `src/StaticReplacer.php` | 52 | `class StaticReplacer` | 可用，但协程不安全（§5.7） |
| `src/SimpleHttpd.php` | 66 | `trait SimpleHttpd` | **坏死 + 无人使用**（§3.2-#7） |
| `src/SimpleWebSocketd.php` | 54 | `trait SimpleWebSocketd` | 可用（但事件名登记错了，§3.2-#3） |
| `src/SwooleException.php` | 10 | `class SwooleException extends Exception` | 空类，可用 |

### 2.2 `SwooleHttpd` 真实方法表（`ReflectionClass` 实测，非文档）

实际 use 的 trait（8 个，**只有这些**）：

```
SwooleSingleton, SwooleHttpd_SimpleHttpd, SimpleWebSocketd,
SwooleHttpd_Handler, SwooleHttpd_Glue, SwooleHttpd_SystemWrapper,
SwooleHttpd_SingletonHandle, SwooleHttpd_Runner
```

> ⚠️ 一个**曾经的误判**，留给后续 AI 避免重复踩：
> `src/SwooleHttpd.php:16-20` 有 `use SwooleHttpd\SwooleHttpd_Static; use ..._SuperGlobal; use ..._Singleton;` 三行，
> 而这三个 trait **在仓库里不存在**。但它们只是**文件顶部的 import 别名**，**没有在 class body 里 `use`**，
> 所以**不会** fatal。实测 `class_exists('SwooleHttpd\SwooleHttpd')` 返回 `true`。
> 它们是 2019-12-21 提交 `aee0fee` 把 `src/SwooleHttpd_*.php` 拆掉合并进单文件时留下的**残余 import**，可以直接删。

| 类别 | 方法 |
|---|---|
| 入口 | `static RunQuickly(array $options = [], callable $after_init = null)`、`init(array $options, $server = null)`、`run()`、`createServer()` |
| 钩子（子类覆盖点） | `onHttpRun($request,$response)`、`onHttpException($ex)`、`onHttpClean()`、`onRequest($request,$response)`、`onOpen($server,$request)`、`onMessage($server,$frame)` |
| 静态门面 | `Server()`、`Request()`、`Response()`、`Frame()`、`Fd()`、`IsClosing()`、`OnShow404()`、`OnException($ex)` |
| 超全局替代 | `&GLOBALS($k,$v=null)`、`&STATICS($k,$v=null)`、`&CLASS_STATICS($class_name,$var_name)` |
| 系统封装 | `header()`、`setcookie()`、`exit()`、`set_exception_handler()`、`register_shutdown_function()`、`session_start()`、`session_destroy()`、`session_set_save_handler()`、`system_wrapper_get_providers()` |
| 单例 | `static G($object=null)`、`ReplaceDefaultSingletonHandler()`、`EnableCurrentCoSingleton()` |
| DuckPhp 对接（**已失效，见 §4.6**） | `getDynamicComponentClasses()`、`forkMasterInstances($classes,$exclude=[])`、`_OnServerRequest()`、`initApp()` |
| 分发内部 | `fixIndex()`、`prepareRootMode()`、`runHttpFile()`、`includeHttpFullFile()`、`runPhpFile()`、`send_file()` |
| 其他 | `is_with_http_handler_root()`、`set_http_exception_handler($ex)`、`_exit($code)`、`_OnShow404()`、`_OnException($ex)`、`deferGC()`、`checkShutdown()`、`check_swoole()` |

### 2.3 一份请求的完整生命周期（读懂这个就懂了库）

入口 `RunQuickly` → `G()->init($options)->run()`（`src/SwooleHttpd.php:81-89`）。

`init()`（`:145-182`）做五件事：
1. merge `$options`，把 `http_handler_basepath` 转成 `realpath` 并补尾 `/`（`:147-148`）；
2. `createServer()`（`:191-205`）建 `Swoole\Http\Server` 或 `Websocket\Server`（并 `check_swoole()` 要求 swoole ≥ 4.2.0，`:132-142`）；
3. `$server->set(swoole_server_options)` + 注册 `request` 事件（`:154-155`）；
4. 若配了 websocket handler 就注册 `open`/`mesage` 事件（`:165-169`，**事件名拼错，见 §3.2-#3**）；
5. 若配了 `http_app_class` 就 `initApp()`（`:170-172`）；
6. 最后 `Runtime::enableCoroutine()` + `SwooleCoroutineSingleton::ReplaceDefaultSingletonHandler()`（`:176-179`）。

`run()`（`:206-215`）：打印一行启动信息，然后 `$this->server->start()`（**阻塞，永不返回**）。

单请求 `onRequest()`（trait `SwooleHttpd_SimpleHttpd`，`:238-282`）—— 顺序极其讲究，**注释明确说"没 bug 就别动"**：

```
deferGC()                              // Coroutine::defer → gc_collect_cycles()
EnableCurrentCoSingleton()             // 开协程单例 + defer 清理
checkShutdown()                        // is_shutdown 时抛异常（但没人设它，见 §3.2-#5）
Coroutine::defer( ob_end_flush... + SwooleContext::G()->cleanUp() )
Coroutine::defer( SwooleContext::G()->onShutdown() )     // 伪 register_shutdown_function
ob_start(callback)                     // echo → response->end()
SwooleContext::G(new SwooleContext())->initHttp($request,$response)
SwooleSuperGlobal::G(new SwooleSuperGlobal())->SaveSuperGlobalAll()
try { $flag = onHttpRun($request,$response) } catch(Throwable) { _OnException($ex) }
if (!$flag) _OnShow404()
onHttpClean()
```

> 注意 defer 是**后进先出**：所以实际顺序是 `onShutdown()`（跑用户注册的 shutdown 函数）→ `ob_end_flush`+`cleanUp()` → `gc_collect_cycles()`。这个顺序是对的：session 的 `writeClose` 就是靠 `regShutdown` 挂在 `onShutdown` 上的（`src/SwooleContext.php:118-121`）。

`ob_start` 回调（`:258-265`）是整套"直接 echo"魔术的核心：

```php
ob_start(function ($str) {
    if ('' === $str) { return; }
    SwooleContext::G()->response->end($str);
});
```

回调**不返回** `$str`，所以内容被 `end()` 送走后不会重复输出。**但这个设计很脆**（§5.5）。

`onHttpRun()`（`SwooleHttpd_Runner`，`:470-512`）按模式分发：

```
if http_app_class  → 保存 autoload 快照 → _OnServerRequest() → return true   （DuckPhp 路径）
if http_handler    → auto_clean_autoload=false; fixIndex(); 调 handler
                     true→return true；false 且没配 root/file→return false(404)
if http_handler_root → prepareRootMode(); runHttpFile()
if http_handler_file → runPhpFile() 然后 ... return;   ← 返回 null，见 §3.2-#4
```

### 2.4 协程单例机制（理解隔离问题的关键）

两层设计：

- `trait SwooleSingleton`（`src/SwooleSingleton.php:11-27`）给每个类一个 `G($object=null)`。
  关键在 `:13-16`：**只要常量 `__SINGLETONEX_REPALACER` 被定义，`G()` 就整个外包给它**。
- `SwooleCoroutineSingleton::ReplaceDefaultSingletonHandler()`（`src/SwooleCoroutineSingleton.php:17-24`）**负责定义**这个常量：

```php
define('__SINGLETONEX_REPALACER', self::class.'::SingletonInstance');
```

于是所有 `X::G()` 都走 `SwooleCoroutineSingleton::SingletonInstance($class, $object)`（`:40-72`）：
按 `Coroutine::getuid()` 拿到 cid，查 `self::$_instances[$cid][$class]`；
**协程内没有就回落到 cid=0 的 master 实例**（`:51-56`）；master 也没有才 new。

`EnableCurrentCoSingleton()`（`:87-119`）在请求协程里为当前 cid 开一个空槽位，并 `Coroutine::defer` 在协程结束时 `unset`，实现"协程单例用完即弃"。

`forkMasterInstances($classes, $exclude)`（`:120-153`）把 master 实例 clone 到当前协程 —— 这就是"让协程拥有自己的组件副本"的手段，也是 `SwooleHttpd_SingletonHandle::getDynamicComponentClasses()` 返回 `[SwooleSuperGlobal, SwooleContext]` 的用途。

**这套东西本身设计是自洽的。问题在于：新版 DuckPhp 不用 `G()` 也不用 `__SINGLETONEX_REPALACER`（除了 `HttpServer::_()`），所以这套机制对 DuckPhp 的组件完全不起作用。** 见 §4.6 / §5.1。

---

## 3. 当前状态：为什么跑不起来（全部实测证据）

### 3.1 L1 环境事实（先确认这些，否则你会白折腾）

| 检查项 | 实测结果 |
|---|---|
| `php -v` | `PHP 7.4.33 (cli) (NTS Visual C++ 2017 x64)`，带 Xdebug 3.1.6 |
| `extension_loaded('swoole')` | **`false`**；`function_exists('swoole_version')` → **`NULL`** |
| `php -m` 里有无 swoole | 无（只有 `curl` 命中） |
| `vendor/` | **不存在** |
| phpunit | **不存在**（`class_exists('PHPUnit\Framework\TestCase')` → false） |
| composer | 未找到可用的 composer.phar |
| `php -l src/*.php` | **10 个文件全部语法通过** |

**结论**：`check_swoole()`（`src/SwooleHttpd.php:132-142`）会直接 `echo 'SwooleHttpd: PHP Extension swoole needed;'` 然后 `exit`。
**在这个环境里，`SwooleHttpd` 一行业务代码也跑不了。任何"修复验证"都必须先装 swoole（建议 Swoole 5.x + PHP 8.x，见 §9）。**

### 3.2 L2 确认的 bug 清单（按严重度排序）

#### 🔴 BLOCKER-1（已实测复现）：`SwooleContext` 里 Coroutine 命名空间拼错 → 会话功能必崩

`src/SwooleContext.php:10`
```php
use Swool\Coroutine;      // ← 少了一个 e，应为 Swoole\Coroutine
```
而 `:170-174` 用它：
```php
public function create_sid()
{
    $cid = Coroutine::getuid();     // 解析成 Swool\Coroutine::getuid()
    return md5(microtime().' '.$cid.' '.mt_rand());
}
```

**实测复现**（即使没有 swoole 扩展也能复现，因为类名解析先发生）：
```
$ php -r 'require "autoload.php"; SwooleHttpd\SwooleContext::G()->create_sid();'
Error: Class 'Swool\Coroutine' not found
```

**影响链**：`session_start()`（`:122-142`）→ 无 cookie 时 → `getSessionId()`（`:91-111`）→ `create_sid()` → **崩**。
即**首次访问时 `session_start()` 100% 抛 Error**。README:23 说 session 是本库最重要的卖点之一，所以这是最该先修的。
**最小修复**：`use Swoole\Coroutine;`。

#### 🔴 BLOCKER-2（WebSocket）：Swoole 事件名拼错

`src/SwooleHttpd.php:167`
```php
$this->server->on('mesage', [$this,'onMessage']);   // ← 应为 'message'
```
Swoole 不认识 `mesage`，**`onMessage` 永远不会被调用**。WebSocket 收消息功能完全失效（且 Swoole 可能因未知事件名报 warning）。
**最小修复**：改成 `'message'`。

#### 🟠 BROKEN-1：`http_handler_file` 模式必然输出 404

`src/SwooleHttpd.php:505-511`
```php
if ($this->options['http_handler_file']) {
    $path_info = $_SERVER['REQUEST_URI'];
    $file = $this->options['http_handler_basepath'].$this->options['http_handler_file'];
    $document_root = dirname($file);
    $this->runPhpFile($file, $document_root, $path_info);
    return;                      // ← 返回 null
}
```
`onHttpRun()` 返回 `null` → `onRequest()` 里 `$flag = null` → `if (!$flag) $this->_OnShow404();`（`:278-280`）
→ **入口文件已经执行并输出内容之后，又追加一段 404 文本**。
**最小修复**：`return true;`。

#### 🟠 BROKEN-2：`base_class` 选项是死配置

`src/SwooleHttpd.php:68` 声明了 `'base_class' => ''`，README:86 也把它写成文档化的功能（"替换 SwooleHttpd 类初始化"）。
实测：**全 `src/` 没有任何地方读 `options['base_class']`**。
（对照 README:405-409 描述的 `init()` 流程第 1 步"检测是否有 base_class，如果有则替换当前单例" —— 那段代码不存在。）
**修复**：要么在 `init()` 开头实现它，要么从 `$options` 和 README 里删掉。

#### 🟠 BROKEN-3：`init()` 的 `$server` 形参被忽略，无法注入现成 server

`src/SwooleHttpd.php:145`
```php
public function init(array $options, $server = null)   // $server 从未被使用
```
`createServer()`（`:191-205`）永远按 `host`/`port` 新建 server。
README:66 的 `DEFAULT_OPTIONS` 里还写着 `'swoole_server' => null, // 留空，则用 host,port 创建`，但代码里既没有这个选项名（实际叫 `swoole_server_options`），也没有注入逻辑。
**影响**：单元测试无法注入 mock server；也无法复用外部已建好的 server。
**修复**：`$server ?: $this->createServer()` 之类。

#### 🟡 DEAD-1：`onHttpClean()` 第一行就是 `return;`

`src/SwooleHttpd.php:116-131`
```php
protected function onHttpClean()
{
return;                          // ← 后面 11 行全是死代码
    if (!$this->auto_clean_autoload) { return; }
    $functions = spl_autoload_functions();
    ...
}
```
README:447-450 说这个函数负责"处理 autoload，防止 http_handler_root/http_handler_file 模式多次载入 spl_autoload"。
实际效果：**常驻进程里 `http_handler_root`/`http_handler_file` 模式每请求 include 的文件所注册的 autoloader 会不断累积**（内存泄漏 + 重复加载风险，正是 README:119 警告的"重复包含类会导致异常"）。
**修复**：删掉那行 `return;` 并验证逻辑。

#### 🟡 DEAD-2：`SimpleHttpd.php` 整个 trait 坏死且无人使用

`src/SimpleHttpd.php` 是一个**和 `SwooleHttpd.php:221` 里那个 `SwooleHttpd_SimpleHttpd` 同名不同物**的旧 trait（类名 `SimpleHttpd`），且：
- `:58` 调用 `SwooleSuperGlobal::G(...)->mapToGlobal()` —— **`mapToGlobal` 方法不存在**（实测 `method_exists` → `MISSING`；真实方法是 `SaveSuperGlobalAll()`，`src/SwooleSuperGlobal.php:93-96` / `_SaveSuperGlobalAll` `:108-118`）。
- `:35` / `:41` / `:48` 使用 `\defer(...)` 裸函数 —— 这是 Swoole 的**短名**函数，在 `swoole.use_shortname=Off`（Swoole 5 默认）下**不存在**。
- `SwooleHttpd` 类并没有 use 它。

**处理建议：直接删除**，或改写成 `SwooleCoroutineSingleton::EnableCurrentCoSingleton()` / `Coroutine::defer()`。

#### 🟡 LATENT-1：`SwooleCoroutineSingleton` 漏了 `self::`

`src/SwooleCoroutineSingleton.php:44`
```php
$cid = $cid_map[$cid] ?? $cid;      // ← 应是 self::$cid_map
```
`$cid_map` 是未定义局部变量。因为 `??` 会抑制 undefined 警告，结果是**恒定回落到 `$cid` 本身**，即 `cid_map` 映射被静默忽略。
**影响**：`EnableCurrentCoSingleton($cid)`（`:87-101`，带参数版本，用于"在 `\go` 里挂到父请求的协程单例空间"）实际不生效。功能级 bug，不是崩溃级。
**修复**：`self::$cid_map[$cid] ?? $cid`。

#### 🟡 LATENT-2：`SwooleSuperGlobal::init()` 的 `REQUEST_URI` 会被重复拼接 query

`src/SwooleSuperGlobal.php:74-77`
```php
// fixed swoole system bug
if (!empty($this->_GET)) {
    $this->_SERVER['REQUEST_URI'] .= '?'.http_build_query($this->_GET);
}
```
注释说是"修 swoole 的 bug"，但**现代 Swoole 的 `$request->server['request_uri']` 已经带 query**，这段会导致 `REQUEST_URI` 变成 `/path?a=1?a=1`。
`REQUEST_URI` 在新版 DuckPhp 里被 **10 处**读取（实测 grep），其中：
- **会因此产生错误输出的**：`Component/Pager.php:38` 直接返回原始 `REQUEST_URI` 用于拼分页链接 → 链接里会出现 `?a=1?a=1`；
- **被 `parse_url(..., PHP_URL_PATH)` 掩盖的**：`Core/Route.php:393`、`Ext/RouteHookDirectoryMode.php:39`、`Component/RouteHookPathInfoCompat.php:47`、`GlobalUser/GlobalUser.php:85`、`GlobalAdmin/GlobalAdmin.php:85`、`Foundation/Controller/{Admin,User}ControllerBase.php:33/34`（这些取的是 PATH 部分，重复的 query 不影响结果）。
所以**不要**把它当成"路由必崩"，但**必须修** —— 它是明确的语义错误，而且会在分页/回跳链接上暴露。
**修复建议**：先 `if (strpos($this->_SERVER['REQUEST_URI'],'?') === false)` 再拼；或直接删除并实测目标 Swoole 版本行为。

#### 🟡 LATENT-3：`SwooleSuperGlobal::init()` 在 request 为空时留下未初始化公开属性

`:38-47`：先把 `$this->is_inited = true`（`:41`），再取 request 并 `if (!$request) return;`（`:45-47`）。
这条路（如 WebSocket 的 `onOpen` 场景，或 `SwooleContext` 还没 init 就构造）会让 `_GET/_POST/...` 全部保持**未初始化**，
后续 `_SaveSuperGlobalAll()`（`:108-118`）执行 `$_GET = $this->_GET;` → 读未定义属性 → warning（PHP 8 升级为 Warning，在 DuckPhp 的 `ExceptionManager::on_error_handler` 下**可能被转成 ErrorException**，见 `DNMVCS/src/Core/ExceptionManager.php:69-87`）。
**修复**：把 `is_inited = true` 移到 request 检查之后，或在构造函数里给这 8 个属性默认值。

#### ⚪ 其他观察（非 bug，但要知道）

- `src/SwooleSessionHandler.php:11` `implements SessionHandlerInterface` 但 6 个方法都没有返回类型声明。**PHP 8.1+ 会发 deprecation notice**，同样会被 DuckPhp 的 error handler 变成异常。需加返回类型或 `#[\ReturnTypeWillChange]`。
- `src/SwooleHttpd.php:209` / `:213` 用了 `DATE(DATE_ATOM)` —— PHP 常量名大小写不敏感所以能跑，但风格是历史遗留（examples 里也有 `DATE(DATE_ATOM)`）。
- `src/SwooleHttpd.php:607-612` `send_file()` 用 `mime_content_type()` + `$response->sendfile()`，**绕过了 `system_wrapper`**。而新版 DuckPhp 恰好把 `mime_content_type` 也列进了 provider 表（§4.5），对接时要统一。
- `SwooleHttpd::is_shutdown`（`:80`）**没有任何代码把它置为 `true`**，所以 `checkShutdown()`（`:231-237`）的优雅停机逻辑是空转的。

### 3.3 L3 Phantom API：文档/测试引用了**不存在**的东西（实测）

这是"这个库感觉能跑但实际不能"的最大来源。以下全部用 `method_exists` / `class_exists` 实测为 `MISSING`：

#### 不存在的类

| 类 | 谁在引用 | git 里的下场 |
|---|---|---|
| `SwooleHttpd\SwooleExt` | `tests/SwooleExtTest.php:3,9,13` | 2021-03-27 提交 `04c5447` 删除 `src/SwooleExt.php` |
| `SwooleHttpd\SwooleExtAppInterface` | `tests/SwooleExtAppInterfaceTest.php:3,9,13` | 同上传删除 `src/SwooleExtAppInterface.php` |
| `SwooleHttpd\SwooleExtServerInterface` | `tests/SwooleExtServerInterfaceTest.php:3,9,13`；`src/SwooleHttpd.php:21,29`（被注释掉） | 同上传删除 `src/SwooleExtServerInterface.php` |
| `SwooleHttpd\Swoole404Exception` | `tests/Swoole404ExceptionTest.php:3,9,13`；`README.md:346`；`doc/swoolehttpd.gv:27,44` | 同上传删除 `src/Swoole404Exception.php` |
| `DuckPhp\HttpServer\HttpServer`（旧的） | `src/SwooleHttpd.php` 之外，`git show c120c68^:src/ServerForDuckPhp.php` 引用 | 2021-03-27 提交 `c120c68` 删除 |

#### 不存在的 `SwooleHttpd` 方法

| 方法 | 出处 | 备注 |
|---|---|---|
| `SG()` | `README`、`examples/session.php:17,31`、`tests/SwooleHttpdTest.php:15` | **最常被引用**。语义应是"拿 SuperGlobal 单例"（对照 `SwooleHttpd::Request()` 的实现风格，`src/SwooleHttpd.php:323-330`） |
| `ThrowOn($flag,$msg,$code=0)` | `README.md:134-138`、`tests/SwooleHttpdTest.php:35` | 文档还专门解释"和 DuckPhp 不同，这里抛 SwooleException" |
| `Throw404()` | `tests/SwooleHttpdTest.php:34` | |
| `exit_request($code=0)` | `README.md:308-310`、`tests/SwooleHttpdTest.php:33` | 实际存在的是 `_exit($code)`（`src/SwooleHttpd.php:97-100`） |
| `exit_system($code=0)` | `tests/SwooleHttpdTest.php:68` | 命名空间/命名演进残留（现叫 `exit`，`:376-379`） |
| `set_http_404_handler($cb)` | `tests/SwooleHttpdTest.php:31`、`tests/SwooleExtServerInterfaceTest.php:24` | 只有 `set_http_exception_handler`（`:101-104`）存在；404 只能靠 `http_404_handler` 选项 |
| `getStaticComponentClasses()` | `tests/SwooleHttpdTest.php:77`、`tests/SwooleExtAppInterfaceTest.php:20` | 只有 `getDynamicComponentClasses()` 存在 |
| `forkMasterClassesToNewInstances()` | `tests/SwooleHttpdTest.php:80`、`tests/SwooleExtServerInterfaceTest.php:26` | 实际存在的是 `SwooleCoroutineSingleton::forkAllMasterClasses()`（`src/SwooleCoroutineSingleton.php:155-164`） |
| `checkOverride($options)` | `tests/SwooleHttpdTest.php:45` | |
| `includeHttpPhpFile(...)` | `tests/SwooleHttpdTest.php:41` | 实际存在的是 `runPhpFile()`（`:594-606`） |

> 这些"phantom"几乎全部来自 `tests/bootstrap.php:163-248` 的 `TestFileGenerator` —— 它会**扫 `src/*.php` 的源码文本、用正则抓 `function xxx(...)` 签名，然后生成测试骨架**。所以你看到的 `tests/*Test.php` **不是人手写的测试，而是自动生成的占位壳**（里面的方法调用全在 `/* */` 注释块里，且明显是跨多次生成累积的、命名空间已经变了的旧签名）。
> **含义**：这套测试**从来没有真正断言过任何东西**，看起来"有测试"是假象。后续 AI 不要把它当成需求规格来读。

### 3.4 文档与代码不一致（会对齐时踩坑）

| README 说 | 代码实际 | 位置 |
|---|---|---|
| `'swoole_options' => []` | `'swoole_server_options' => []` | README:67 vs `src/SwooleHttpd.php:46,154` |
| `'enable_not_php_file' => true` | `'enable_resource_file' => true` | README:84 vs `:61,589` |
| `'swoole_server'=>null`（可注入 server 对象） | **无此选项**，且 `init()` 的 `$server` 形参被忽略 | README:66 vs `:145,191-205` |
| `'base_class'=>null` 可替换初始化 | 声明了但**从不读** | README:86 vs `:68` |
| `RunQuickly` 的默认选项叫 `SwooleHttpd::DEFAULT_OPTIONS` | **没有这个常量**（实测 `getConstants()` 只有 `VERSION`） | README:61 vs `:31,43-71` |
| `Swoole404Exception` 已实现 | 类不存在 | README:346 |
| `http_handler_file` 模式可用 | 返回 null → 必出 404 | README:122-124 vs `:505-511` |
| `DuckPhp_SUPER_GLOBAL_REPALACER` / `DuckPhp_SYSTEM_WRAPPER_INSTALLER` 两个宏 | **两个名字在新版 DuckPhp 里都不存在**（见 §4.6） | README:413-414 |
| 命名空间 `DuckPhp\SwooleHttpd` | 实际 `SwooleHttpd\` | README:249 vs `composer.json:17` |
| `WebSocket onMessage` / 无 `OnClose` | 事件名拼错（BLOCKER-2） | README:478-516 vs `:167` |

### 3.5 测试基建不可用

- `tests/bootstrap.php:1-28` 只做 `require ../autoload.php`，然后一大段 coverage 相关逻辑后 `return;`（`:28`）—— **`return` 之后的 `MyCodeCoverage` / `TestFileGenerator` 仍然定义**（PHP 中 `return` 只结束执行流，不影响后续函数/类声明被编译），但 `ini_get('tests.report')` 这个 ini 没人设置，`TestFileGenerator::Run()` 不会自动跑。
- `tests/support.php`、`tests/*Test.php` 全部依赖 `PHPUnit\Framework\TestCase` 与 `\MyCodeCoverage` → **phpunit 没装 → 全都跑不了**。
- `phpunit.xml:3-12` 用的是 **PHPUnit 8/9 时代的属性**（`backupStaticAttributes`、`convertErrorsToExceptions`、`convertNoticesToExceptions`、`<filter><whitelist>`），在 PHPUnit 10+ 已移除/改名；且 `processIsolation="true"` 对 Swoole 场景是自杀式配置（每测试起一个进程，无法测常驻服务器）。
- `phpunit.xml:17-19` 把 `tests/support.php` 作为一个 test suite —— 这个文件的 `_testCreateTests()` 会**写文件到 `tests/`**，副作用很大。
- `tests/duckphp.php:7` `require __DIR__.'/../../DNMVCS/autoload.php'` → 解析为 `E:\ProjectGoat\DNMVCS\autoload.php` **存在 ✅**；
  但 `:8` `chdir(realpath(__DIR__.'/../../DNMVCS/template/'))` → `E:\ProjectGoat\DNMVCS\template` **不存在 ❌**（实测 `Test-Path` → `False`），`:10` `require 'duckphp-project'` 必失败。
  （`E:\ProjectGoat\DNMVCS\skeleton` 倒是存在。）
- `.php_cs` 是 **php-cs-fixer 2.x** 配置（用了已移除的 `PhpCsFixer\Config::create()`），在新版 php-cs-fixer 下直接报错。

### 3.6 一个重要的"考古发现"：被删掉的 DuckPhp 适配层

提交 `04c5447`（2021-03-27"删除不必要文件"）和 `c120c68`（同日"总之运行起来了就是"）删掉了 5 个文件。
**它们是可恢复的**，而且**是理解"原设计者想怎么对接 DuckPhp"的第一手资料**：

```bash
git show 04c5447^:src/SwooleExt.php
git show 04c5447^:src/SwooleExtAppInterface.php
git show 04c5447^:src/SwooleExtServerInterface.php
git show 04c5447^:src/Swoole404Exception.php
git show c120c68^:src/ServerForDuckPhp.php
git show aee0fee^:src/SwooleHttpd_SimpleHttpd.php   # 2019 年拆分的 7 个 trait 文件
```

要点（我已读过内容）：

- **`SwooleExtServerInterface`** 规定了"服务器侧"能力：
  `G($object)` / `ReplaceDefaultSingletonHandler()` / `system_wrapper_get_providers()` / `init(array $options,$server=null)` / `run()` / `is_with_http_handler_root()` / `getDynamicComponentClasses()` / `forkMasterClassesToNewInstances()` / `forkMasterInstances($classes,$exclude=[])`。
- **`SwooleExtAppInterface`** 规定了"应用侧（DuckPhp）"能力：
  `G($object)` / `run()` / `onSwooleHttpdInit(SwooleHttpd,$RunHandler=null)` / `onSwooleHttpdStart(SwooleHttpd)` / `onSwooleHttpdRequest(SwooleHttpd)` / `getDynamicComponentClasses()` / `getStaticComponentClasses()`。
- **`SwooleExt`** 是核心胶水：在 `PHP_SAPI === 'cli'` 且 `Coroutine::getuid() > 0`（说明在子协程）时走 `onSwooleHttpdRequest`；
  在主进程时 `replaceInstances()`（**把 master 单例先 `::G()` 存快照，再定义 `__SINGLETONEX_REPALACER`，然后重新 `::G($object)` 灌回去** —— 这是"绕过宏定义时机"的巧妙手法），
  并把 `http_handler` 设成 `[static::class,'RunSwoole']`，最后抛 `Exception('run break;',500)` 中断主流程。
- **`ServerForDuckPhp.php` 里有明显的复制粘贴未完成痕迹**：引用了 `DuckPhp\HttpServer\HttpServer`、`DuckPhp\Core\App`，但里面出现 `WorkermanHttpd404Exception`（隔壁 WorkermanHttpd 项目的类），且 `_OnServerRequest()` 里 `if (!$flag)` 的 `$flag` **从未赋值**。
  → **这个文件是半成品，被删是合理的。不要试图直接恢复它**，但可以当设计参考。

**含义**：原设计者走的是"**扩展 DuckPhp 的应用类，让它暴露 `getDynamicComponentClasses()` 等钩子**"（即"侵入式改造 DuckPhp"）这条路。
而**新版 DuckPhp 明确拒绝了这条路**，改成了 `HttpServerInterface` 的"启动器替换"模型（§4.2）。**这是本备忘最重要的一处转折。**

---

## 4. 配套的新版 DuckPhp（必须先读懂这章）

### 4.1 位置、版本、形态

| 项 | 值 |
|---|---|
| 路径 | `E:\ProjectGoat\DNMVCS`（**目录名叫 DNMVCS，但包名是 `dvaknheo/duckphp`**） |
| 版本 | **1.4.1**（`composer.json` 的 `"version": "1.4.1"`） |
| 命名空间 | `DuckPhp\` → `src/`（PSR-4） |
| PHP 要求 | `>= 7.4.0` |
| 入口 | `DuckPhp\DuckPhp`（`extends DuckPhp\Core\App`）、`DuckPhp\DuckPhpAllInOne` |
| 自动加载 | `E:\ProjectGoat\DNMVCS\autoload.php`（`require src/Core/AutoLoader.php` + `spl_autoload_register`） |
| 同一版本的已发布副本 | `E:\ProjectGoat\DuckAdmin\vendor\dvaknheo\duckphp`（也是 1.4.1，可作只读对照） |
| 分支 | `master` 外还有大量历史分支（`231019-新思路分支` 等），**但 user 说的"开发中的新版本"= 这个 master 工作区** |
| 文档 | `docs/zh/guide/*.md`（用户手册）、`docs/zh/reference/*.md`（API 参考）—— **写得非常全，优先查文档再读代码** |

`swoolehttpd/tests/duckphp.php:7` 指向 `../../DNMVCS/autoload.php`，**这证明了两个仓库的配套关系是原设计者的意图**。

### 4.2 ⭐ 官方插件契约：`HttpServerInterface` + `--http_server`

**这是本库应该实现的东西。**

`DNMVCS/src/HttpServer/HttpServerInterface.php`（全文 16 行）：
```php
namespace DuckPhp\HttpServer;

interface HttpServerInterface
{
    public static function RunQuickly($options);   // :12
    public function run();                         // :13
    public function getPid();                      // :14
    public function close();                       // :15
}
```
只有 **4 个方法**。文档 `docs/zh/reference/HttpServer-HttpServerInterface.md` 与 `docs/zh/guide/http-server.md:146` 都重申了这 4 个。

**注意**：内置的 `DuckPhp\HttpServer\HttpServer`（`DNMVCS/src/HttpServer/HttpServer.php:9`）**并没有 `implements HttpServerInterface`**，它只是碰巧有同名方法。
这个 interface 目前在整个 DuckPhp 里**没有任何实现者** —— 它就是为 `swoolehttpd` / `workermanhttpd` 这类外部包准备的。

**官方的插拔机制**（两处，逻辑相同）：

`DNMVCS/src/Component/Command.php:47-60`（CLI `duckphp run` 命令）：
```php
public function command_run()
{
    $cli_options = Console::_()->getCliParameters();              // :49
    $cli_options['http_app_class'] = get_class($this->context()); // :50  ← 应用类名
    $cli_options['path'] = $this->context()->options['path'];     // :51  ← 项目根
    if (!empty($cli_options['http_server'])) {                    // :52
        $class = str_replace('/', '\\', $cli_options['http_server']); // :54
        HttpServer::_($class::_());                               // :55  ← 替换单例
    }
    $this->context()->options['cli_enable'] = false;              // :57  ← 关键！见 §4.4
    HttpServer::RunQuickly($cli_options);                         // :58
    $this->context()->options['cli_enable'] = true;               // :59
}
```
`DNMVCS/src/Ext/DuckPhpInstaller.php:127-132`（`runDemo()`）是同一套写法。

用法（文档 `docs/zh/guide/http-server.md:138-146`）：
```bash
php cli.php run --http_server=MyProj/Http/MyServer
```

**替换能生效的原理**（很微妙，务必理解）：
`HttpServer::RunQuickly($options)` 的实现是 `return static::_()->init($options)->run();`（`HttpServer.php:97-100`）。
它内部调用的 `static::_()` 会拿到**已经被替换掉的那个实例**（因为 `HttpServer::_($class::_())` 塞进了 `HttpServer` 的单例槽），
所以后续 `->init()->run()` 实际执行的是**你的类的实现**。

**因此你的服务器类必须提供**（方法名/签名与 `HttpServer` 对齐）：
1. `public static function _($object = null)` —— 单例访问器。**建议直接照抄 `HttpServer.php:77-93`**，它同时支持 `__SINGLETONEX_REPALACER` 宏。
2. `public static function RunQuickly($options)` —— 建议 `return static::_()->init($options)->run();`
3. `public function init(array $options, ?object $context = null)` —— 注意新版签名是 `?object $context`，旧库是 `$server = null`，**语义不同**。
4. `public function run()` —— 启服务并阻塞。
5. `public function getPid(): int` / `public function close()` —— 配套 `run()` 的进程管理（`--background` / `--command stop`）。

另外建议 `implements DuckPhp\HttpServer\HttpServerInterface` —— 这是文档承诺的契约，虽然框架不检查，但能表达意图。

**构造函数/CLI 解析可复用**：`HttpServer::parseCaptures()`（`HttpServer.php:139-167`）把 `host/port/docroot/dry/background/help` 解析进 `$this->args`，可参考或直接继承 `HttpServer` 复用 `init()`（这也是 DNMVCS 自己测试里的做法：`DNMVCS/tests/HttpServer/HttpServerTest.php:39` `class HttpServerParent extends HttpServer`）。

### 4.3 服务器会收到什么 options

从 `command_run` 传来的 `$cli_options` 至少包含：

| key | 含义 |
|---|---|
| `http_app_class` | **应用类名的完整 FQCN 字符串**（`Command.php:50`）。**本库已有同名选项，语义一致**（`src/SwooleHttpd.php:48`）✅ |
| `path` | 项目根目录（`Command.php:51`） |
| `host` / `port` | CLI `-H/--host`、`-P/--port` 或默认 `127.0.0.1:8080`（`HttpServer.php:11-20,35-44`） |
| `path_document` | 默认 `'public'`（`HttpServer.php:15`），静态资源目录名 |
| `workers` | 非空则多进程（`HttpServer.php:16,251-254`） |
| `background` / `b` | 后台运行（`HttpServer.php:27-29,244-247`） |
| `dry` | 只打印命令不执行（`HttpServer.php:50-52`） |
| `help` / `h` | 打印帮助 |
| `docroot` / `t` | 文档根 |

> ⚠️ 注意 `path_document` / `workers` / `background` / `dry` 是 **`HttpServer` 自己的选项**，`command_run` 传给 `RunQuickly` 的是**扁平的一份 `$cli_options`**，所以你的类必须容忍不认识的 key。

### 4.4 ⚠️ 最大的坑：`cli_enable` 与 `PHP_SAPI === 'cli'`

Swoole 下 `PHP_SAPI` 就是 `'cli'`。而新版 DuckPhp 有多处用 `PHP_SAPI` 或 `cli_enable` 判断"我是不是在命令行"：

| 位置 | 代码 | 后果 |
|---|---|---|
| `KernelTrait.php:89-100` | `RunQuickly()`：`if (PHP_SAPI==='cli' && isRoot() && options['cli_enable']) return execute(); else return serve();` | **在 Swoole 里调 `$app::RunQuickly()` 会走控制台分支**（除非 `cli_enable=false`） |
| `KernelTrait.php:468-475` | `run()`：`if (options['cli_enable']) return execute(); else return serve();` | 同上。**`cli_enable` 默认是 `true`**（`KernelTrait.php:36`） |
| `KernelTrait.php:310` | `$this->is_cli = (PHP_SAPI==='cli') && options['cli_enable'];` | 常驻进程里 `is_cli` 恒为 `true`（除非关掉 `cli_enable`） |
| `SystemWrapper.php:142` | `_header()`：`if (PHP_SAPI==='cli') return;` | **没装 system wrapper 时，所有 header 静默丢弃** |
| `Lang.php:240` | `if (PHP_SAPI !== 'cli')` | 语言探测行为分支 |

**结论与要求**：
1. **你的服务器必须显式把 `cli_enable` 设为 `false`**（或直接调 `serve()`，见 §4.4 下条），**绝不能盲目调 `run()`**。
2. 好消息：`command_run` 已经在 `:57` 设了 `cli_enable = false`。**但如果你让用户绕过 CLI 直接 `SwooleHttpd::RunQuickly()` 启动**（本库的 examples 就是这种用法），**这个保护不存在**，必须自己设。

### 4.5 ⭐ 单请求入口是 `serve()`，不是 `run()`

`DNMVCS/src/Core/KernelTrait.php:476-500`（**逐字**）：
```php
public function serve(): bool
{
    $ret = false;
    $this->prepareServe();
    $this->onRequest();
    try {
        Runtime::_()->run();
        $ret = Route::_()->run();
        if (!$ret) {
            $ret = $this->runChildren();
        }
        $this->phaseToCurrent();
        if (!$ret) {
            $this->_On404();
        }
    } catch (\Throwable $ex) {
        $this->runException($ex);
        $ret = true;
    } finally {
        $this->phaseToCurrent();
        Route::_()->clear();
        Runtime::_()->clear();
    }
    return $ret;
}
```

**每请求由框架负责的清理只有**：`Route::_()->clear()` + `Runtime::_()->clear()`（`finally` 里）。

`_On404()`（`App.php:209-236`）的关键点：
- `:211` `if (!$this->is_root || ($this->options['skip_404'] ?? false)) return;` —— **选项名是 `skip_404`，不是 `skip_404_handler`**。
- `:217` 用 `SystemWrapper::_()->_header('HTTP/1.1 404 Not Found', true, 404)`。
- `:214` `error_404` 选项可以是 callable（自定义 404），`:250` `error_500` 同理。
- 想自己接管 404：调 `$app->skip404Handler()`（`App.php:415-418`，它设 `options['skip_404']=true`），然后看 `serve()` 的返回值自己处理。

### 4.6 ⭐ 旧 API → 新 API 对照表（**逐项 grep 验证过**）

| 旧 SwooleHttpd 用的 | 新版 DuckPhp 现状 | 证据 |
|---|---|---|
| `$app::G()` / `static::G()` | **不存在**。改成 `$app::_()`（`SingletonExTrait::_()`） | 全 `src/` 无 `function G(`；`Core/SingletonExTrait.php:16-19` |
| `$app::G()->options['skip_404_handler']=true` | **不存在**。改 `options['skip_404']` 或 `$app->skip404Handler()` | `Core/App.php:54,211,415-418` |
| `$app::assignExceptionHandler(...)` | **App 上没有这个静态方法**。改 `ExceptionManager::_()->assignExceptionHandler($class,$cb)`；静态便捷入口只在 `ControllerHelper` | `Core/ExceptionManager.php:53-59`；`Foundation/Controller/ControllerHelper.php:149-151` |
| `$app::system_wrapper_replace(...)` | **App 上没有**。改 `SystemWrapper::system_wrapper_replace(array $funcs)`（或 `SystemHelper::system_wrapper_replace`） | `Core/SystemWrapper.php:31-46`；`Foundation/System/SystemHelper.php:94-96` |
| `$app::system_wrapper_get_providers()` | **App 上没有**。改 `SystemWrapper::system_wrapper_get_providers(): array` | `Core/SystemWrapper.php:35-38,53-62` |
| `$app::G()->getDynamicComponentClasses()` | **不存在**（全 `src/` 无此名） | grep 零命中 |
| `$app::G()->getStaticComponentClasses()` | **不存在** | grep 零命中 |
| `forkMasterInstances($classes,$exclude)` | **DuckPhp 从来没有过**（那是本库自己的方法）。最接近的是 `PhaseContainer::RestAllContainerForTesting()` 或逐类 `Class::_($newInstance)` 注入 | `Core/PhaseContainer.php:33-36` |
| `resetInstances()` / `replaceSingletons()` | **不存在**（从未有过） | grep 零命中 |
| `$app::On404()` | ✅ **仍然存在** | `Core/KernelTrait.php:553-556` |
| `$app::OnException($ex)` | **App 上没有**。用 `ExceptionManager::CallException($ex)`；或 `ControllerHelper` 层的 `ExceptionReporterTrait::OnException()` | grep 见 §4.6 注 |
| 宏 `__SUPERGLOBAL_CONTEXT` | ✅ **仍然存在**，但值从 `::G` 变成 **`::_`** | `Core/SuperGlobal.php:38-45` |
| 宏 `__SINGLETONEX_REPALACER` | ⚠️ **只在 `HttpServer::_()` 里被消费**；决定性 `SingletonExTrait::_()` **不看它** | `HttpServer/HttpServer.php:79-80` vs `Core/SingletonExTrait.php:16-19` |
| 宏 `__SYSTEM_WRAPPER_REPLACER` | ✅ 消费方存在（`SystemWrapper.php:66,77`），**DuckPhp 自己从不定义**，由你定义 | 同上 |
| 宏 `__SYSTEM_WRAPPER_INSTALLER` | ❌ **不存在**（README:414 提的是这个旧名） | grep 零命中 |
| 宏 `__SUPER_GLOBAL_REPALACER` | ❌ **不存在**（README:413 提的是这个旧名） | grep 零命中 |
| 宏 `__EXIT_EXCEPTION` | ✅ 存在（`use_exit_exception` 默认 true 时定义），值 `DuckPhp\Core\ExitException::class` | `Core/KernelTrait.php:285-289`；`Core/ExitException.php:12-17` |
| `__EXCEPTION_HANDLER` / `__VIEW` / `__RUN` | ❌ 均不存在 | grep 零命中 |
| 选项 `http_app_class` | DuckPhp **不读**它，但 `command_run` 会**产出**它给服务器用 | `Component/Command.php:50` |

### 4.7 `SystemWrapper` 的 provider 表（对接必须逐项覆盖）

`DNMVCS/src/Core/SystemWrapper.php:12-25` —— **10 个 key**：
```php
'header' => null,
'setcookie' => null,
'exit' => null,
'set_exception_handler' => null,
'register_shutdown_function' => null,
'session_start' => null,
'session_id' => null,          // ← 本库的 system_wrapper_get_providers() 缺这个
'session_destroy' => null,
'session_set_save_handler' => null,
'mime_content_type' => null,   // ← 本库也缺这个
```

替换方式：`SystemWrapper::system_wrapper_replace($funcs)`（`array_replace`，`SystemWrapper.php:42-46`）。
**本库 `system_wrapper_get_providers()`（`src/SwooleHttpd.php:402-415`）只提供 8 个 key**，
缺 `session_id` 和 `mime_content_type`，**必须补齐**，否则 `session_id()` / `mime_content_type()` 会落到原生实现上（Swoole 下行为不对）。

dispatch 逻辑（`SystemWrapper.php:63-88`）：
- 若定义了宏 `__SYSTEM_WRAPPER_REPLACER`（值为**类名字符串**），**所有**系统调用都走 `[宏, $func](...$args)`，`$system_handlers` 被完全忽略。
- 否则走 `$system_handlers[$func]`，没有则调原生函数（`!is_callable($func)` 时抛 `\ErrorException`）。

两个必须注意的细节：
- `_header()`（`:135-153`）：**先查 wrapper，再判 `PHP_SAPI==='cli'`**（`:142` 直接 return）。所以只要 wrapper 装上了，CLI SAPI 就不是问题。
- `_exit()`（`:162-172`）：若 `__EXIT_EXCEPTION` 已定义且是 Throwable，**抛异常而不是 `exit()`**。Swoole 里这是刚需（`exit` 会杀掉整个 worker）。

### 4.8 异常机制（决定你怎么写 try/catch）

- `ExceptionManager::run()`（`Core/ExceptionManager.php:119-138`）在 init 时**安装进程级全局** `set_error_handler([$this,'on_error_handler'])`（`:126`）和 `set_exception_handler([static::class,'CallException'])`（`:135`）。
  → **Swoole 下这是全局的，所有协程共用**。一个协程的错误会走到"当时 current 的那个 PhaseContainer"，这在多协程下会串（§5.3）。
  → 如果想换掉：使用 `options['system_exception_handler']` 回调（`:130-131`，它接收 `[$this,'_CallException']` 并负责自己注册）。
- `on_error_handler`（`:69-87`）：**NOTICE/DEPRECATED 交给 `dev_error_handler`；其他一切都被转成 `\ErrorException` 抛出**。
  → 所以本库那些 warning 级问题（§3.2 LATENT-3、`SessionHandlerInterface` 返回类型）在 DuckPhp 环境里会**升级成异常**，必须提前修掉。
- `CallException()` / `_CallException()`（`:45-48, 88-104`）：**`__EXIT_EXCEPTION` 直接被 `return`（静默放行）**。
- `serve()` 内部 catch 一切 `\Throwable` 交给 `runException()`（`KernelTrait.php:508-520`），所以 **DuckPhp 会把你服务器抛出的东西全部吃掉**。
  除非设 `options['skip_exception_check']=true`（`KernelTrait.php:37,512-514`）→ 它会重新 `throw`。
  → **你的服务器若想在协程里感知错误以便关闭响应，需要靠 `skip_exception_check` 或自己的 wrapper 回调。**

### 4.9 超全局：这是**最干净的**接入点 ✅

`DNMVCS/src/Core/SuperGlobal.php:145-154`：
```php
protected function getSuperGlobalData(string $superglobal_key, ?string $key, $default)
{
    $data = defined('__SUPERGLOBAL_CONTEXT')
        ? (__SUPERGLOBAL_CONTEXT)()->$superglobal_key
        : ($GLOBALS[$superglobal_key] ?? []);
    ...
}
```
全框架约 **25 处**读 `(__SUPERGLOBAL_CONTEXT)()->_SERVER` 等（`KernelTrait.php:158`、`App.php:224`、`CoreHelper.php:215`、`Route.php:88/348/389/395/407/549/569`、`Logger.php:55`、`Pager.php:37/42`、`Lang.php:177/190/200`、`MiniRoute.php`、`RouteHook*` 系列、`Console.php:90`、`Component/Command.php:71` …）。

**只要你的类在 DuckPhp 初始化之前 define 了 `__SUPERGLOBAL_CONTEXT`，并把值设成你自己的 `静态方法` 字符串，DuckPhp 的全部超全局读取就会走你的实现** —— 包括 `_SESSION` 的读（`_SessionGet`/`getSuperGlobalData`）和写（`_SessionSet` `:184-191`、`_SessionUnset` `:192-198`）。

**要求**：你的类必须有 **public** 属性 `_GET _POST _REQUEST _SERVER _COOKIE _SESSION _FILES`，且有可被字符串调用的静态访问器。
`SwooleHttpd\SwooleSuperGlobal`（`src/SwooleSuperGlobal.php:16-23`）**正好满足这两个条件** ✅。
→ **唯一需要改的是宏的值**：本库现在 define 成 `static::class.'::G'`（`src/SwooleSuperGlobal.php:84`），而 DuckPhp 期望 `::_`（`SuperGlobal.php:41`）。
但因为宏的值是**你自己 define 的整串字符串**，`::G` 也能用 —— DuckPhp 只做 `(__SUPERGLOBAL_CONTEXT)()` 调用，不关心方法名。
**所以这一层可以零改动复用，只要保证 SwooleHttpd 比 DuckPhp 更早 define。**

> ⚠️ 顺序陷阱：`SuperGlobal::initOptions()`（`:29-36`）在 `superglobal_auto_define` 为 true 时会自己 define。默认是 `false`（`:12`），所以默认情况下**DuckPhp 不会抢**。
> 但如果用户开了 `superglobal_auto_define`，而 SwooleHttpd 又 define 得晚，`define()` 会失败（第二次 define 报 warning）。**务必在 app init 之前 define。**

---

## 5. 核心难点：真正要解决的四件事

这一章是"修 bug 不够、必须做设计决策"的部分。**建议先把 §7 的 1-3 步做完再来啃这里。**

### 5.1 ⚠️ 头号难题：`PhaseContainer` 是进程级全局静态 → 没有协程隔离

`DNMVCS/src/Core/PhaseContainer.php:11`
```php
public static $instance;      // ← 一个进程只有一份
```
所有组件的"单例"都从这里取：
```php
// Core/SingletonExTrait.php:16-19
public static function _($object = null)
{
    return PhaseContainer::GetObject(static::class, $object);
}
// Core/PhaseContainer.php:18-21
public static function GetObject(string $class, ?object $object = null)
{
    return static::_()->_GetObject($class, $object);
}
```
内部结构（`:13-16, 42-58, 80-105`）：`containers[$phase][$class]` + `publics[]` 指向 `default` 容器（`#public` 共享区）。

**问题**：`Route::_()`、`Runtime::_()`、`View::_()`、`SuperGlobal::_()`、`DbManager::_()`、`ExceptionManager::_()`、应用实例本身（`initContainer()` 里的 `(self::class)::_($this)`，`KernelTrait.php:260-261`）**全部存在这一个静态里**。
Swoole 常驻 + 多协程 → **所有协程共享同一批对象和同一份 `Route`/`Runtime` 状态** → 请求间串数据。

**原设计者知道这件事**：`KernelTrait.php:236` 有一行**被注释掉的**
```php
//$flag = PhaseContainer::ReplaceSingletonImplement();
```
—— 说明曾经有过"可替换单例实现"的钩子，但已被移除/未实现。

**✅ 已裁定（用户，2026-09-24）：用「override PhaseContainer」的方式解决** —— 从外部把 `PhaseContainer::$instance` 换成一个协程感知的**子类**，**不动 DuckPhp 的核心语义**。
具体做法、可覆写的完整表面、以及 **4 个必须处理的坑**，全部写在 **§12**（必读）。

### 5.2 ⚠️ `prepareServe()` 是空操作 → 框架没有任何每请求重置

`DNMVCS/src/Core/KernelTrait.php:501-506`（**逐字**）：
```php
protected function prepareServe()
{
    $this->phaseToCurrent();
    $classes = [];                                          // :504  ← 空数组
    $this->initComponentsOfDynmic($classes, self::$EXT_RENEW); // :505
}
```
`initComponentsOfDynmic()` → `initComponentsByClasseOptions([], EXT_RENEW)` → `array_filter([])` = `[]` → **循环体一次都不执行**（`:360-373`）。

**即：`serve()` 里没有任何组件被 renew。** 所谓 `EXT_RENEW`（`KernelTrait.php:76` → `initExtensionsByOptions()` `:398-403` 会 `$class::_(new $replaced_class)->init(...)`）这条机制**存在但没被使用**。

> ### ✅ 更正（用户，2026-09-24）：`$classes = []` 是**有意设计**
> 用户明确说：**这不是笔误，是"给其他实现看"的扩展点。**
> 即：框架把 `prepareServe()` 留成这个样子，是为了让**别的服务器实现**（= 本库）自己 override 它、把"我需要每请求 renew 的动态组件列表"填进去。
> 所以后续 AI **不要**去"修" DuckPhp 的这个方法。
> **✅ 最终裁定（§12.6）：本库走"路径 3" —— 不覆写 `prepareServe()`，每请求准备/重建全部挂在 `options['on_request']` 里。**
> （`$classes = []` 仍是"留给其他实现看的示范位"，只是**本库选择不用它**。）
> `prepareServe()` 是 `protected`（`KernelTrait.php:501`），所以拿覆写权的办法见 **§12.6**。
> 每请求的业务钩子则用 `options['on_request']`（见 **§12.1-3**）。
>
> 下面这段"框架不保证多请求状态干净"的解释**依然成立**（它只是说明了为什么框架把这件事交给实现方）：

这解释了 DuckPhp 文档 `docs/zh/guide/http-server.md` 开头为什么要专门讲"**一个进程处理多个请求时哪些状态会残留**"：
**框架自己承认它不保证多请求进程的状态干净** —— 因为内置 `HttpServer` 走的是 `php -S`，每个请求是独立进程，所以掩盖了这个问题。

**给后续 AI 的直接结论**：不要指望框架"自动"帮你重置 —— **重置是服务器实现方的责任**（这正是 `$classes` 留空的本意）。**已裁定：写在 `options['on_request']` 回调里**（§12.6）：
```php
Route::_()->clear();
Runtime::_()->clear();
// 视方案还要处理：SuperGlobal、StaticReplacer、DbManager、View、Logger、ExceptionManager...
```
**而且 `Route::_()->clear()` 本身在并发下就不安全**（它是共享对象）—— 只有把它放进**协程私有的容器**（§12）里才安全。这是 §5.1 的必然推论。

### 5.3 全局 error/exception handler 在协程模型下是错的

见 §4.8：`ExceptionManager::run()` 装的是**进程级** `set_error_handler` / `set_exception_handler`（`ExceptionManager.php:126,135`）。
这些 handler 内部落回 `static::_()->...` → `PhaseContainer::$instance` → 谁在那一刻是 current 就用谁。
多协程并发时，A 协程的 warning 可能被投递到 B 协程的容器上。

**应对**：
- 请求协程里把 PHP 的 error/exception handler **临时换成协程局部的**（Swoole 的 handler 也是全局的，所以"临时换"在并发下仍然不可靠）；
- 更实际的做法：`options['skip_exception_check']=true` 让 `serve()` 重新抛出（`KernelTrait.php:512-514`），然后在**你自己的** `try/catch` 里处理；
- 或者用 `options['system_exception_handler']`（`ExceptionManager.php:130-131`）接管注册流程。

**这一条在 §12 的容器方案里怎么处理**：因为 `ExceptionManager::_()` 也是容器里的一个实例，per-cid 容器会让"该用谁"变得确定；但 `set_error_handler`/`set_exception_handler` 本身仍是**进程级**的，所以协程并发的正确性依赖于"注册时拿到的那个实例"是否已按 cid 绑定 —— 具体见 §12.7（**待确认项**）。

### 5.4 ✅ 超全局反而是最干净的（见 §4.9）

已经分析完：define `__SUPERGLOBAL_CONTEXT` + 一个 per-request 的 store，就能让全框架的路由/语言/分页/cookie/session 读取走协程局部数据。
**这部分不需要改 DuckPhp。** 本库的 `SwooleSuperGlobal` 形状已经对上了。

需要补的：
- **`$_SERVER` 里必须提供的 key**（逐个查证过读取点，缺一个就有一块功能静默失灵）：

  | key | 谁在读 | 用途 |
  |---|---|---|
  | `PATH_INFO` | `App.php:225`、`Route`（`Route.php` 路由分发）、`RouteHookPathInfoCompat.php:46,112` | 路由主输入 |
  | `REQUEST_URI` | `Route.php:393`、`RouteHookDirectoryMode.php:39`、`RouteHookPathInfoCompat.php:47`、`Pager.php:38` | 路由 + 分页链接 |
  | `REQUEST_METHOD` | `Route.php:349`、`Foundation/Controller/ControllerHelper.php:176` | POST 判定 / `action_do_` 前缀 |
  | `REQUEST_SCHEME`、`HTTP_HOST`、`SERVER_NAME`、`SERVER_ADDR`、`SERVER_PORT` | `Route::_Domain()`（`Route.php:549-556`） | **生成绝对 URL**（跳转、资源链接），缺了就退化成 `http:///` |
  | `DOCUMENT_ROOT` | `Route::getUrlBasePath()`（`Route.php:569-572`，会 `realpath()`） | **URL base path 计算**；注意这里会做 `realpath()`，Swoole 下要给真实存在的目录 |
  | `SCRIPT_FILENAME` | `KernelTrait::getDefaultProjectPath()`（`:156-164`） | 反推项目根（**建议改为直接传 `path` 选项，摆脱这个依赖**） |
  | `HTTP_X_REQUESTED_WITH` | `CoreHelper::_IsAjax()`（`CoreHelper.php:215-218`） | ajax 判定 |
  | `HTTP_*`（全部请求头） | `SwooleSuperGlobal::init()`（`src/SwooleSuperGlobal.php:63-66`）已做 `HTTP_` 转换 ✅ | 通用 |
  | `REQUEST_URI`（`RouteHookResource.php:94`） | 静态资源路由钩子 | 资源文件分发 |
- 注意 `KernelTrait::getDefaultProjectPath()`（`:156-164`）用 `$_SERVER['SCRIPT_FILENAME']` 反推项目根。
  **Swoole 的 `$request->server` 里没有这个东西的语义**。
  本库旧代码是靠 `_OnServerRequest()` 手动塞（`src/SwooleHttpd.php:461-462`，从 `$app::G()->options['path']` 取），**这个思路是对的，保留**；
  但更好的做法是**直接在 options 里传 `path`**，避免依赖 `SCRIPT_FILENAME`。
- 修掉 §3.2 LATENT-2 的 `REQUEST_URI` 重复拼 query —— 它会直接破坏路由。

### 5.5 ⚠️ 输出缓冲：`ob_start` → `response->end()` 只能成功一次

本库的魔术（`src/SwooleHttpd.php:258-265`）：
```php
ob_start(function ($str) {
    if ('' === $str) { return; }
    SwooleContext::G()->response->end($str);   // ← 每次 flush 都调 end()
});
```
`response->end()` 在 Swoole 里**只能调一次**（第二次会报 "Http response is already sent"）。
而这个回调会在**每次 ob 层被 flush** 时触发，包括：
- `ob_end_flush()`（defer 里那个循环，`:244-252`）；
- 输出量超过 `output_buffering` / `ob_start` 的 chunk size 时的自动 flush；
- `ob_flush()` / `flush()`。

→ **大响应体（超过 chunk size）或用户手动 flush 时，会调 `end()` 两次 → 报错/响应截断。**

同时还要和 DuckPhp 自己的输出缓冲共存：`Runtime::_()->run()`（`Runtime.php:37-46`）在 `options['use_output_buffer']` 为 true 时会 `ob_start()`，`Runtime::_()->clear()`（`:47-59`）负责 flush 回 `init_ob_level`。

**建议改造**：
- 用 `$response->write()`（分块）或**累积到字符串、只在请求末尾 `end()` 一次**；
- 明确 ob 层级契约：DuckPhp 的 `Runtime` 层在内、Swoole 的捕获层在外，并确保 `Runtime::clear()` 之后才 `end()`；
- 顺带确认：**Swoole 是否对 `ob_*` 做协程隔离** ——【待验证】。如果不隔离，多协程同时 `ob_start` 会互相污染，那就必须改成"不用 ob、改用 `use_output_buffer` + 读取 buffer 字符串"的方案。

### 5.6 session 对接

链路：用户的 `SessionTrait::checkSessionStart()`（`DNMVCS/src/Foundation/Controller/SessionTrait.php:20-28`）→ `SystemWrapper::_()->_session_start()`（`:25`）
→ 走 provider `session_start` → **你的实现**。

所以要覆盖的 provider：`session_start`、`session_id`、`session_destroy`、`session_set_save_handler`（4 个，见 §4.7）。
本库已有 `SwooleContext::session_start/session_id/session_destroy/session_set_save_handler`（`src/SwooleContext.php:83-86,122-156`），
但 `SwooleHttpd::system_wrapper_get_providers()` 只暴露了其中 3 个（`src/SwooleHttpd.php:409-411`），**要补 `session_id`**。

另外必须先修 §3.2 BLOCKER-1（`Swool\Coroutine`），否则 session 一进去就崩。

数据落点：`SwooleContext` 通过 `(__SUPERGLOBAL_CONTEXT)()->_SESSION` 读写（`:141,153,166,168`），
与 §4.9 的机制天然一致 ✅。但注意 DuckPhp 会用 `session_prefix` 选项加前缀（`SessionTrait.php:27,32`），不冲突。

### 5.7 `StaticReplacer` 不在 dynamic 列表里 → 跨协程共享

`SwooleHttpd_SingletonHandle::getDynamicComponentClasses()`（`src/SwooleHttpd.php:422-428`）只返回：
```php
[SwooleSuperGlobal::class, SwooleContext::class]
```
而 `SwooleHttpd_Glue::GLOBALS()/STATICS()/CLASS_STATICS()`（`:344-355`）走的是 **`StaticReplacer::G()`**。
`StaticReplacer` 不在上面那个列表里 → `forkMasterInstances()` 不会 clone 它 → 所有协程**共享同一个 master `StaticReplacer` 实例**，
其 `$GLOBALS` / `$STATICS` / `$CLASS_STATICS` 三个数组**跨协程互相污染**。

**即：本库主打卖点之一（`GLOBALS()`/`STATICS()` 替代语法）在协程下是不安全的。**
**修复**：把 `StaticReplacer::class` 加进 `getDynamicComponentClasses()` 的返回值。

---

## 6. 开放问题（**动核心代码前必须先问**）

> **✅ 已确认（用户，2026-09-24）**：
> - **Q1 协程隔离 → 用「override PhaseContainer」的方式**（不改 DuckPhp 核心语义）；完整设计见 **§12**。
> - **Q2 `prepareServe()` 的 `$classes = []` → 是特意设计，不是 bug**。它是留给"其他实现"看的示范位，说明"这里本该由实现方填 renew 列表"。**不要改 DuckPhp**；而本库走**路径 3**：不覆写它，每请求准备挂在 `options['on_request']`（§12.6）。
> - **补充：每请求业务钩子用 `options['on_request']`**（`KernelTrait.php:45,598-603`，文档 `docs/zh/reference/Core-KernelTrait.md:39`："每次 `serve()` 开头被调"）。
>
> 以下 Q3-Q8 仍待确认；**§12.7 另有 4 个由本次裁定新产生的实现细节问题**（其中前两个会直接决定怎么写代码）。

3. **目标运行环境**：PHP 版本（7.4 还是升 8.x）？Swoole 版本（4.8 / 5.x）？是否要保留 PHP 7.4 兼容（DuckPhp 目前 `>=7.4`）？
4. **是否保留旧的 `http_handler_root` / `http_handler_file` 三种模式**，还是只做 DuckPhp 单一模式？
   （三种模式代码量大且有两个坏点：§3.2 BROKEN-1、DEAD-1、DEAD-2）
5. **WebSocket 还要不要？**（README:478 自称"测试中"；当前事件名拼错，等于没实现）
6. **`StaticReplacer` / `GLOBALS()` 这套语法糖还维护吗？**（§5.7；且新版 DuckPhp 自己的 `Ext/StaticReplacer.php` 已标 `@todo deprecate`）
7. **`SwooleExt*` 那套旧接口要不要恢复？**（§3.6）—— 建议**不要**，改用 `HttpServerInterface`。
8. **本库要不要发到 composer？** 若发，`composer.json` 需要补 `phpunit` 到 `require-dev`、更新 PHP 版本约束、并考虑把 DuckPhp 放进 `require-dev` 以便做集成测试。

---

## 7. 建议的落地路线（有序 checklist）

> 原则：**每一步都要可验证**。不要跳过 1-3 直接改协程。

### 阶段 0：环境与基线（先做，否则啥也验证不了）

- [ ] 装 Swoole 扩展（Windows 下建议用 WSL 或直接 Linux 容器；Windows 原生编译 swoole 很痛苦）。确认 `php -m | grep swoole` 与 `swoole_version()`。
- [ ] 在 `composer.json` 的 `require-dev` 里加 `phpunit/phpunit`（选与 PHP 版本匹配的大版本），`composer install`。
- [ ] 把 `phpunit.xml` 迁到当前 phpunit 版本（删 `backupStaticAttributes`、`convertNoticesToExceptions` 等已移除属性；`<filter><whitelist>` 改 `<source>`；**去掉 `processIsolation`**）。
- [ ] 决定测试策略：**现有 `tests/*Test.php` 是自动生成的空壳（§3.3），建议整体废弃重写**，先写一个 `tests/SmokeTest.php`：起服务器 + 发一个真实请求 + 断言响应。
- [ ] 修 `.php_cs`（php-cs-fixer 2 → 3 语法）或干脆删掉。

### 阶段 1：修 L2 的 bug，让"单请求能跑通"（低风险、可独立验证）

- [ ] **BLOCKER-1**：`src/SwooleContext.php:10` `Swool\Coroutine` → `Swoole\Coroutine`。
- [ ] **BLOCKER-2**：`src/SwooleHttpd.php:167` `'mesage'` → `'message'`。
- [ ] **BROKEN-1**：`src/SwooleHttpd.php:510` `return;` → `return true;`
- [ ] **BROKEN-3**：`init()` 支持 `$server` 注入（`$this->server = $server ?: ...`），顺便补 README 里承诺的 `swoole_server` 选项或删掉该文档。
- [ ] **BROKEN-2**：实现或删除 `base_class`（`src/SwooleHttpd.php:68`）。
- [ ] **DEAD-1**：`src/SwooleHttpd.php:118` 删掉裸 `return;`，让 autoload 清理逻辑真正生效。
- [ ] **DEAD-2**：删除 `src/SimpleHttpd.php`（或其 `mapToGlobal`/`\defer` 修正）。
- [ ] **LATENT-1**：`src/SwooleCoroutineSingleton.php:44` 补 `self::`。
- [ ] **LATENT-2**：`src/SwooleSuperGlobal.php:74-77` 修 `REQUEST_URI` 重复拼 query。
- [ ] **LATENT-3**：`src/SwooleSuperGlobal.php:38-47` 修 `is_inited` 时机 / 给公开属性默认值。
- [ ] 删掉 `src/SwooleHttpd.php:16-20` 那 3 行残余 import（§2.2）。
- [ ] `src/SwooleSessionHandler.php` 补返回类型或 `#[\ReturnTypeWillChange]`（PHP 8.1+）。
- [ ] **验证**：跑 `examples/hello.php`（`SwooleHttpd::RunQuickly(['port'=>9528,'http_handler'=>'hello'])`），浏览器/curl 拿到 `hello` 输出；再跑 `examples/session.php` 验证 session 计数递增（它依赖当前不存在的 `SG()`，见阶段 2）。

### 阶段 2：补 Phantom API，让 README/examples 自洽

- [ ] 决定逐个补还是逐个删（建议**补常用的、删冷门的**）：
  - `SG()`（`examples/session.php:17,31`、README 都用）→ 建议补：`return SwooleSuperGlobal::G();`
  - `ThrowOn($flag,$msg,$code=0)` → 建议补（README:134-138 有文档，抛 `SwooleException`）
  - `Throw404()` / `set_http_404_handler()` → 建议补
  - `exit_request()` → 建议补为 `_exit()` 的别名；`exit_system()` 直接删（旧命名）
  - `getStaticComponentClasses()` / `forkMasterClassesToNewInstances()` / `checkOverride()` / `includeHttpPhpFile()` → 建议**从测试注释和文档里删掉**（`tests/` 反正要重写）
- [ ] 重建 `Swoole404Exception`（`git show 04c5447^:src/Swoole404Exception.php`，内容是空类）**或**删掉 `tests/Swoole404ExceptionTest.php` 与 README:346。
- [ ] 修 README 的所有不一致（§3.4 表）—— **README 目前会主动误导后续 AI，优先级高**。
- [ ] 删除或重写 `tests/duckphp.php`（`DNMVCS/template` 不存在，见 §3.5）。

### 阶段 3：按 `HttpServerInterface` 重写对接层（**这是核心工作量**）

- [ ] 新建一个服务器类（建议 `src/SwooleHttpd/HttpServerForDuckPhp.php` 或直接让 `SwooleHttpd` 实现 `DuckPhp\HttpServer\HttpServerInterface`），提供 §4.2 的 5+ 项表面：
  `static _($object=null)`、`static RunQuickly($options)`、`init(array $options, ?object $context = null)`、`run()`、`getPid(): int`、`close()`。
- [ ] `init()` 里：
  - 从 `$options['http_app_class']` 取应用类名，从 `$options['path']` 取项目根（不要依赖 `SCRIPT_FILENAME`）。
  - **强制 `cli_enable = false`**（§4.4）。
  - **先 define `__SUPERGLOBAL_CONTEXT`**（指向 SwooleHttpd 的超全局实现），再 init 应用（§4.9 顺序陷阱）。
- [ ] 每请求：
  1. 填超全局（含 `PATH_INFO` / `REQUEST_URI` / `REQUEST_METHOD` / `SCRIPT_FILENAME` / `DOCUMENT_ROOT` / `HTTP_*`）；
  2. 按选定方案做容器隔离/重置（§5.1）；
  3. 调用 **`$app::_()->serve()`**（不是 `run()`）（§4.5）；
  4. 捕获输出并**只 `end()` 一次**（§5.5）；
  5. 收尾清理（对齐 `serve()` 的 `finally`：`Route::clear()` / `Runtime::clear()`；按方案加更多）。
- [ ] 装 system wrapper：`SystemWrapper::system_wrapper_replace(...)`，**覆盖全部 10 个 key**（§4.7，本库现有 8 个，要补 `session_id`、`mime_content_type`）。
- [ ] 异常：注册 `ExceptionManager::_()->assignExceptionHandler(ExitException::class, function(){})` 静默 exit 异常；
      并同时容忍 `Swoole\ExitException`（旧代码 `src/SwooleHttpd.php:23,111`、`src/SimpleWebSocketd.php:8,48` 硬编码了这个类）。
      **不要**再调 `$app::assignExceptionHandler(...)` / `$app::system_wrapper_replace(...)`（App 上没这些方法，§4.6）。
- [ ] 404：用 `$app->skip404Handler()` + `serve()` 返回值自行接管，或设 `options['error_404']`。**不要**设 `skip_404_handler`（不存在的选项名）。
- [ ] 删掉 `SwooleHttpd_Runner::_OnServerRequest()` / `initApp()` 里对 `::G()` / `getDynamicComponentClasses()` / `forkMasterInstances()` 的调用（§4.6），改成新契约。
- [ ] 让 CLI 能选中本实现：确认 `php cli.php run --http_server=SwooleHttpd/SwooleHttpd` 这条路径可用（`Command.php:52-55`）。

### 阶段 4：协程隔离（**方案已定，见 §12.8 的最终 checklist；不要再问用户**）

- [ ] 按 **§12.8** 执行（override `PhaseContainer` + 每协程完全独立容器 + `on_request` 挂钩）。
- [ ] 处理 §5.3（全局 error handler）、§5.7（`StaticReplacer` 要不要并入 per-cid 重建）。
- [ ] **验收**：并发不串（§8.2 断言点 2）必须绿。

### 阶段 5：打磨

- [ ] WebSocket（若保留）：修事件名后补真实测试。
- [ ] `send_file()` 走 system wrapper 的 `mime_content_type`，统一路径。
- [ ] 实现优雅停机（`is_shutdown` 目前没人设，§3.2 其他观察）。
- [ ] 更新 README / changelog；`changelog.md` 补上 1.1.x → 当前的事实变更。
- [ ] 考虑加 `AGENTS.md`（软链或改名 `AI_MEMO.md`），让后续 AI 自动加载本备忘。

---

## 8. 验证方法（怎么证明"真的能跑"）

### 8.1 不装 swoole 也能做的验证

```powershell
cd E:\ProjectGoat\swoolehttpd
# 语法
Get-ChildItem src\*.php | ForEach-Object { php -l $_.FullName }
# 类能加载 + API 面
php -r 'require "autoload.php"; $r=new ReflectionClass("SwooleHttpd\\SwooleHttpd"); print_r($r->getTraitNames());'
# 纯逻辑（不碰 Swoole 类）的单测：StaticReplacer、SwooleSingleton
php -r 'require "autoload.php"; $a=&SwooleHttpd\StaticReplacer::G()->_GLOBALS("n"); $a++; echo $a;'
```
> 注意：**任何碰 `Swoole\Coroutine` 的代码路径在没装扩展时都会 "Class not found"**，这不是本库的 bug，是环境缺失。

### 8.2 装了 swoole 之后的冒烟测试

```bash
# 1) 本库自己的最小 example（http_handler 模式）
php examples/hello.php &
sleep 1
curl -s http://127.0.0.1:9528/ | head

# 2) session（阶段 1 修完 BLOCKER-1、阶段 2 补了 SG() 之后）
php examples/session.php &
curl -s -c /tmp/c.txt http://127.0.0.1:9528/
curl -s -b /tmp/c.txt -c /tmp/c.txt http://127.0.0.1:9528/   # 计数应递增

# 3) DuckPhp 集成（阶段 3 之后）
cd E:\ProjectGoat\DNMVCS
php bin/duckphp run --http_server=SwooleHttpd/SwooleHttpd --port=9529 --path=<某项目路径>
curl -s http://127.0.0.1:9529/
```

**必须覆盖的断言点**（这些正是 §5 里各难题的探针）：
1. 同一个 worker 连续两个请求，`$_GET` / `$_SESSION` 不串（→ 验 §5.1、§4.9）；
2. **两个并发请求**（`curl` 同时发或 `ab -c 4`）不串数据（→ 这才是协程隔离的真考题）；
3. 响应体超过 `ob_start` chunk size（例如 echo 2MB）不报 "response already sent"（→ 验 §5.5）；
4. 抛异常 / 访问不存在路由 / 调 `exit` 三种情况下，客户端都能拿到完整响应、worker 不挂（→ 验 §4.8）；
5. session 跨请求保持 + `session_destroy()` 生效（→ 验 §5.6）。

---

## 9. 版本与兼容清单（从 2021 迁到 2026）

| 项 | 现状 | 要做的事 |
|---|---|---|
| PHP | `composer.json:21` 要求 `>=7.0.0`；本机 7.4.33 | 决定目标版本。DuckPhp 要求 `>=7.4`。若升 8.x：`SwooleSessionHandler` 需返回类型（§3.2）；`declare(strict_types=1)` 已在各文件顶部，注意类型宽松度变化 |
| Swoole | 完全没装；代码要求 `>=4.2.0`（`src/SwooleHttpd.php:138`） | 建议直接针对 5.x 开发。需逐项核对：`Swoole\Runtime::enableCoroutine()` 签名、`Coroutine::getuid()`（**新版本倾向 `getCid()`，`getuid` 已废弃【待验证具体移除版本】**）、`$server->setting` 属性可读性（`src/SwooleHttpd.php:156-158`）、`$response->sendfile()`、`Http\Server::on()` 的合法事件名 |
| Swoole 短名函数 | `src/SimpleHttpd.php:35,41,48` 用 `\defer()`；`\go()`/`\co()` 同理 | Swoole 5 默认 `swoole.use_shortname=Off` → **裸 `defer()`/`go()` 全部失效**，必须用 `Swoole\Coroutine::defer()`（本库主体代码已经这么写了，只有 `SimpleHttpd.php` 是旧的） |
| `Swoole\ExitException` | `src/SwooleHttpd.php:23,111`、`src/SimpleWebSocketd.php:8,48` 硬编码 | 需**同时**兼容 DuckPhp 的 `__EXIT_EXCEPTION`（`DuckPhp\Core\ExitException`，见 §4.6） |
| PHPUnit | 完全没有；`phpunit.xml` 是 8/9 时代 | 见阶段 0 |
| php-cs-fixer | `.php_cs` 是 2.x 语法，需 `.php-cs-fixer.php` + 3.x 语法 | 见阶段 0 |
| `mime_content_type` | PHP 8.1+ 需要 `fileinfo` 扩展；本库 `send_file()` 直接用（`:609`） | 走 DuckPhp 的 provider（它自带 MIME 表实现，`SystemWrapper.php:229-249,250-344`），避免依赖 |
| Xdebug | 本机装了 3.1.6 | 跑 Swoole 性能测试前记得关 |

---

## 10. 关键行号速查（改代码时直接跳）

### `E:\ProjectGoat\swoolehttpd\src\SwooleHttpd.php`
```
:16-20   残余 import（SwooleHttpd_Static/_SuperGlobal/_Singleton，不存在但无害，可删）
:31      const VERSION = '1.1.4-dev'
:43-71   $options 默认值（:46 swoole_server_options、:61 enable_resource_file、:68 base_class 死配置）
:81-89   RunQuickly
:101-104 set_http_exception_handler
:109-115 onHttpException（检查 Swoole\ExitException）
:116-131 onHttpClean（:118 裸 return → 死代码）
:132-142 check_swoole（要求 swoole>=4.2.0，否则 exit）
:145-182 init（:145 $server 形参被忽略）
:167     $server->on('mesage', ...)  ← 拼错
:183-190 initApp（旧 DuckPhp API：G/assignExceptionHandler/system_wrapper_replace/skip_404_handler）
:191-205 createServer
:206-215 run → $server->start()
:221-283 trait SwooleHttpd_SimpleHttpd（:238-282 onRequest；:258-265 ob_start→end）
:285-316 trait SwooleHttpd_Handler（:302 404 header；:307 500 header）
:317-365 trait SwooleHttpd_Glue（:344-355 GLOBALS/STATICS/CLASS_STATICS → StaticReplacer）
:366-416 trait SwooleHttpd_SystemWrapper（:402-415 provider 表，缺 session_id / mime_content_type）
:417-434 trait SwooleHttpd_SingletonHandle（:422-428 getDynamicComponentClasses → 缺 StaticReplacer）
:435-613 trait SwooleHttpd_Runner
  :439-454 fixIndex
  :455-468 _OnServerRequest  ← 旧 DuckPhp 对接，整体要重写
  :470-512 onHttpRun（:505-511 http_handler_file 返回 null 的 bug）
  :513-522 prepareRootMode
  :524-581 runHttpFile（:526 路径穿越检查）
  :582-593 includeHttpFullFile
  :594-606 runPhpFile
  :607-612 send_file（绕过 system wrapper）
```

### `E:\ProjectGoat\swoolehttpd` 其他
```
src/SwooleContext.php:10        use Swool\Coroutine;   ← BLOCKER-1
src/SwooleContext.php:118-121   registWriteClose → regShutdown
src/SwooleContext.php:122-142   session_start
src/SwooleContext.php:170-174   create_sid
src/SwooleSuperGlobal.php:25-28 __construct → init()
src/SwooleSuperGlobal.php:38-47 is_inited 时机问题
src/SwooleSuperGlobal.php:74-77 REQUEST_URI 重复拼 query
src/SwooleSuperGlobal.php:81-88 DefineSuperGlobalContext → '::G'
src/SwooleCoroutineSingleton.php:17-24  define __SINGLETONEX_REPALACER
src/SwooleCoroutineSingleton.php:44     $cid_map 缺 self::
src/SwooleCoroutineSingleton.php:120-153 forkMasterInstances
src/SwooleSingleton.php:11-16           G() 外包给 __SINGLETONEX_REPALACER
src/SimpleHttpd.php:35,58               \defer() + mapToGlobal() ← 坏死，建议删
src/SwooleSessionHandler.php:11         implements SessionHandlerInterface（无返回类型）
tests/bootstrap.php:163-248             TestFileGenerator（生成空壳测试的元凶）
phpunit.xml:3-12,17-19                  过时属性 + processIsolation
```

### `E:\ProjectGoat\DNMVCS\src`
```
HttpServer/HttpServerInterface.php:9-16   ← 要实现的目标接口（4 方法）
HttpServer/HttpServer.php:9                class HttpServer（未 implements 该接口）
HttpServer/HttpServer.php:77-93            _() 单例 + __SINGLETONEX_REPALACER
HttpServer/HttpServer.php:97-100           RunQuickly = _()->init()->run()
HttpServer/HttpServer.php:107              init(array $options, ?object $context = null)
HttpServer/HttpServer.php:168-175          run()
HttpServer/HttpServer.php:176-179          getPid()
HttpServer/HttpServer.php:180-202          close()
HttpServer/HttpServer.php:237-274          runHttpServer（php -S）
Component/Command.php:47-60                command_run ← 官方插件位
Component/Command.php:50,55,57             http_app_class / HttpServer::_($class::_()) / cli_enable=false
Ext/DuckPhpInstaller.php:127-132           runDemo（同样的插件位）
Core/KernelTrait.php:25-68                 内核选项（:36 cli_enable 默认 true、:37 skip_exception_check、:38 use_exit_exception）
Core/KernelTrait.php:89-100                RunQuickly（PHP_SAPI==='cli' → execute）
Core/KernelTrait.php:140-146               initOptions（path realpath）
Core/KernelTrait.php:156-164               getDefaultProjectPath（依赖 $_SERVER['SCRIPT_FILENAME']）
Core/KernelTrait.php:232-267               initContainer（PhaseContainer 切换 + 注册 app 实例）
Core/KernelTrait.php:236                   //$flag = PhaseContainer::ReplaceSingletonImplement();  ← 被注释的钩子
Core/KernelTrait.php:298-324               init
Core/KernelTrait.php:310                   is_cli = PHP_SAPI==='cli' && cli_enable
Core/KernelTrait.php:468-475               run()：cli_enable ? execute : serve
Core/KernelTrait.php:476-500               ⭐ serve()  ← 单请求入口
Core/KernelTrait.php:501-506               ⭐ prepareServe()：$classes=[] → 空操作
Core/KernelTrait.php:508-520               runException（skip_exception_check 时 rethrow）
Core/KernelTrait.php:553-556               On404（静态）
Core/KernelTrait.php:565-568               _On404 默认实现
Core/KernelTrait.php:598-603               onRequest 钩子（options['on_request']）
Core/App.php:136                           SuperGlobal => EXT_FOLLOW_APP
Core/App.php:209-236                       App::_On404（:211 skip_404、:214 error_404）
Core/App.php:415-418                       skip404Handler()
Core/PhaseContainer.php:11                 ⭐ public static $instance   ← 协程隔离的根因
Core/PhaseContainer.php:18-21              GetObject
Core/PhaseContainer.php:22-36              _() / RestAllContainerForTesting()
Core/PhaseContainer.php:42-78              _GetObject / getObjectInContainer / createObject
Core/PhaseContainer.php:80-105             setDefaultContainer / addPublicClasses / setCurrentContainer
Core/SingletonExTrait.php:16-19            ⭐ _() → PhaseContainer::GetObject
Core/SuperGlobal.php:12                    superglobal_auto_define 默认 false
Core/SuperGlobal.php:29-36                 initOptions（会 _LoadSuperGlobalAll）
Core/SuperGlobal.php:38-45                 ⭐ DefineSuperGlobalContext → static::class.'::_'
Core/SuperGlobal.php:145-154               ⭐ getSuperGlobalData（优先读宏）
Core/SuperGlobal.php:184-198               _SessionSet / _SessionUnset
Core/SystemWrapper.php:12-25               ⭐ provider 表（10 个 key）
Core/SystemWrapper.php:31-46               system_wrapper_replace
Core/SystemWrapper.php:63-88               dispatch（__SYSTEM_WRAPPER_REPLACER 优先）
Core/SystemWrapper.php:135-153             _header（:142 PHP_SAPI==='cli' 早退）
Core/SystemWrapper.php:162-172             _exit（抛 __EXIT_EXCEPTION）
Core/ExceptionManager.php:45-48            CallException
Core/ExceptionManager.php:53-59            assignExceptionHandler
Core/ExceptionManager.php:69-87            on_error_handler（非 notice 全转 ErrorException）
Core/ExceptionManager.php:88-104            _CallException（静默 __EXIT_EXCEPTION）
Core/ExceptionManager.php:119-138          ⭐ run()：装全局 error/exception handler；:130 system_exception_handler 钩子
Core/Runtime.php:37-46                     run()（use_output_buffer 时 ob_start）
Core/Runtime.php:47-59                     clear()
Core/ExitException.php:12-17               Init() → define __EXIT_EXCEPTION
Foundation/Controller/SessionTrait.php:20-28  checkSessionStart → SystemWrapper::_session_start
Foundation/Helper.php:127-141              __callStatic 扇出到 4 个 Helper
```

### `E:\ProjectGoat\DNMVCS` 文档（**优先查文档**）
```
docs/zh/guide/http-server.md:138-146      §⑤ 换用别的 HttpServer 实现（--http_server）
docs/zh/guide/http-server.md              §起停、状态残留、workers 回环、生产别用
docs/zh/reference/HttpServer-HttpServerInterface.md   本接口的契约说明
docs/zh/reference/HttpServer-HttpServer.md            内置实现说明
docs/zh/reference/Component-Command.md                command_run 行为
docs/zh/guide/troubleshooting.md:148      官方建议：常驻方案要自己接生命周期
docs/old/ChangeLog.txt:372-393            Swoole 适配的历史沿革
```

---

## 11. 给后续 AI 的几条硬提醒

1. **不要相信 `README.md` 和 `tests/`。** README 有 §3.4 表里那一堆不一致；`tests/*Test.php` 是脚本生成的空壳（§3.3），里面的方法名是跨年累积的幻觉。
   **唯一可信的是 `src/` 的实际代码 + `ReflectionClass` 实测结果。**
2. **不要恢复 `ServerForDuckPhp.php` / `SwooleExt*`。** 它们是半成品（连 `$flag` 都没赋值），而且其设计前提（扩展 DuckPhp 应用类暴露 `getDynamicComponentClasses()`）**已被新版 DuckPhp 废弃**。新契约是 `HttpServerInterface`（§4.2）。
3. **`::G()` → `::_()` 是最容易漏的一处系统性改动。** 新版 DuckPhp 里 `G()` 一个都不存在（唯一例外是 `HttpServer::_()` 内部对 `__SINGLETONEX_REPALACER` 的处理，方法名是 `_` 不是 `G`）。
4. **`cli_enable` 是你最大的敌人。** Swoole 下 `PHP_SAPI==='cli'`，DuckPhp 默认 `cli_enable=true`，`run()` 会跑去执行控制台命令。**永远调 `serve()`，或先确保 `cli_enable=false`。**
5. **`PhaseContainer::$instance` 是全局静态**（`PhaseContainer.php:11`）。看到任何"多请求/多协程"的方案，先问"这个静态怎么办"。**这是本项目真正的技术核心，比所有 bug 加起来都重要。**
6. **改 DuckPhp 核心前先问用户。** 用户是 DuckPhp 的作者，但改框架核心是重大决策。**注意：§12 的三条已裁定设计都明确"不改 DuckPhp 核心"—— 照 §12 做就行，不要再提改框架。**
7. **顺序很重要**：先修 §3.2 的 bug 让单请求跑通（可验证、低风险），再重写对接层（§7 阶段 3），最后才碰协程隔离（§7 阶段 4）。**反过来做你会无法定位失败原因。**
8. **每次验证都要用"两个并发请求不串数据"当终检**（§8.2 断言点 2）—— 单请求跑通**不能**说明协程隔离是对的。
9. **§12 是本次最重要的新增章节**（用户亲自裁定的架构）。动手前务必读完 §12，尤其是 §12.3 的 4 个坑（尤其"浅拷贝陷阱"—— 那是最容易"以为做完了其实没做"的地方）。

---

## 12. ⭐ 用户已裁定的设计（2026-09-24）

> 本节内容由用户（DuckPhp 作者）直接裁定，**优先级高于本文件其他章节里我原先的推测**。
> 若本节与其他章节冲突，**以本节为准**。

### 12.1 三条裁定（原文照录 + 我的技术展开）

| # | 用户裁定 | 我的解读 |
|---|---|---|
| 1 | **协程隔离：使用 override PhaseContainer 的方式** | 从外部把 `PhaseContainer::$instance` 换成协程感知的**子类**；**不改 DuckPhp 核心语义**（不动 `PhaseContainer` 源码，只替换实例）。见 §12.2 |
| 2 | **`KernelTrait.php:501-506` 是特意设计，给其他实现看** | `prepareServe()` 里 `$classes = []` **不是 bug**，是"留给其他实现看的示范位"（本该由实现方填 renew 列表）。**但本库选定路径 3：不覆写它**，改在 `options['on_request']` 里做每请求准备。见 §12.6 |
| 3 | **可以用 onRequest(): `$this->options['on_request']`** | 每请求的业务钩子就用这个选项。`KernelTrait.php:45` 声明（默认 `null`），`:598-603` 实现，**"每次 `serve()` 开头被调"**（`docs/zh/reference/Core-KernelTrait.md:39`、`docs/zh/reference/options-index.md:218`）。见 §12.5 |

### 12.2 「override PhaseContainer」的确切做法

**安装方式（一行）：**
```php
\DuckPhp\Core\PhaseContainer::_(new SwooleHttpd\CoroutinePhaseContainer());
```

**这不是新发明 —— 框架自己的测试就是这么干的**，可直接照抄：
```php
// DNMVCS/tests/Core/PhaseContainerTest.php:14-15
PhaseContainer::_();
PhaseContainer::_(new MyPhaseContainer());
```
文档 `docs/zh/reference/Core-PhaseContainer.md:48` 也写了 `RestAllContainerForTesting()` 是"用全新容器替换静态 `$instance`"。

**为什么这一行就能全局生效：**

`::_()` 是框架里**唯一**的取实例入口，链条只有三步，中间没有别的分支：
```php
// 1. 所有组件（ComponentBase / Foundation\SingletonTrait）都走这里
// DNMVCS/src/Core/SingletonExTrait.php:16-19
public static function _($object = null)
{
    return PhaseContainer::GetObject(static::class, $object);
}
// 2. GetObject 是 static，转发到「当前容器实例」的 _GetObject
// DNMVCS/src/Core/PhaseContainer.php:18-21
public static function GetObject(string $class, ?object $object = null)
{
    return static::_()->_GetObject($class, $object);
}
// 3. _GetObject 是 public → 可被子类覆写  ← 隔离的落点
// DNMVCS/src/Core/PhaseContainer.php:42
public function _GetObject(string $class, ?object $object = null): object
```
因为第 2 步走的是 `static::_()`（拿 `$instance`）而不是 `new static()`，所以**换掉 `$instance` 就等于换掉了全框架的单例解析器** ✅

**容器内部结构**（`PhaseContainer.php:13-16`，文档 `docs/zh/guide/container-phases.md:26-33` 有权威解释）：
```php
public $containers = [];   // containers[相位名][类名] = 实例
public $current = '';      // 当前相位（桶名），根相位是空串 ''
public $default = '';      // 「公共桶」名，根应用 init 后是 '#public'
public $publics = [];      // 被标为 public 的类名表
```
`_GetObject()` 的三步规则（`PhaseContainer.php:42-58`）：
1. 当前相位桶里找 → 命中即返回（传了 `$object` 则先替换）；
2. 若该类在 `$publics` 里 → 去 `$default`（`#public`）桶找；
3. 都没有 → `new $class()`，存进**第 2 步选定的那个桶**。

**谁在哪个桶**（文档 `docs/zh/guide/container-phases.md:47,51-56`，很关键）：
- **public 类 → 全进程一份，在 `#public` 桶**：走 `initComponentsOfRoot()` 的（`Console`，`KernelTrait.php:344` 的 `addPublicClasses()`）+ `DuckPhp::initComponentsOfRoot()` 并入的 `DbManager`/`RedisManager`/`GlobalAdmin`/`GlobalUser`/`GlobalEvent`（`DuckPhp.php:112-136`）。
- **非 public 类 → 每个相位各一份**：`Route`（走 `initComponentsOfInner()`，**不标 public**）、`options['ext']` 里的扩展、`View`、`Lang`…
- **应用实例本身**：`initContainer()` 里 `(self::class)::_($this)` / `(static::class)::_($this)`（`KernelTrait.php:260-261`）→ 存进**根相位桶 `''`**，不是 `#public`。

**可覆写的完整表面**（改代码时对着这张表）：

| 类型 | 成员 | 行 |
|---|---|---|
| **必覆写** | `_GetObject($class, $object)` | `:42` |
| 建议覆写（都是 per-cid 语义） | `getObjectInContainer` (prot) / `createObjectToContainer` (prot) / `createObject` (prot) | `:59` / `:69` / `:75` |
| 建议覆写 | `setDefaultContainer` / `addPublicClasses` / `removePublicClasses` | `:80` / `:84` / `:92` |
| 建议覆写 | `setCurrentContainer` / `getCurrentContainer` | `:98` / `:102` |
| 建议覆写 | `issetContainer` / `createLocalObject` / `removeLocalObject` / `getClassOfContainer` | `:106` / `:110` / `:116` / `:120` |
| 可选 | `dumpAllObject`（调试用，建议保留并能打印全部 cid） | `:124` |
| static | `GetObject` / `_` / `RestAllContainerForTesting` / `Dump` | `:18` / `:22` / `:33` / `:37` |
| 状态字段 | `$containers` / `$current` / `$default` / `$publics` | `:13-16` |

### 12.3 ⚠️ 四个必须处理的坑（都已查证，不是猜测）

#### 坑 1：`RestAllContainerForTesting()` 会**静默丢掉**你的 override

`PhaseContainer.php:33-36`：
```php
public static function RestAllContainerForTesting()
{
    static::_(new static());     // ← new static()
}
```
以 `PhaseContainer::RestAllContainerForTesting()` 调用时 `static` = `PhaseContainer` → 装回一个**普通** `PhaseContainer`，**你的子类没了**，协程隔离瞬间失效，而且**不报错**。
（DuckPhp 自己的测试套件**每个用例都调它**，`tests/Core/KernelTraitTest.php` 里就有 20+ 处。）

**处理**：
- 本库自己的代码**不要**用 `RestAllContainerForTesting()`；要重置就显式 `PhaseContainer::_(new CoroutinePhaseContainer())`；
- 或者覆写它（静态方法可被子类覆写）让它永远装回协程版；
- 或者做一个 `assertContainerIsOurs()` 守卫，在每请求入口校验 `PhaseContainer::_() instanceof CoroutinePhaseContainer`，不是就装回去 + 报错（推荐，防御性最强）。

#### 坑 2：`SwitchRootPhase()` 直接写公开属性，子类**拦截不到**

`KernelTrait.php:137-138`：
```php
PhaseContainer::_()->current = self::$ROOT_PHASE;
PhaseContainer::_()->default = self::$ROOT_PHASE_OF_SHARED;
```
PHP 里**无法**通过继承移除父类的 `public` 属性，`__set()` 也**不会**对已声明的 public 属性触发。
→ 这两行写的是**共享的** `$current` / `$default`，无法变成 per-cid。

**处理（推荐）**：把 `$current` / `$default` / `$publics` / `$containers` 明确定义为 **"master 模板值"**：
- 它们由框架在 init / `SwitchRootPhase()` 时写；
- 协程首次进入时，**以它们为模板播种**该 cid 的容器；
- 而文档化的读写 API（`setCurrentContainer()` / `getCurrentContainer()`，`KernelTrait.php:548,167-170` 在用）则覆写成 **per-cid**。

这样 `SwitchRootPhase()` 只在"嵌套应用重设根"这种极少见的场景下表现为全局生效 —— **记录为已知限制**即可（不要试图拦截，得不偿失）。

#### 坑 3：换容器后**必须重新登记应用实例**，否则"莫名的 Internal Error"

`initContainer()` 的 `(self::class)::_($this)`（`KernelTrait.php:260-261`）和 `initComponentsOfRoot()` 的 `addPublicClasses()`（`:344`）**都只在 init 时执行一次**。
DuckPhp 文档 `docs/zh/guide-maintenance-guide.md:403` 白纸黑字警告：

> 换过相位容器后（`PhaseContainer::RestAllContainerForTesting()`）**必须重新 init**，否则 `User::_()` / `App::_()` 会新建一个「默认的」实例，症状是**莫名的 Internal Error**。

→ 所以 **per-cid 容器绝不能是空的**。新协程的容器必须从 master **播种**至少这几样：
1. `$default` 桶名（`'#public'`）、`$publics` 表；
2. **根相位桶里的应用实例登记**（`DuckPhp\DuckPhp` / `static::class` / `override_from` 三个键，见 `KernelTrait.php:259-264`）；
3. 子应用实例（若有 `options['app']`，见 `initChildren()` `:442-457` 与 `toThisChild()` `:203-212`）。

**否则 `App::_()` 会 `new` 出一个全新的应用对象**（`_GetObject` 第 3 步），整个框架行为就错了。

#### 坑 4：⭐ **PHP 数组是浅拷贝（对象按句柄共享）—— 最容易"以为做完了其实没做"**

```php
$cid_container->containers = $master->containers;   // 看起来"拷贝了一份"
```
实际上**两个容器数组里装的是同一批对象**（PHP 拷贝数组是 copy-on-write，但**对象永远是句柄**）。
后果：
- 想要**共享**的（`#public` 桶里的 `Logger`/`Console`/`DbManager`）→ 直接搬引用 **✅ 语义正确**；
- 想要**隔离**的（`Route`/`Runtime`/`View`/`Lang`/应用实例）→ 若只是搬引用，**"独立容器"就只换了桶名，对象还是同一个** → 请求间照样串数据，**而且你怎么看容器都觉得"隔离了"**。

**这是本节最容易翻车的地方。** 判断标准只有一个：**跑 §8.2 的"两个并发请求不串数据"测试**（单请求永远看不出问题）。
"隔离"要么**不播种这些类**（让它在本协程桶里按需新建），要么**显式 `clone`**（注意 `Route` 等可能持有 closure/资源，clone 语义需逐类验证）。

### 12.4 ✅ 已定：每个协程一个**完全独立**的容器（含 `#public`）

用户裁定：选 **（乙）**。

**含义**
- 每个 cid 一份容器。`$publics` 表照样播种（它只是**类名表**，无状态），但 public 类解析到的 `#public` 桶**也是该 cid 私有的**。
- ⇒ **public 类从"全进程一份"变成"每协程一份"**。这是与 DuckPhp 原语义（`docs/zh/guide/container-phases.md:47`）的**唯一偏离，也正是隔离的来源**。
- ⇒ **应用实例按引用播种同一个对象**（它承载 `options` / `is_inited` / `setting` / 子应用登记，属于**启动期状态**，不是每请求状态）。**不要 clone 应用对象**（它还被按类名查找，clone 会导致 `App::_()` 与实际 init 过的那个不一致）。
- 其余组件：per-cid 独立实例。

#### ⚠️ 由此产生的一个必须解决的后果：per-cid 容器**绝不能是空的**

我查证了：`Route` 这类组件**不是"new 出来就能用"** —— 应用配置与路由钩子都是在 `init()` 时灌进去的：
- `initComponentsOfInner()`（`KernelTrait.php:334-337,349-355`）对 `Route::class` 调 `$class::_()->init($options, $this)`；
- 各 RouteHook 组件在 `initContext()`/`init()` 里把**自己注册到当前 Route** 上，例如 `Component/RouteHookRouteMap.php:34-37` 的 `Route::_()->addRouteHook([static::class,'PrependHook'], 'prepend-inner')`；`RouteHookRewrite.php:35`、`RouteHookResource.php:30`、`RouteHookPathInfoCompat.php:25`、`Ext/RouteHookDirectoryMode.php:32`、`Ext/RouteHookFunctionRoute.php:23`、`Ext/RouteHookApiServer.php:35`、`Ext/RouteHookWebInstaller.php:77` 全都是这个套路；
- 而 **`Route::clear()` 并不回滚这些**：它只跑 `finally_run_hook_list`（`Core/Route.php:128-139`），**既不还原 `options`、也不注销钩子**。

**后果**：若 per-cid 容器为空，`Route::_()` 会走 `_GetObject()` 第 3 步 `new Route()` → 得到一个 **options 全默认、路由钩子全空** 的 Route → **路由彻底失效**，而且症状表现为"跑到默认控制器 / 莫名 404"，**极难一眼看出是容器播种问题**。

#### 播种机制：**优先用 `clone`**（推荐）

决定这件事的关键事实（已查证）：**框架内部所有路由钩子都是 `[类名, '方法名']` 形式，没有一个是闭包**（上面列的 8 处全部如此）。
这一点很重要，因为：
- `[类名字符串, '静态方法']` 是**静态可调用**、**不捕获 `$this`** → `clone` Route 后钩子列表原样可用，而且钩子方法内部再通过 `RouteHookRouteMap::_()` 取实例时会走**本协程的容器** ✅ 天然 per-cid；
- 反过来说，**如果注册的是闭包 `function() use ($this)`，clone 之后闭包仍指向 master 的那个对象** → 隔离被"走后门"破坏。框架内部没有这个问题；**用户自己用 `SystemHelper::addRouteHook()`（`Foundation/System/SystemHelper.php:44-46`）传闭包时是例外**，需要文档提醒。

**因此推荐：per-cid 容器 = 结构信息按值/引用播种，其余对象逐个 `clone`。**
- 按值/引用播种：`$publics`、`$default`、`$current`、应用实例登记（`KernelTrait.php:259-264` 那三个键：`self::class` / `static::class` / `override_from`）；
- 其余：`clone $masterObject`。

**必须逐类验证的 clone 风险**（要和 §8.2 的并发测试一起验）：

| 类 | clone 的风险 | 处理建议 |
|---|---|---|
| `Route` | 钩子为静态可调用 → **安全** ✅；`controller_class_map` 等数组按值复制 | 直接 clone |
| `View` / `Lang` / `Pager` | 基本是配置 + 数组 | 直接 clone |
| `Logger` | 可能持有文件句柄，clone 后**共享同一句柄**（写入可能交错） | 接受或确认 |
| `DbManager` | ⚠️ clone **不会复制连接**，两个对象指向**同一个 PDO** → 并发用同一连接**仍不安全** | 接受（当连接池），或 per-cid 新建（`local_database` 思路，`DuckPhp.php:156-165`） |
| `RedisManager` | 同上 | 同上 |
| `ExceptionManager` | 持有 `$exceptionHandlers`（可能含闭包）+ 全局 handler | 见 §12.7 待确认 |

**另一条腿（备选、更彻底）**：不用 clone，而是**每请求重新 `init()`**（即 `EXT_RENEW` 的语义：`$class::_(new $replaced_class)->init($oldOptions, $this->options)`，`KernelTrait.php:398-403`）。
它把钩子重新注册到**新实例**上，隔离最彻底；代价是每请求多一轮 init，且**要自己维护"哪些类需要重建"的清单与顺序**。
因为覆写路径已定为 **3**（只用 `on_request`、`prepareServe()` 不动，见 §12.6），这两条腿都**必须在 `on_request` 回调里自己实现**（`initComponentsOfDynmic()` 是 protected，外部调不到）。

**建议**：先上 **clone**（便宜、大概率够用），把并发测试与上面的 clone 风险表一起跑；哪个类 clone 不干净，再**单独把那一个类**改成 re-init。不要一开始就全量 re-init。

### 12.5 三件事怎么配合（推荐分工）

```
主进程启动（一次）
├─ ① PhaseContainer::_(new CoroutinePhaseContainer())        ← 装容器（必须在 app init 之前！）
├─ ② define('__SUPERGLOBAL_CONTEXT', 'SwooleHttpd\SwooleSuperGlobal::G')  ← 超全局（同样必须在 app init 之前）
├─ ③ $app::_()->init([
│       'cli_enable' => false,          // §4.4 必做
│       'path'       => ...,             // §5.4 避免依赖 SCRIPT_FILENAME
│       'on_request' => [Server::class, 'onRequestPrepare'],   // ← 每请求钩子（裁定 3）
│   ])
└─ ④ $server->start()

每个请求（协程内）
├─ ⑤ 建立/取出本 cid 的容器，并**播种**（§12.3-坑3 + §12.4：clone 或 re-init，绝不能空）
├─ ⑥ 填超全局（_SERVER 必需 key 见 §5.4 表；_GET/_POST/_COOKIE/_FILES）
├─ ⑦ $app::_()->serve()          ← 注意是 serve() 不是 run()（§4.4/§4.5）
│     ├─ prepareServe()   → 空操作（裁定 2：有意留白；本库**不用**它，见 §12.6）
│     └─ onRequest()      → options['on_request'] 回调（裁定 3）= 本库的每请求准备/重建位
└─ ⑧ 捕获输出 → response->end()（只一次，§5.5）
```
> ⚠️ ⑤ 和 ⑦ 的分工：容器播种（⑤）必须在 `serve()`（⑦）之前完成；而"每请求重建组件"既可以放在 ⑤（自己建容器时顺手做），也可以放在 ⑦ 的 `on_request` 里（框架已就位）。**建议放 ⑤**，让 `on_request` 只做业务相关的轻量准备，职责更清楚。

**`serve()` 内部调用顺序**（逐字来自 `KernelTrait.php:476-500`，决定了你能在哪里插手）：
```
prepareServe()   ← 先
onRequest()      ← 后（= options['on_request']）
Runtime::run() → Route::run() → 未命中则 _On404()
finally: phaseToCurrent(); Route::clear(); Runtime::clear();
```

### 12.6 ✅ 已定：覆写路径 = **3（不碰 `prepareServe()`，只用 `on_request`）**

用户裁定：选 **路径 3**。含义与后果：

- **不改** `prepareServe()`；**也不用** `override_class` 去注入应用子类；
- 所有"每请求准备 / 重建 / 重置"逻辑，全部写在 **`options['on_request']`** 回调里（`KernelTrait.php:45` 声明、`:598-603` 实现；文档 `docs/zh/reference/Core-KernelTrait.md:39`）；
- ⇒ **放弃 `EXT_RENEW` 白名单机制**（那是 `prepareServe()` → `initComponentsOfDynmic()` 的用法）→ 重建逻辑得自己写（§12.4 的 clone / re-init 两条腿）。

⚠️ **时序注意**：`prepareServe()` 在 `onRequest()` **之前**执行（`KernelTrait.php:479-480`）。既然 `prepareServe()` 什么都不做，我们的 `on_request` 就是"框架动手做事之前的最后准备位"，时序完全没问题 ✅

⚠️ **"给其他实现看"怎么理解**：`$classes = []` 仍然是**有意留着的示范位** —— 框架用它表明"这里本该由实现方填自己的 renew 列表"。**本库选择不用它**，改在 `on_request` 做等价的事。**不要因此去改 DuckPhp。**

### 12.7 待确认（本次裁定后剩下的问题）

> **已裁定**：seed 语义 = **（乙）完全独立容器**（§12.4）；覆写路径 = **3，只用 `on_request`**（§12.6）。这两条**不必再问**。

1. **`ExceptionManager` 的全局 handler 怎么办**（§5.3）：容器 per-cid 之后，`set_error_handler`/`set_exception_handler` 仍是**进程级**，注册时绑定的是"当时的容器"。而且是接受、还是用 `options['system_exception_handler']`（`ExceptionManager.php:130-131`）接管？
2. **`SwooleHttpd\SwooleSuperGlobal` 要不要顺便加 `_()` 静态访问器**，好让 `__SUPERGLOBAL_CONTEXT` 的值与 DuckPhp 的约定（`::_`，`Core/SuperGlobal.php:41`）一致？（现在本库 define 的是 `::G`，**其实也能用** —— DuckPhp 只做 `(__SUPERGLOBAL_CONTEXT)()` 调用，见 §4.9。所以这条纯属风格统一，不急。）
3. **`DbManager`/`RedisManager` 在 per-cid 下要 clone（共享连接，并发不安全）还是 per-cid 新建连接？**（§12.4 风险表最后两行）—— 这直接影响每个请求的开销。
4. **用户代码用 `SystemHelper::addRouteHook()` 传闭包的情况**要不要在文档里明确警告？（§12.4：闭包会捕获 master 对象，破坏隔离。）

### 12.8 本节带来的 §7 阶段 4 修正（**最终版，照这个做**）

§7 阶段 4 原来写的是"与用户确定方案 A/B/D"。**现已确定，替换为下列步骤**：

- [ ] 写 `SwooleHttpd\CoroutinePhaseContainer extends DuckPhp\Core\PhaseContainer`，覆写 §12.2 表里的方法（`_GetObject` 是必覆写的落点）。
- [ ] 主进程启动时 `PhaseContainer::_(new CoroutinePhaseContainer())`，**放在 app `init()` 之前**。
- [ ] 实现 per-cid 容器的**建立 + 播种**（§12.3-坑3、§12.4）：
  - [ ] 播种结构信息：`$publics`（类名表）、`$default`、`$current`、应用实例登记（`KernelTrait.php:259-264` 的三个键）；
  - [ ] **按 `clone` 播种其余组件对象**（§12.4 推荐），应用实例按**引用**播种；
  - [ ] 协程结束时用 `Coroutine::defer` 清理本 cid 的容器（防内存泄漏）。
- [ ] 处理坑 1（`RestAllContainerForTesting` 会丢 override）+ 加 `assertContainerIsOurs()` 守卫（每请求入口校验 `PhaseContainer::_() instanceof CoroutinePhaseContainer`）。
- [ ] 处理坑 2（`SwitchRootPhase` 直接写 public 属性）—— 按"**master 模板值**"语义接受，并写进代码注释。
- [ ] 处理坑 4（**浅拷贝陷阱**）—— 明确"哪些类必须隔离、哪些可共享"，**并用并发测试验证**（不是靠读代码确认）。
- [ ] 不要覆写 `prepareServe()`；把每请求准备/重建挂在 `options['on_request']`（§12.6 = 裁定 3）。容器播种放"建容器时"（§12.5 的 ⑤）。
- [ ] **逐类验证 §12.4 的 clone 风险表**（尤其 `DbManager`/`RedisManager` 的连接共享、`Logger` 的文件句柄）。
- [ ] **验收**：§8.2 断言点 1 和 2 必须绿 —— **尤其断言点 2（并发不串）**；单请求跑通**不能**算完成。

---

## 13. ✅ 实施结果（1.1.5，2026-09-24）—— 本文件的历史章节已过期，以本节为准

用户下令"开工"后本次已实施完成。**§3.2 的 bug 清单、§4.6 的 API 对照、§5 的难点、§7 的阶段 0-5
都已经落地并实测通过。** 后续 AI 请先读本节 + `docs/duckphp-integration.md`。

### 13.1 环境（实测）

- WSL2 Debian 12，**PHP 8.2.32**，**Swoole 6.2.3**（`dev/install-swoole.sh` 从源码编译，`wsl -u root` 安装）。
- 分支 `260924-with-duckphp1.4.1dev`。
- 目标 PHP 从 7.4 变为 **8.2**（WSL 现状），代码用 `#[\ReturnTypeWillChange]` 保持 7.4 可解析。

### 13.2 Swoole 6.2.3 的实测事实（推翻了本文件早先的几条推断）

| 事项 | 实测结论 | 影响 |
|---|---|---|
| `swoole.enable_coroutine` / `swoole.enable_exit` | **这两个 ini 不存在**（`ini_get` 返回 false 是"不存在"而非"关闭"） | 别用它们做判断 |
| `swoole.use_shortname` | `On`（`go()`/`defer()` 可用） | 但仍应写全 `Swoole\Coroutine::defer()` |
| `Coroutine::getuid()` | **仍然存在**（本文件早先标"待验证"） | 但已统一改用 `getCid()` |
| `Coroutine::getPcid()` | 存在，链为 root(-1) → child(1) → grandchild(2) | `GetOwnerCid()` 的上溯依据 |
| **`ob_*` 是否按协程隔离** | **是**（两协程交错，各拿各的 `A1A2A3` / `B1B2B3`） | §5.5 的 ob 串扰担忧解除；只剩"`end()` 只能一次"这个真问题 |
| **真超全局是否按协程隔离** | **否**（A 读到 B 写的 `$_SERVER['MARKER']`） | **本节最重要的结论**，见 13.3 |
| 协程内 `exit()` | 抛 `Swoole\ExitException`，`getStatus()` 返回退出码，**worker 存活** | §9 的担忧解除；但仍需给 DuckPhp 注册空处理器 |
| `Runtime::enableCoroutine()` | 只有 1 个参数 `int $flags = SWOOLE_HOOK_ALL` | 无参调用即可 |
| `Coroutine::getContext()` | 子协程**不继承**父的 context | 所以没用它做 per-cid 存储，改用 `getPcid()` 上溯 |

### 13.3 ⚠️ 最重要的新结论：真超全局变量不隔离

实测（`dev/probe-superglobal.php`）：

```
A: $_SERVER[MARKER]='B'  $_GET[who]='B'   => !! 全世界共享 !!
B: $_SERVER[MARKER]='B'  $_GET[who]='B'
```

⇒ 本库的 `_SaveSuperGlobalAll()` 只能是**尽力而为的兼容层**（对"让出之前就读"的传统代码有效），
**对象存储 `SG()->_*`（DuckPhp 经 `__SUPERGLOBAL_CONTEXT`）才是协程安全的唯一真相**。
已在 README 与 `docs/duckphp-integration.md` §6 明确写出，并在测试里用并发探针固定这个行为。

### 13.4 本次新发现并修掉的致命 bug（本文件 §3.2 没列到的）

1. **会话门面指错类**：`session_start/session_destroy/session_set_save_handler` 调
   `SwooleSuperGlobal::G()->...`，而**这些方法在 `SwooleContext` 上**，`SwooleSuperGlobal` 一个都没有
   → 会话功能整体 fatal。实测 `get_class_methods()` 确认。
2. **`onShutdown()` 会杀掉 worker**：`$func = array_shift($v); $func($v);` 对
   `['类名','方法']` 取出的是字符串 `'类名'`，当成函数调用 → fatal → 顺着 Swoole 的
   request shutdown 把**整个 worker** 带走（实测日志 `php_swoole_server_rshutdown() ERRNO 503`）。
   而它只在"注册过 shutdown 函数"的请求上触发 —— 也就是**任何用了 session 的请求**。
3. **`EnableCurrentCoSingleton($cid)` 映射方向写反** → 该重载静默失效（配合漏掉的 `self::`）。
4. **`setcookie()`/`mt_rand()` 收到字符串** → PHP 8 严格类型下 TypeError（session 首次访问必崩）。
5. `ob_start` 回调方案的**空响应不 `end()`** → 客户端挂到超时（不只是"二次 end"）。

### 13.5 落地清单

| 交付物 | 说明 |
|---|---|
| `src/HttpServerForDuckPhp.php` | 实现 `DuckPhp\HttpServer\HttpServerInterface`；`--http_server=SwooleHttpd/HttpServerForDuckPhp` 即插即用 |
| `src/CoroutinePhaseContainer.php` | §12 方案落地：per-cid 完全独立容器、`install()`/`assertInstalled()`/`RestAllContainerForTesting()` 重写、share/renew 两份清单 |
| `src/Swoole404Exception.php` | 从 git 恢复 |
| `docs/duckphp-integration.md` | 集成 + 协程模型 + 限制的完整说明（含**闭包路由钩子会破坏隔离**的警告，用户裁定第 4 条） |
| `dev/` | 安装脚本 + 3 个行为探针 + 2 套端到端测试 + fixtures |
| `changelog.md` | 1.1.5 完整记录 |

### 13.6 验收结果

```
dev/test.sh          → 28 passed, 0 failed
dev/test-duckphp.sh  → 22 passed, 0 failed
```

关键断言（都真起服务器 + 真发 HTTP）：

- DuckPhp 路由、URL 生成（`DOCUMENT_ROOT`/`REQUEST_SCHEME` 补齐后 `/probe/route` 与域名都正确）
- `/probe/container` → `container_is_ours=true`、`app_is_shared=true`、`route_has_hooks=true`
  （证明 Route 的 clone 播种保住了路由钩子，且应用实例是同一个）
- `STATICS`/`GLOBALS` 每请求重置为 1
- session n=1→2→3
- 异常 → 500 且 worker 存活；`exit()` 只输出 `before-bye` 且 worker 存活
- **并发**：两个并行请求各自 `Helper::GET('tag')` 在让出前后都是自己的值；
  裸 `$_GET` 在让出后串了（符合 13.3 的预期，已在测试输出里标为 info）

### 13.7 仍未做（留给后续）

- **PHPUnit 测试**：`composer.json` 已声明 `require-dev: phpunit`，但真正的验证是两套 shell 端到端
  测试（对常驻服务器 + 并发场景更有意义）。若要做 PHPUnit，应新建用例而不是恢复旧的空壳。
- **`DbManager`/`RedisManager` 的 per-协程连接**：机制（`http_app_renew_classes`）已就位并文档化，
  但**没有接真实数据库做端到端验证**，也没做连接池。
- **WebSocket**：事件名已修，但未针对 6.2.3 做端到端测试。
- **`http_handler_root` / `http_handler_file` 两种模式的端到端测试**：只修了 bug，未写测试。
- **`app_class` 在 master 上的可变状态仍是进程级共享的**（设计如此，已在文档说明）。
