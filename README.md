# SwooleHttpd

## 1.1.5 复兴版：现在真的能跑了

这个库从 2021 年起就处于"看着完整、实际跑不起来"的状态（拼写错误、指向已删除类的测试、
对接的是 2019 年的 DuckPhp API）。1.1.5 把它修好并接到新版 DuckPhp 上。

**实测环境**：Debian 12 (WSL2) / PHP 8.2.32 / Swoole 6.2.3 / `dvaknheo/duckphp` 1.4.1。
**端到端验证**：`bash dev/test.sh`（28 项）与 `bash dev/test-duckphp.sh`（22 项，含并发不串数据），当前全绿。

### 在 Swoole 上跑 DuckPhp 应用

```bash
php cli.php run --http_server=SwooleHttpd/HttpServerForDuckPhp --port=9528
```

`HttpServerForDuckPhp` 实现 DuckPhp 官方的 `DuckPhp\HttpServer\HttpServerInterface`，
所以走的是框架给的插件位。详见 **[docs/duckphp-integration.md](docs/duckphp-integration.md)**。

### ⚠️ 三条必须先知道的事

1. **真超全局变量在协程间不隔离。** PHP 的 `$_GET`/`$_SERVER`/`$_SESSION` 在 Swoole 下
   是所有协程共享的（已实测）。请求开始时会把数据写进真超全局，但那只对
   **"让出之前就读"的传统代码**有效；一旦 `Co::sleep`、查库、RPC 之后再读就可能串。
   **协程安全的唯一真相是 `SwooleHttpd::SG()->_GET` / `->_SERVER` / `->_SESSION`。**
   DuckPhp 走 `__SUPERGLOBAL_CONTEXT`，读的正是这个对象存储，所以是安全的。
2. **每请求只 `end()` 一次响应。** 用 `ob_start()` 缓冲，收尾时发一次；
   `sendfile()` 之类的路径会提前标记，不会再二次 `end()`。
3. **协程隔离靠 `CoroutinePhaseContainer`。** 它给每个请求协程一份**独立**的组件容器
   （含 `#public` 桶），结构信息播种 + 组件 `clone`；连接类组件请在
   `http_app_renew_classes` 里声明，它们每协程**新建连接**。
   **自己注册闭包式路由钩子会破坏隔离** —— 原因和改法见集成文档第 4 节。

### 1.1.5 修掉的主要问题

| 问题 | 后果 |
|---|---|
| `SwooleContext.php` 里 `use Swool\Coroutine`（少个 e） | `session_start()` 首次访问必然 `Class not found` 崩 |
| `session_*` 门面调 `SwooleSuperGlobal` 上的方法，而那些方法在 `SwooleContext` 上 | 会话功能整体不可用 |
| `$server->on('mesage', ...)` 拼错 | WebSocket 收消息永久失效 |
| `onShutdown()` 把 `[类名,'方法']` 当函数名调用 | **整个 worker 被杀** |
| `ob_start` 回调里调 `response->end()` | 大响应二次 `end()` 丢数据；空响应不 `end()` 挂到超时 |
| `setcookie` / `mt_rand` 收到 ini 的字符串值 | 严格类型下抛 TypeError |
| `http_handler_file` 模式返回 null | 入口文件跑完后又追加 404 |
| `StaticReplacer` 不在动态组件列表 | `GLOBALS()`/`STATICS()` 跨请求泄漏 |
| `base_class` 选项声明了却从不读 | 文档化的功能根本不存在 |
| `regShutDown`/`regShutdown` 与 cid 映射方向写反 | 子协程拿到错误实例 |

还有一批**幻影 API**（README 和自动生成的测试引用、代码里却没有）已经补上或删掉：
`SG()` / `ThrowOn()` / `Throw404()` / `exit_request()` / `set_http_404_handler()` /
`Swoole404Exception` 已实现；`SwooleExt*` 那套旧接口和 `src/SimpleHttpd.php` 已删除。
`tests/` 里那些自动生成的空壳测试也已移除 —— 真正的验证在 `dev/` 下。

---

## SwooleHttpd 是什么

SwooleHttpd 致力于 Swoole 代码和 fpm 平台 代码几乎不用修改就可以双平台运行。
是对 swoole_http_server 类的一个包裹。

