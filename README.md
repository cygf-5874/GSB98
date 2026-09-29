# replsafe

PHP 8 的「正则替换封装」：把 `preg_*` 上容易被误用的替换语义收敛成几条确定、可复用的接口。
只依赖 PHP 标准库（php-cli），不用 composer、不用第三方依赖、不用 mbstring。

## 快速开始

```php
require __DIR__ . '/autoload.php';

use Replsafe\Replacer;

Replacer::replace('price 42', '/(\d+)/', '$1');          // 'price $1'（默认字面）
Replacer::replace('price 42', '/(\d+)/', '<$1>', true);  // 'price <42>'（显式展开）
[$text, $n] = Replacer::replaceAll('a.b.c', '/\./', '-'); // ['a-b-c', 2]
Replacer::split('a,b,c', '/,/');                          // ['a', 'b', 'c']
```

## 自检

```bash
php tests/run.php            # 回归用例：全过打印「通过 18/18」并退 0
php repro.php                # 调用方最小复现：不再复现才退 0
bash scripts/check.sh        # 固定验收：10 个场景全过才退 0
bash scripts/check.sh -list  # 列出全部场景
bash scripts/check.sh --only literal
php -l src/Replacer.php      # 语法检查
```

## 对外契约（10 条）

1. **接口**（公开发签名固定，不得更改；可新增内部方法）

   ```php
   Replacer::replace(?string $subject, string $pattern, string $replacement,
                     bool $expandRefs = false, bool $utf8Mode = false): string
   Replacer::replaceAll(同上): array          // 返回 [$result, $count]
   Replacer::split(string $subject, string $pattern, int $limit = -1): array
   ```

   `$subject` 按字节处理（`string`，不是「字符」）；`$pattern` 含定界符与修饰符，原样交给 PCRE。

2. **默认字面**：`$expandRefs = false` 时，`$replacement` 里的 `$`、`\` 一律**字面**处理——
   用户给什么就输出什么，不得被解释成反向引用或转义序列。`$1`、`$0`、`$1.00 $`、`C:\snap\1`
   都原样输出。

3. **显式展开**：`$expandRefs = true` 时才展开引用。以下写法**等价**，对同一个捕获组给出同一结果：
   - 编号引用 `$1..$99` 与 `\1..\99`；数字按最长数字串解析，超出组数即「不存在的组」；
   - `$0` / `\0` 表示整段匹配；
   - 命名引用 `${name}`（`name` 为 `[A-Za-z_][A-Za-z0-9_]*`）取具名捕获组，也支持 `${1}` 这类编号写法。

   **不存在的组展开为空串**（不是原文、不是 `null`）。`$` / `\` 后面既不是数字也不是 `{` 时，
   该字符按字面输出。

4. **字节语义**：偏移与长度一律按**字节**。默认**不加 `/u` 修饰符**，因此二进制主体必须可用；
   `$utf8Mode = true` 时才加 `/u`，此时若主体不是合法 UTF-8 → 抛 `PatternError`。

5. **null 主体**：`$subject === null` → 抛 `TypeError`（PHP 8.1 起 `preg_*` 的 `null` 已废弃，
   本项目明确拒绝，不静默当空串）。

6. **非法 pattern**：`$pattern` 非法（PCRE 编译失败）→ 抛 `PatternError`，消息里必须带上
   **原始 PCRE 错误文本**（形如 `Compilation failed: missing terminating ] for character class at offset N`）
   与**错误码**（`preg_last_error()` 返回的整数）。**不得用 `@` 抑制 `preg_*`** 后再把 `null` / `false`
   拼进结果；不许把 `null` 当合法返回值。

7. **计数**：`$count` 是实际替换次数（0 也要正确返回）；同一 pattern 的连续调用之间不共享状态。

8. **split 的组语义与 `replaceAll` 一致**：`Replacer::split()` 按 `$pattern` 切分 `$subject`；
   `$pattern` 里出现捕获组时，每个分隔符匹配的捕获组文本按**编号顺序**插在该分隔符前后两个片段之间，
   编号规则与 `replaceAll` 的 `$N` **完全一致**（编号组按左括号顺序，具名组取其所在位置的编号）；
   某次匹配里未参与匹配的组插入空串。PCRE 若在某次匹配里省略了尾部未绑定的组，这些组不插入
   （与 `preg_split(..., PREG_SPLIT_DELIM_CAPTURE)` 的行为一致）。
   `$limit`：`< 1` 表示不限制；`>= 1` 表示最多产生 `$limit` 个片段，剩余主体原样留在最后一个片段里。

9. **单遍预算**：`replaceAll` 对主体字节的访问次数（累计进 `Meter::$subjectBytes`，
   实现每读取主体的一段连续字节就 `Meter::count(长度)`）必须
   `>= strlen($subject)`（计数必须真实）且 `< 6 × strlen($subject)`。
   **不得对每个匹配重新扫描整个主体**——禁止「先拿到全部匹配、再为每个匹配从主体头部重新截一遍」这类写法。
   固定件在调用前先 `Meter::reset()`。

10. **确定性**：结果与 `ini` 设置、默认字符集、`PREG_UNMATCHED_AS_NULL` 无关；
    三个入口都是**纯函数**，不共享状态、不修改入参、不依赖 PHP 数组的 hash 顺序。

## 目录

```
README.md          本文件
PROMPT.md          出题用的 User Prompt
autoload.php       极简 spl_autoload_register（__DIR__ 相对定位 src/）
src/Replacer.php   替换 / 展开 / 切分的实现
src/PatternError.php  PCRE 层错误类型（固定，别改）
src/Meter.php      单遍预算计数器（固定入口，别改字段名）
tests/run.php      既有回归用例（18 个，别改）
repro.php          调用方最小复现（别改）
check/check.php    固定验收程序（别改）
scripts/check.sh   固定验收的 shell 入口
```
