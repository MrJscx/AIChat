<?php
// 配置信息
$API_KEY = "请输入你的KEY"; 
$BASE_URL = "https://api.qnaigc.com";

// 1. 处理 Token 用量查询
if (isset($_GET['action']) && $_GET['action'] === 'usage') {
    header('Content-Type: application/json');
    date_default_timezone_set('PRC');
    $start = date('Y-m-d\T00:00:00+08:00');
    $end = date('Y-m-d\TH:i:s+08:00');
    $url = $BASE_URL . "/v2/stat/usage?granularity=day&start=" . urlencode($start) . "&end=" . urlencode($end);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $API_KEY"]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);
    $totalToken = 0;
    if (isset($data['status']) && $data['status'] === true) {
        foreach ($data['data'] as $model) {
            foreach ($model['items'] as $item) { $totalToken += $item['total']; }
        }
    }
    echo json_encode(['total' => $totalToken]);
    exit;
}

// 2. 处理 AI 对话请求 (流式转发)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 设置流式响应头
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // 禁用 Nginx 缓存

    // 获取前端发送的 JSON 数据
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);

    // 构建发送给上游 API 的数据
    $postData = [
        'model'    => $inputData['model'] ?? 'deepseek/deepseek-v3.2-exp-thinking',
        'messages' => $inputData['messages'] ?? [],
        'stream'   => true // 强制开启流模式
    ];

    // 如果前端传了温度且在合法范围内，则加入请求
    if (isset($inputData['temperature'])) {
        $postData['temperature'] = (float)$inputData['temperature'];
    }

    $ch = curl_init($BASE_URL . "/v1/chat/completions");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $API_KEY"
    ]);

    // 处理流式写回
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 格式化为标准的 Server-Sent Events (SSE) 格式
            if (strpos($line, 'data:') === 0) {
                echo $line . "\n\n";
            } else {
                echo "data: " . $line . "\n\n";
            }
        }

        // 刷新 PHP 缓冲区，确保实时推送到浏览器
        if (ob_get_level() > 0) ob_flush();
        flush();
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);
    exit;
}
