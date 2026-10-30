# API HMAC v2

1067 起推荐新客户端使用 HMAC-SHA256 v2。接口路径、POST 表单参数、Scope、域名权限、IP 白名单和限流保持原有约定。旧 MD5 签名继续兼容；需要防重放和幂等保护时必须切换至 v2。

## 请求头

| 请求头 | 内容 |
| --- | --- |
| `X-Dnsmgr-Version` | 固定为 `2` |
| `X-Dnsmgr-Keyid` | 后台创建的 16 位 KeyID |
| `X-Dnsmgr-Uid` | 使用用户 API 时提供用户 ID，与 KeyID 二选一 |
| `X-Dnsmgr-Timestamp` | 10 位 Unix 秒，允许服务器时间前后 300 秒 |
| `X-Dnsmgr-Nonce` | 每次请求生成的新随机值，16–128 个字母、数字、`_` 或 `-` |
| `X-Dnsmgr-Signature` | HMAC-SHA256 的 64 位小写十六进制结果 |
| `Idempotency-Key` | 可选，同一业务操作保持不变，格式与 Nonce 相同 |

v2 的认证参数只放在请求头。请求体仍用 `application/x-www-form-urlencoded`，业务参数按原接口文档填写。原始请求体上限 2MB。

## 签名串

以下八项按顺序使用一个 LF（`\n`）连接，不加 BOM，不做 URL 解码，不转换请求体格式：

```text
DNSMGR-HMAC-SHA256
keyid:<KeyID> 或 uid:<用户ID>
POST
完整请求 URI（路径以及原样查询串）
SHA256(原始请求体字节) 的小写十六进制
时间戳
Nonce
Idempotency-Key（未使用则为空）
```

使用对应 Secret 对 UTF-8 编码的签名串做 HMAC-SHA256。最后一项为空时，签名串以 LF 结尾；有幂等键时，其后不添加 LF。请求 URI 不包含协议和主机名，例如 `/api/record/add/1` 或 `/api/account/data?deploy=1`。反向代理必须保留客户端签名的 URI。

先把业务参数编码成最终字节，再计算签名，并原样发送这些字节。签名后再序列化 JSON、重新编码表单、改变查询参数顺序，都会导致验签失败。

## Python 示例

以下示例只读取域名列表，使用 Python 标准库。凭据来自环境变量，避免把 Secret 写入源码。

```python
import hashlib
import hmac
import json
import os
import time
import uuid
from urllib.parse import urlencode
from urllib.request import Request, urlopen

BASE = os.environ["DNSMGR_URL"].rstrip("/")  # 例如 https://dns.example.com
KEYID = os.environ["DNSMGR_KEYID"]
SECRET = os.environ["DNSMGR_SECRET"]

def call(uri, params, operation_id=""):
    body = urlencode(params, doseq=True).encode("ascii")
    timestamp = str(int(time.time()))
    nonce = uuid.uuid4().hex  # 每一次网络请求都重新生成
    canonical = "\n".join([
        "DNSMGR-HMAC-SHA256", "keyid:" + KEYID, "POST", uri,
        hashlib.sha256(body).hexdigest(), timestamp, nonce, operation_id,
    ])
    signature = hmac.new(
        SECRET.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256
    ).hexdigest()
    headers = {
        "Content-Type": "application/x-www-form-urlencoded",
        "X-Dnsmgr-Version": "2", "X-Dnsmgr-Keyid": KEYID,
        "X-Dnsmgr-Timestamp": timestamp, "X-Dnsmgr-Nonce": nonce,
        "X-Dnsmgr-Signature": signature,
    }
    if operation_id:
        headers["Idempotency-Key"] = operation_id
    request = Request(BASE + uri, data=body, headers=headers, method="POST")
    with urlopen(request, timeout=60) as response:
        return json.load(response)

print(call("/api/domain/data", {"offset": 0, "limit": 20}))
```

## 防重放与幂等

同一凭据的 Nonce 只能使用一次。重复 Nonce 返回 HTTP 409，控制器不会再次执行。使用新的时间戳和 Nonce 重试时，仍需重新计算签名。

以下接口支持可选 `Idempotency-Key`：

- `/api/record/{add|update|delete|status|remark|batch|batchedit|batchadd|apply}/:id`
- `/api/batch/enqueue`
- `/api/batch/task/{cancel|retry}`
- `/api/cert/deploy/process`

其他接口携带幂等键会返回 HTTP 400。创建订单、快照、预览等未列出的接口不会隐式获得幂等保证。

首次写操作前，客户端生成并持久化一个业务操作 ID；同一操作的重试复用该 ID、方法、URI 和原始请求体，并使用新的 Nonce。服务器保存结果 7 天：

- 已确认完成：返回保存的 JSON 及 HTTP 状态，附加 `Idempotency-Replayed: true`，不再调用提供商。业务错误响应也会保存，仍应检查 JSON 的 `code`。
- 同一键被用于不同请求：HTTP 409。
- 授权范围已改变：HTTP 409；正常认证仍会先检查当前有效期、Scope 和 IP 权限。
- 首次操作仍在执行，或远端操作后进程中断、结果未能落库：HTTP 409，要求先核对远端状态。不能换新键盲目重试。

7 天是结果的最短保留窗口；清理由计划任务执行。删除过期回执后不再提供旧键的幂等保证，客户端不要复用已结束操作的 ID。幂等机制不能撤销已经发生的远端写入，也不能使多个不同请求组成跨平台事务。
