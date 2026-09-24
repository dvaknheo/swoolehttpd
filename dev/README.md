# dev/ — 开发与验证脚本

`tests/` 下原来的 `*Test.php` 是 `tests/bootstrap.php` 里 `TestFileGenerator` 用正则扫
`src/*.php` 自动生成的**空壳**（断言全在注释块里，还引用了 2021 年就删掉的
`SwooleExt*` / `Swoole404Exception` 等类）。它们从来没有真正断言过任何东西，已于 1.1.5 移除。

真正的验证在这里，都是**起真服务器 + 发真 HTTP 请求**：

```bash
bash dev/test.sh          # SwooleHttpd 核心（28 项断言）
bash dev/test-duckphp.sh  # DuckPhp 集成（22 项断言，含并发不串数据）
```

也可以 `composer test`。

## 需要 PHP + swoole 的 WSL 环境

```bash
# 一次性：编译安装 swoole（需要 root；WSL 下可用 wsl -u root）
wsl -u root -e bash -lc "bash /mnt/e/ProjectGoat/swoolehttpd/dev/install-swoole.sh"
```

## 文件说明

| 文件 | 用途 |
|---|---|
| `install-swoole.sh` | 从源码编译安装 swoole 到 WSL 的 PHP（默认 6.2.3） |
| `env-check.sh` | 体检：PHP / swoole / composer / 工具链 |
| `probe-swoole.php` | 探测 swoole 的 API 面（方法、常量、ini、shortname） |
| `probe-concurrency.php` | 探测 `ob_*` 协程隔离、`exit()` 行为、`getContext()` 继承 |
| `probe-superglobal.php` | 探测**真超全局变量是否按协程隔离**（结论：不隔离） |
| `test.sh` | 核心端到端测试 |
| `test-duckphp.sh` | DuckPhp 集成端到端测试 |
| `fixtures/app.php` | 核心测试用的示例应用（http_handler 模式） |
| `fixtures/duckphp/` | 最小 DuckPhp 项目（System/App + Controller） |
| `fixtures/duckphp-server.php` | 用 `HttpServerForDuckPhp` 起那个项目 |

改 Swoole 版本时，建议先重跑三个 `probe-*.php`，因为它们记录的正是版本相关的行为假设。
