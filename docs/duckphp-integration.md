# 在 Swoole 上跑 DuckPhp 应用

本文说明 `SwooleHttpd\HttpServerForDuckPhp` 如何把 DuckPhp 应用接到 Swoole 常驻进程上，
以及**协程隔离到底做了什么、哪些用法有前提**。配套代码见 `src/CoroutinePhaseContainer.php`。

实测环境：Debian 12 (WSL2) / PHP 8.2.32 / Swoole 6.2.3 / `dvaknheo/duckphp` 1.4.1。
端到端验证：`bash dev/test-duckphp.sh`（22 项断言，含并发不串数据）。

---

## 1. 怎么用

DuckPhp 官方的插件位是 `DuckPhp\HttpServer\HttpServerInterface` +
`Component\Command::command_run()` 里的 `--http_server` 开关。本库实现该接口，所以直接：

```bash
php cli.php run --http_server=SwooleHttpd/HttpServerForDuckPhp --port=9528
```

也可以绕过 CLI 直接启动：

```php
require 'vendor/autoload.php';          // 含 dvaknheo/duckphp 与 dvaknheo/swoolehttpd

use SwooleHttpd\HttpServerForDuckPhp;

HttpServerForDuckPhp::RunQuickly([
    'host'            => '127.0.0.1',
    'port'            => 9528,
    'path'            => __DIR__.'/',    // 项目根（DuckPhp 的 path 选项）
    'path_document'   => 'public',       // 项目里的文档根；不存在则回退到项目根
    'http_app_class'  => \MyProj\System\App::class,
]);
```

`command_run()` 会把 `http_app_class` 和 `path` 一起传进来，所以走 CLI 时不用手写这两项。

### 服务器会用到 / 会产生的选项

| 选项 | 方向 | 说明 |
|---|---|---|
| `http_app_class` | 框架→本库 | DuckPhp 应用类名（`command_run` 填） |
| `path` | 框架→本库 | 项目根 |
| `host` / `port` | CLI→本库 | 监听地址；`-H/--host`、`-P/--port` |
| `path_document` | CLI→本库 | 文档根子目录名，默认 `public` |
| `workers` | CLI→本库 | 非空时设 `worker_num` |
| `silent_mode` | 本库 | 不打印启动信息 |
| `http_app_options` | 本库 | 额外透传给应用 `init()` 的选项 |
| `http_app_renew_classes` | 本库 | **每协程新建连接**而不是 clone 的组件类名列表（见 §4） |
| `swoole_server_options` | 本库 | 直接透传给 `Swoole\Http\Server::set()` |

---

## 2. 一次请求发生了什么

```
Swoole 'request' 事件（每个请求一个协程）
└─ SwooleHttpd::onRequest()
   ├─ SwooleCoroutineSingleton::EnableCurrentCoSingleton()   本请求的实例空间
   ├─ ob_start()                                             捕获 echo（见 §5）
   ├─ SwooleContext::G(new ...)->initHttp($request,$response) 绑定响应对象
   ├─ StaticReplacer::G(new ...)                             每请求干净的 global/static 空间
   ├─ SwooleSuperGlobal::G(new ...)->SaveSuperGlobalAll()     填充超全局（尽力而为，见 §6）
   └─ _OnServerRequest()
      ├─ 补齐 Swoole 不提供的 $_SERVER 键（DOCUMENT_ROOT/SCRIPT_FILENAME/REQUEST_SCHEME）
      └─ DuckPhp 应用 ->serve()      ← 注意不是 run()！见 §3
```

`serve()` 内部（DuckPhp `KernelTrait`）：

```
prepareServe()   框架留空的扩展点，本库不使用
onRequest()      options['on_request'] 回调
Route::run()     → 控制器
finally: Route::clear() / Runtime::clear()
```

---

## 3. 两个必须知道的 DuckPhp 行为

### 3.1 `PHP_SAPI === 'cli'`，所以永远调 `serve()`

Swoole 下 `PHP_SAPI` 就是 `'cli'`，而 DuckPhp 的 `cli_enable` 默认 `true`：

```php
// KernelTrait::run()
if ($this->options['cli_enable']) { return $this->execute(); }  // 控制台分支！
else                              { return $this->serve(); }
```

本库在 `initApp()` 里强制 `cli_enable = false`，并且每请求调 `serve()`，
所以**不会**跑去执行控制台命令。如果你自己写入口，也务必守住这两点。

