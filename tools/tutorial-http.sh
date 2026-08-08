#!/usr/bin/env sh
# 教程 curl 助手：统一显示响应正文与 HTTP 状态，并安全提取 JSON 字段 / token。
#
# 用法（在 engine/ 内）：
#   . ../tools/tutorial-http.sh
#   tutorial_http_token '后台登录' POST "$BASE/api/admin/authenticate" \
#     -H 'Content-Type: application/json' \
#     -d '{"account":"13800000000","password":"admin888"}'
#   TOKEN=$TUTORIAL_HTTP_TOKEN
#
# 公开结果变量：
#   TUTORIAL_HTTP_BODY / TUTORIAL_HTTP_STATUS / TUTORIAL_HTTP_VALUE / TUTORIAL_HTTP_TOKEN

TUTORIAL_HTTP_BODY=''
TUTORIAL_HTTP_STATUS=''
TUTORIAL_HTTP_VALUE=''
TUTORIAL_HTTP_TOKEN=''

tutorial_http_error() {
    printf '❌ [教程 HTTP] %s\n' "$*" >&2
}

tutorial_http_request() {
    if [ "$#" -lt 2 ]; then
        tutorial_http_error 'tutorial_http_request 至少需要 METHOD 和 URL。'
        return 2
    fi

    _tutorial_http_method=$1
    _tutorial_http_url=$2
    shift 2

    TUTORIAL_HTTP_BODY=''
    TUTORIAL_HTTP_STATUS=''
    TUTORIAL_HTTP_VALUE=''

    if ! _tutorial_http_result=$(curl -sS \
        -w '\n__MOO_TUTORIAL_HTTP_STATUS__:%{http_code}' \
        -X "$_tutorial_http_method" "$_tutorial_http_url" "$@"); then
        tutorial_http_error "请求发送失败：$_tutorial_http_method $_tutorial_http_url"
        tutorial_http_error '请确认服务仍在运行、URL 与端口正确。'
        return 1
    fi

    TUTORIAL_HTTP_STATUS=${_tutorial_http_result##*__MOO_TUTORIAL_HTTP_STATUS__:}
    TUTORIAL_HTTP_BODY=${_tutorial_http_result%__MOO_TUTORIAL_HTTP_STATUS__:*}

    printf '\n[%s %s]\n%s\nHTTP %s\n' \
        "$_tutorial_http_method" "$_tutorial_http_url" \
        "$TUTORIAL_HTTP_BODY" "$TUTORIAL_HTTP_STATUS"
}

tutorial_http_expect() {
    if [ "$#" -ne 1 ]; then
        tutorial_http_error 'tutorial_http_expect 需要一个期望状态码。'
        return 2
    fi

    if [ -z "$TUTORIAL_HTTP_STATUS" ]; then
        tutorial_http_error '还没有可断言的响应，请先运行 tutorial_http_request。'
        return 1
    fi

    if [ "$TUTORIAL_HTTP_STATUS" != "$1" ]; then
        tutorial_http_error "期望 HTTP $1，实际 HTTP $TUTORIAL_HTTP_STATUS。"
        return 1
    fi

    printf '✅ HTTP %s 符合预期。\n' "$1"
}

tutorial_http_call() {
    if [ "$#" -lt 3 ]; then
        tutorial_http_error 'tutorial_http_call 需要 EXPECTED_STATUS、METHOD、URL 和可选 curl 参数。'
        return 2
    fi

    _tutorial_http_expected=$1
    shift

    tutorial_http_request "$@" || return 1
    tutorial_http_expect "$_tutorial_http_expected"
}

tutorial_http_json() {
    if [ "$#" -ne 1 ]; then
        tutorial_http_error 'tutorial_http_json 需要一个点分 JSON 路径，例如 data.token。'
        return 2
    fi

    if ! command -v php >/dev/null 2>&1; then
        tutorial_http_error '找不到 php，无法解析 JSON 响应。'
        return 1
    fi

    TUTORIAL_HTTP_VALUE=$(printf '%s' "$TUTORIAL_HTTP_BODY" | php -r '
        $value = json_decode(stream_get_contents(STDIN), true);
        if (! is_array($value)) {
            fwrite(STDERR, "响应正文不是有效 JSON。\n");
            exit(1);
        }
        foreach (explode(".", $argv[1]) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                fwrite(STDERR, "响应中没有 JSON 路径 {$argv[1]}。\n");
                exit(1);
            }
            $value = $value[$segment];
        }
        if (is_array($value)) {
            echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            echo $value ? "true" : "false";
        } elseif ($value !== null) {
            echo (string) $value;
        }
    ' "$1") || {
        TUTORIAL_HTTP_VALUE=''
        tutorial_http_error "无法提取 JSON 路径 $1。"
        return 1
    }

    if [ -z "$TUTORIAL_HTTP_VALUE" ]; then
        tutorial_http_error "JSON 路径 $1 的值为空。"
        return 1
    fi
}

tutorial_http_token() {
    if [ "$#" -lt 3 ]; then
        tutorial_http_error 'tutorial_http_token 需要 LABEL、METHOD、URL 和可选 curl 参数。'
        return 2
    fi

    _tutorial_http_label=$1
    _tutorial_http_method=$2
    _tutorial_http_url=$3
    shift 3
    TUTORIAL_HTTP_TOKEN=''

    tutorial_http_request "$_tutorial_http_method" "$_tutorial_http_url" "$@" || return 1
    tutorial_http_expect 200 || {
        tutorial_http_error "$_tutorial_http_label 失败，先根据上面的响应正文排查。"
        return 1
    }
    tutorial_http_json data.token || {
        tutorial_http_error "$_tutorial_http_label 虽返回 200，但没有可用的 data.token。"
        return 1
    }

    TUTORIAL_HTTP_TOKEN=$TUTORIAL_HTTP_VALUE
    printf '✅ %s已拿到 token（长度 %s）。\n' \
        "$_tutorial_http_label" "${#TUTORIAL_HTTP_TOKEN}"
}

unset _tutorial_http_method _tutorial_http_url _tutorial_http_result _tutorial_http_label _tutorial_http_expected