SwooleHttpd 原先来自 PHP 框架 DuckPhp 的前身 DNMVCS。不对外引用其他 PHP 代码，简单可靠。
但是 SwooleHttpd 是设计成几乎和 DuckPhp 无关的Swoole 框架，所以我把他剥离了。

理论上应该是是高性能的

## 特色

直接使用超全局变量， 直接用 echo 输出。

最方便旧代码迁移。

当然， fpm 方式的代码还没那么简单就代替，我们动用 SwooleHttpd::GLOBALS() 代替全局变量 ,SwooleHttpd::STATICS()代替 静态变量 SwooleHttpd::CLASS_STATICS() 代替类内静态变量

还有对系统函数的封装 SwooleHttpd::header(),SwooleHttpd::setcookie() 等。

尤其是 session 方面的 SwooleHttpd::session_start() swoole_http_server 最常碰到的基本问题。

最后一个没法处理的： require ,include   ，以及重复包含文件导致 函数的重复。

要处理这些，需要动用到 php-parser ， 写个 SwooleHttpd::PHPFile(),或者 SwooleHttpd::require() SwooleHttpd::include() 想解决。不想折腾太大，所以没去折腾。

## 基本应用

### 使用方法：

```shell
composer require dvaknheo/swoolehttpd
```

```php
<?php
use SwooleHttpd\SwooleHttpd;
require(__DIR__.'/../autoload.php');
function hello()
{
    echo "<h1> hello ,have a good start.</h1><pre>\n";
    var_export($_SERVER,$_GET,$POST,$_REQUEST,$_COOKIE, $_SESSION);
    echo "</pre>";
    return true;
}

$options=[
    'port'=>9528,
    'http_handler'=>'hello',
];
SwooleHttpd::RunQuickly($options);
```

浏览器打开 http://127.0.0.1:9528/
这个例子展现了 $_SERVER 里有的东西

### 选项

RunQuickly 的默认选项（就是 `SwooleHttpd::$options`，没有 `DEFAULT_OPTIONS` 常量）：

```php
public $options = [
        'host'=>'127.0.0.1',            // IP
        'port'=>8080,                   // 端口
        'swoole_server'=>null,          // 传入现成的 Swoole\Http\Server 对象；留空则用 host,port 新建
        'swoole_server_options'=>[],    // 透传给 Swoole\Http\Server::set()

        'http_app_class'=>null,         // DuckPhp 应用类（配 HttpServerForDuckPhp 用）
        'http_app_options'=>[],         // 额外透传给该应用 init() 的选项
        'http_app_path_document'=>'public', // 项目里的文档根；不存在则回退到项目根
        'http_app_renew_classes'=>[],   // 每协程新建连接（而非 clone）的组件类名

        'http_handler'=>null,           // 启动方法，返回 false 表示 404
        'http_handler_basepath'=>'',    // 基础目录，搭配 http_handler_root / http_handler_file
        'http_handler_root'=>null,      // PHP 目录模式
        'http_handler_file'=>null,      // 映射所有 URI 到单一文件模式
        'http_exception_handler'=>null, // 异常处理回调
        'http_404_handler'=>null,       // 404 的处理回调

        'with_http_handler_root'=>false,// http_handler 返回 false 后继续走目录模式
        'with_http_handler_file'=>false,// 目录模式没命中后继续走单文件模式

        'enable_fix_index'=>true,       // http_handler 模式下，修正 index.php
        'enable_path_info'=>true,       // http_handler_root 允许 path_info
        'enable_resource_file'=>true,   // http_handler_root 允许发送资源文件

        'websocket_open_handler'=>null,
        'websocket_handler'=>null,
        'websocket_exception_handler'=>null,
        'websocket_close_handler'=>null,

        'base_class'=>'',               // 用另一个类接管初始化（1.1.5 起真正生效）
        'silent_mode'=>false,           // 不在命令行提示服务启动信息
        'enable_coroutine'=>true,       // 调用 \Swoole\Runtime::enableCoroutine()
];
```

### 难度级别

从难度低到高，大概是这样的级别以实现目的

1. 使用默认选项实现目的
2. 只改选项实现目的
3. 调用 SwooleHttpd 类的静态方法实现目的
4. 调用 SwooleHttpd 类的动态方法实现目的
5. ---- 初级程序员和高级程序员分界线 ----
6. 使用入口类扩展
7. 调用扩展类，组件类的动态方法实现目的
8. 继承接管特定类实现目的
9. 魔改，硬改 SwooleHttpd 的代码实现目的