### 3.2 `exit()` 是可用的

Swoole 里协程内 `exit()` 抛 `Swoole\ExitException`（**不会**杀掉 worker）。
但 DuckPhp 只认自己的 `__EXIT_EXCEPTION`，所以本库在 `initApp()` 里注册了一个空处理器：

```php
ExceptionManager::_()->assignExceptionHandler(Swoole\ExitException::class, function () {});
```

没有这一行，一个普通的 `exit` 会在真实输出之后**再渲染一个 500 页面**。

---

## 4. 协程隔离：`CoroutinePhaseContainer`

### 为什么需要它

DuckPhp 的所有组件单例都挂在**一个进程级静态**上：

```php
SingletonExTrait::_()  →  PhaseContainer::GetObject()  →  static::_()->_GetObject()
```

常驻进程 + 多协程 ⇒ 所有请求共用一套 `Route` / `Runtime` / `View`…… ⇒ 请求间串数据。
DuckPhp 有意把这件事留给服务器实现方（`prepareServe()` 里那个空 `$classes` 列表）。

### 做法：装一个按协程分身的 PhaseContainer

```php
CoroutinePhaseContainer::install($renew_classes = [], $shared_classes = [], $shared_instance_of = [$app_class]);
```

只有一个实例被装进 `PhaseContainer::$instance`，它内部**按协程 id 分派**：

| cid | 含义 | 行为 |
|---|---|---|
| `0` | 主进程（应用 `init()` 时） | 用父类自己的 `$containers/$current/$default`，于是 master 就是完整模板 |
| `> 0` | 请求协程 | 自己的桶副本，**从 master 逐个 `clone` 播种** |

`SwooleCoroutineSingleton::GetOwnerCid()` 负责"我是哪个协程"：沿 `Coroutine::getPcid()`
上溯到最近的、有自己实例空间的祖先。这样请求里 `go()` 出来的子协程**共用父请求的容器**，
而不是各自新建一个空容器（那会让 `Route::_()` 变成没注册过任何路由的裸对象）。

### 为什么是 `clone` 而不是 `new`

`Route` 这类组件**不是 new 出来就能用**：它的 `options` 和全部路由钩子都是 `init()` 时灌进去的，
而 `Route::clear()` **不会**回滚这些。空容器里 `new Route()` 会让路由彻底失效。

`clone` 之所以安全，是因为框架内部的所有路由钩子都注册成 **`[类名, '方法名']` 静态可调用**：

```php
// Component/RouteHookRouteMap.php:36
Route::_()->addRouteHook([static::class, 'PrependHook'], 'prepend-inner');
```

静态可调用**不捕获 `$this`**，所以 clone 之后钩子依然有效，而且钩子方法内部再取组件实例时
会走**本协程的容器**。

> ### ⚠️ 用户自己注册闭包式路由钩子会破坏隔离
>
> ```php
> // ✗ 不要这样：闭包捕获了 $this，clone Route 之后它仍指向主进程的那个对象
> Helper::addRouteHook(function ($path) { return $this->handle($path); });
>
> // ✓ 改成静态可调用
> Helper::addRouteHook([MyHook::class, 'handle']);
> ```
>
> `Helper::addRouteHook()`（`Foundation\System\SystemHelper::addRouteHook()`）是公开 API，
> 传闭包在框架内部不会出现，但你的代码里可能出现。**用闭包就等于把跨协程共享的那个对象
> 又接回来了** —— 单请求测试看不出来，并发下才会串。

### 连接类组件：`http_app_renew_classes`

`clone` **不会复制连接**（两个对象指向同一个 PDO / Redis 句柄），并发用同一连接并不安全。
所以把这类组件列进 `http_app_renew_classes`，它们会**每协程新建一个实例并重新 `init()`**：

```php
HttpServerForDuckPhp::RunQuickly([
    // ...
    'http_app_renew_classes' => [
        \DuckPhp\Component\DbManager::class,
        \DuckPhp\Component\RedisManager::class,
    ],
]);
```

代价是每个请求真的会新建连接；如果你有连接池方案，改成 clone 共享即可。

### 应用实例是**共享**的，不 clone

应用对象承载启动期状态（`options` / `setting` / 子应用登记），而且被按类名查找，
clone 会让 `App::_()` 和真正跑过 `init()` 的实例不一致。
本库用 `$shared_instance_of = [$app_class]` 把它按引用发给每个协程——
这也意味着**应用对象上的可变状态仍是全进程共享的**，别往它上面挂请求级数据。

