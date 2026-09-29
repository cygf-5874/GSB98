replsafe 是 PHP 8 的正则替换封装（php-cli，无 composer，无第三方依赖），README「对外契约」有 10 条。自检走 `scripts/check.sh`，既有用例走 `php tests/run.php`，`repro.php` 是调用方样本。

任务：把 `src/Replacer.php` 修到 10 条契约全部成立，既有 18 个用例仍须全绿。

验收：
- php tests/run.php 退出码 0；
- php repro.php 退出码 0；
- php check/check.php 退出码 0，10 个场景全过（literal 3 + expand 3 + errors 2 + consist 1 + budget 1）。

约束：
1. 不改 `check/`、`repro.php`、`tests/run.php`、`src/PatternError.php`、`src/Meter.php`。
2. 不用 composer、第三方库、mbstring，不用 `@` 抑制 `preg_*`。
3. 公开签名不变；替换与切分必须遵守同一套组语义和单遍预算。