### 三种模式

SwooleHttpd 有三种模式

1. `http_handler`

    主要模式
    所有url请求都到这个回调处理。
    这模式和后面两种模式的区别，就是不搜索文件
    with_http_handler_root 打开时，http_handler 返回 false 后继续进入 http_handler 搜索文件运行
2. `http_handler_root`

    这和 document_root 一样。读取php文件，然后运行的模式。
    注意重复包含类会导致异常.
    with_http_handler_file  打开时 找不到文件会进入 http_handler_file 处理。
    enable_resource_file 允许读取资源文件，如图片，将会在浏览器显示图片（走 `sendfile()`）。
3. `http_handler_file`

    这种模式是把 url 都转向 文件如 index.php 来处理。

### 常用静态方法

常用静态方法，基本都要用到的静态方法

RunQuickly(array $options=[],callable $after_init=null)

    入口，等价于 SwooleHttpd::G()->init($options)->run();
    如果 after_init不为 null 将会在 init 后执行
ThrowOn($flag,$message,$code=0)

    如果 flag 成立抛出异常
    和 DuckPhp 不同的是，这里抛出 SwooleException。
Server()

    获得当前 swoole_server 对象
Request()

    获得当前 swoole_request 对象
    返回 SwooleContext::G()->request
Response()

    获得当前 swoole_response 对象
    返回 SwooleContext::G()->response
OnShow404()

    处理404的通用方法，选项 http_404_handler 优先使用
OnException($ex)

    异常的处理方法，选项 http_exception_handler 优先使用

### 超全局变量静态方法

代替超全局变量，基本由 SwooleSuperGlobal 的动态方法实现
高级程序员可以由接管 SwooleSuperGlobal 以实现自己的解决方式。

&GLOBALS($k,$v=null)

    全局变量 global 语法的替代方法
    返回 SwooleSuperGlobal::G()->STATICS($k,$v)
&STATICS($k,$v=null)

    静态变量 static 语法的替代方法
    返回 SwooleSuperGlobal::G()->_STATICS($k,$v)
&CLASS_STATICS($class_name,$var_name)

    类内静态变量 static 语法的替代方法
    $class_name 传入类名，以确定是 self::class 还是 static::class
    返回 SwooleSuperGlobal::G()->_CLASS_STATICS($class_name,$var_name)

### 系统封装静态方法

对应PHP手册的函数的全局函数的替代，因为相应的同名函数在 Swoole环境下不可用。
特殊函数 system_wrapper_get_providers 介绍了有多少系统替换函数。
所有这些静态方法都是调用动态方法实现，以方便修改。

system_wrapper_get_providers()

    特殊方法，对外提供本类有的系统封装函数
exit($code=0)

    特殊方法，对应  exit() 语法。退出系统，swoole 里，直接 exit 也是可以的。
header(string $string, bool $replace = true , int $http_status_code=0)

    header 函数
setcookie(string $key, string $value = '', int $expire = 0 , string $path = '/', string $domain  = '', bool $secure = false , bool $httponly = false)

    设置 cookie
set_exception_handler(callable $exception_handler)

    设置异常函数
register_shutdown_function(callable $callback,...$args)

    退出关闭函数
session_start(array $options=[])

    开始 session
session_destroy()

    结束 session
session_set_save_handler(\SessionHandlerInterface $handler)

    设置 session_handler

### WebSocket 服务器方法
Frame
Fd
IsClosing
### 高级静态方法

这些静态方法，初学者可以忽略

static G($object=null)

    G 函数，可替换单例。
ReplaceDefaultSingletonHandler()

    替换单例实现
    实质返回 SwooleCoroutineSingleton::ReplaceDefaultSingletonHandler();
EnableCurrentCoSingleton()

    开启协程内单例，比如 \go 函数里你需要用到自己的协程单例。
    实质返回 SwooleCoroutineSingleton::EnableCurrentCoSingleton();

### 单例模式

SwooleHttpd  use trait SingletonEx 。
SingletonEx 定义了静态函数 G($object=null)   ，如果默认参数的话得到当前单例。
如果传入  $object 则替换单例，实现调用方式不变，实现方式改变的效果。