### 两个已知限制

1. **`SwitchRootPhase()` 会写主进程的值。** 它直接赋值 `PhaseContainer::_()->current` /
   `->default`（public 属性），子类无法拦截。嵌套应用重设"谁是根"因此表现为进程级生效。
2. **`RestAllContainerForTesting()` 被改写了。** 父类实现是 `static::_(new static())`，
   那会**把 master 模板整个丢掉**，之后 `App::_()` 会新建一个没 init 过的应用（症状是莫名的
   Internal Error）。本库的版本只清协程副本，保留 master。
   另外提供了 `CoroutinePhaseContainer::assertInstalled()`，可以在请求入口兜底检查容器有没有被换掉。

---

## 5. 输出：每请求只 `end()` 一次

`Swoole\Http\Response::end()` 每个请求只能调一次。旧实现把它放在 `ob_start()` 的回调里，
而回调**每次缓冲区 flush 都会触发** —— 响应体超过缓冲、或用户 `flush()` 一下，就会二次
`end()` 丢数据；输出为空时又完全不会调 `end()`，客户端会一直挂到超时。

现在的做法：`ob_start()` 普通缓冲 → 请求收尾时收干所有缓冲 → `sendResponse()` 发**一次**。
`SwooleContext::$is_response_ended` 保证幂等；`sendfile()` 走的静态资源路径会
`markResponseEnded()` 提前标记。

**请求收尾的 defer 一律不抛异常**：用户注册的 shutdown 回调出错只会变成一次异常处理，
不会顺着 Swoole 的 request shutdown 把整个 worker 带走。

---

## 6. ⚠️ 真超全局变量在协程间**不隔离**

这是实测结论，不是推测。两个协程里各自写 `$_SERVER['MARKER']`、让出后再读：

```
A: $_SERVER[MARKER]='B'   => !! 全世界共享 !!
B: $_SERVER[MARKER]='B'
```

PHP 的超全局变量在 Swoole 下**没有按协程虚拟化**。所以：

- `SwooleHttpd` 在请求开始时把数据写进真超全局（`_SaveSuperGlobalAll()`），
  这是**给传统代码的尽力而为兼容层**；
- **协程安全的唯一真相是对象存储**：`SwooleHttpd::SG()->_GET` / `->_SERVER` / `->_SESSION`，
  以及 DuckPhp 通过 `__SUPERGLOBAL_CONTEXT` 走的同一条路。

因此：

| 场景 | 可用性 |
|---|---|
| 处理函数里**让出之前**读 `$_GET` / `$_SERVER` | 可用 |
| **让出之后**（DB 查询、`Co::sleep`、RPC 之后）读真超全局 | ❌ 可能读到别的请求 |
| 任何时候读 `SG()->_GET` / DuckPhp 的 `Helper::GET()` | ✅ 永远正确 |

DuckPhp 侧的 `Helper::GET()` / `Helper::SERVER()` / `Helper::SessionGet()` 都走
`SuperGlobal::_()` → `__SUPERGLOBAL_CONTEXT`，也就是我们的对象存储，**是安全的**。
实测并发探针确认：两个并发请求各自 `Helper::GET('tag')` 在让出前后都拿到自己的值。

### 会话

`SwooleContext::session_start()` 把数据写进对象存储（权威），同时给真 `$_SESSION` 一份副本，
好让"紧接着读 `$_SESSION`"的传统代码也能工作；`writeClose()` 持久化时，如果对象存储自加载后
一直没被动过、而真 `$_SESSION` 变了，就采用后者（即传统写法改的那份）。

但请记住上面的表格：**并发下直接改 `$_SESSION` 不可靠**，请用 `SG()->_SESSION`
或 DuckPhp 的 `Helper::SessionSet()` / `SessionGet()` / `SessionTrait`。

---

## 7. 验证

```bash
# 核心（不含 DuckPhp）
bash dev/test.sh

# DuckPhp 集成（含并发不串数据）
bash dev/test-duckphp.sh
```

两套都会真起服务器、真发 HTTP 请求、真跑并发，并逐项断言（当前 28 + 22 项全绿）。
`dev/probe-*.php` 是当初用来确认 Swoole 实际行为的探针脚本，改 Swoole 版本时值得重跑。