SwooleHttpd::  通过使用 SwooleCoroutineSingleton 进一步扩展了 SingletonExTrait （ 通过 \_\_SINGLETONEX_REPALACER 宏 ）
实现了协程内单例。

如果协程内没单例，会查找全局的单例（$cid=0）的

在协程结束时候，会自动清理所有协程单例。

### 超全局变量和语法代替静态方法

swoole 的协程使得 跨领域的 global ,static, 类内 static 变量不可用，
我们用替代方法

```php
<?php
use SwooleHttpd\SwooleHttpd as DN;
require (__DIR__.'/../autoload.php');

global $n;
// =>
$n=&DN::GLOBALS('n');

static $n;
// =>
$n=&DN::STATICS('n');  //别漏掉了 &

$n++;
var_dump($n);

class B
{
    protected static $var=10;
    public static function foo()
    {
        //static::$var++;
        //var_dump(static::$var);
        $_=&DN::CLASS_STATICS(static::class,'var');$_   ++;
        // 把 static::$var 替换成  $_=&DN::CLASS_STATICS(static::class,'var');$_
        //别漏掉了 &
        var_dump(DN::CLASS_STATICS(static::class,'var')); // 没等号或 ++ -- 之类非左值不用 &
    }
}
class C extends B
{
    protected static $var=100;
}
C::foo();C::foo();C::foo();
```

输出

```text
int(1)
int(101)
int(102)
int(103)
```

## 高级内容

前面是使用者知道就够的内容，后面是高级内容了

### SwooleHttpd 的其他对外动态方法

init($options=[])

    初始化，这是最经常子类化完成自己功能的方法。
    你可以扩展这个类，添加工程里的其他初始化。
run()

    运行，运行后进入 swoole_http_server
set_http_exception_handler($ex)

    设置异常
exit_request($code=0)

    退出当前请求，等同于 exit
getDynamicClasses()

    获取动态类 http_handler 模式

forkMasterInstances($classes,$exclude_classes=[])

    把master 实例clone 到当前协程。exclude_classes 表示，如果是 克隆的实例有当前的类，则跳过不克隆
resetInstances()

    重置 协程为0 的实例覆盖到当前协程，用空的实例，而不是原有实例。

### 简单 HTTP 服务器

SwooleHttpd 用的 trait SwooleHttpd_SimpleHttpd .
单独使用这个 trait 你可以实现一个 http 服务器

    protected function onHttpRun($request,$response){}
    protected function onHttpException($ex){}
    protected function onHttpClean(){}
    public function onRequest($request,$response)
    初始化 SwooleContext 和一些处理。

### 协程单例方法

不常用方法，主要提供给 init 前调用

    getDymicClasses()
    createCoInstance($class,$object)
    forkMasterInstances($classes,$exclude_classes=[])
    resetInstances()

### SwooleException extends \Exception

空类，异常类

### Swoole404Exception extends \Exception

空类，404 异常

### SwooleSingleton

共享 trait

SwooleHttpd  重写了 可变单例 G 函数的实现，使得做到协程单例。

### class SwooleCoroutineSingleton

    用于协程单例,把主进程单例复制到协程单例
    public static function ReplaceDefaultSingletonHandler() //替换默认Handler
    public static function SingletonInstance($class,$object) // handelr 实现
    public static function GetInstance($cid,$class)
    public static function SetInstance($cid,$class,$object)
    public static function DumpString()  // 打印
    public function cleanUp()
    public function forkMasterInstances($classes,$exclude_classes=[]) //把主协程的实例扩充到协程
    public function forkAllMasterClasses() // 主协程的 G 都扩充到 协程， 和上面例子不同的是...
    public function _DumpString()
    public static function Dump() 

### class SwooleContext

    协程单例。Swoole 的运行信息
    public function initHttp($request,$response)
    public function initWebSocket($frame)
    public function cleanUp()
    public function onShutdown()
    public function regShutDown($call_data)
    public function isWebSocketClosing()

### class SwooleSuperGlobal

    SwooleSuperGlobal 是 Swoole 下 超全局变量 的实现。
    同时处理 session
    调用 SwooleSessionHandler ,
    public $is_inited=false;
    public function init()
    public function &_GLOBALS($k, $v=null)
    public function &_STATICS($name, $value=null, $parent=0)
    public function &_CLASS_STATICS($class_name, $var_name)
    public function session_set_save_handler($handler)
    public function session_start(array $options=[])
    public function session_id($session_id=null)
    public function session_destroy()
    public function writeClose()
    public function create_sid()

### SwooleSessionHandler implements \SessionHandlerInterface

因为默认的 SessionHandler 不能直接用，这里做文件实现版本的 SessionHandler 。

如果你有自己的 SessionHandler ，用 SwooleHttpd::session_set_save_handler() 安装;

## 代码解读

### 基本流程 init()

    1. 如果有 base_class，把当前单例换成该类的实例，并转交给它的 init()（1.1.5 起生效）
    2. 载入选项；server 对象可由 host/port 新建，也可由 $server 形参或
       'swoole_server' 选项注入
    3. $server->set(swoole_server_options)；注册 request / open / message 事件
    4. 若配置了 http_app_class，则 initApp()：装 CoroutinePhaseContainer、
       以 cli_enable=false 初始化 DuckPhp 应用、把系统函数指向 Swoole、
       给 Swoole\ExitException 注册空处理器
    5. Runtime::enableCoroutine()；SwooleCoroutineSingleton::ReplaceDefaultSingletonHandler()
    
    关于协程隔离与 DuckPhp 对接，见 docs/duckphp-integration.md。

### 基本流程 run()

    如果不是安静模式，则打印相关信息
    $this->server->start();     阻塞，直到服务器退出

### 基本流程 onRequest()

    onRequest 实现于 trait SwooleHttpd_SimpleHttpd
    一开始就 defer 手动 gc
    SwooleCoroutineSingleton::EnableCurrentCoSingleton 开启本请求协程的实例空间
    
    ob_start() 捕获直接 echo 的输出（收尾时一次性 end()，见 docs 第 5 节）
    
    SwooleContext 初始化
    SwooleSuperGlobal::G 初始化
    
    注意代码 SwooleSuperGlobal::G(new SwooleSuperGlobal());
    为什么不是 SwooleSuperGlobal::G()；
    因为要确保 SwooleSuperGlobal::G() 得到的单例是 协程内的单例。
    
    接下来 正常流程  onHttpRun 处理 http 业务
    出异常则 onHttpException  处理异常。
    
    流程结束后，进入前面 defer 流程里处理善后
    包括 伪 regist_shutdown_function  处理
    其他信息则  onHttpClean 处理
    SwooleContext 善后处理
    关闭 response;
    （这个 defer 折腾了一段时间处理顺序，没 bug 就暂时不要动了。）

### 基本流程 onHttpClean()

    SwooleHttpd onHttpClean
    处理 autoload ，防止 http_handler_root/http_handler_file 模式多次载入 spl_autoload

### 基本流程 onHttpException($ex)

    这个很简单
    如果是 \Swoole\ExitException 异常， 不用处理
    如果是  Swoole404Exception 则 static::OnShow404();
    否则 static::OnException($ex);

### 基本流程 onHttpRun

    主要流程。
    保存 spl_autoload_functions

如果 http_handler 模式

    关闭自动清理 autoload
    处理选项  enable_fix_index
    运行 http_handler
    如果得到的是 false 而且非 with_http_handler_root，非 http_handler_file 则404
    否则打开自动清理 autoload，继续

如果 http_handler_root 模式

如果 http_handler_file 模式

### DuckPhp handler 相关。

## WebSocket(测试中)

### 配置

```php
        //* websocket 在测试中。未稳定
        'websocket_open_handler'=>null,     // websocket 打开
        'websocket_handler'=>null,          // websocket  处理
        'websocket_exception_handler'=>null,// websocket 异常处理
        'websocket_close_handler'=>null,    // websocket 关闭
```

### 静态方法

Frame()

    获得当前 frame （websocket 生效 ）  
FD()

    获得当前 fd  （websocket 生效）
IsClosing()

    判断是否是关闭的包 （websocket 生效）

### 简单 websocket 服务器

SwooleHttpd 用的 trait SwooleHttpd_WebSocket .
单独使用这个 trait 你可以实现一个 websocket 服务器

onRequest($request,$response)

    //
onOpen(swoole_websocket_server $server, swoole_http_request $request)

    //
onMessage($server,$frame)

    //
没有 OnClose 。
