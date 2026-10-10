<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');
set_time_limit(310);


//初期設定の読み込み
require_once __DIR__ . '/config.php';


//ログ保存用関数
function saveLog($type, $message) {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/chat.log';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $date = date('Y-m-d H:i:s');
    $logText = "[" . $date . "] " . $type . ": " . $message . PHP_EOL;
    file_put_contents($logFile, $logText, FILE_APPEND | LOCK_EX);
}


//入力系の確認
$prompt = trim($_POST['prompt'] ?? '');
if ($prompt === '') {
    echo json_encode([
        'message' => 'どうしたの？何か聞きたいことがあるんじゃない？'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($prompt) > 500) {
    echo json_encode([
        'message' => 'うぅ…。情報量が多くて頭が熱いよ…。できれば500文字以内で送ってほしいな…。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
saveLog('あなた', $prompt);


/*ーーーーーーーーーーーーーーーーーーーーーー名前保存ーーーーーーーーーーーーーーーーーーーーーー*/
function extractUserNameByAI($text, $ollamaUrl, $modelName) {

    $extractPrompt = <<<EOT
次のユーザー発言から、ユーザー自身の名前または呼ばれたい名前を抽出してください。

条件:
- 名前が分かる場合は、名前だけを出力してください。
- 名前が分からない場合は、NONE とだけ出力してください。
- 「くん」「さん」「ちゃん」などの敬称は外してください。
- 説明文は書かないでください。
- 句読点や引用符も付けないでください。

ユーザー発言:
$text
EOT;

    $data = [
        'model' => $modelName,
        'prompt' => $extractPrompt,
        'stream' => false
    ];
    $ch = curl_init($ollamaUrl);
    if ($ch === false) {
        return '';
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return '';
    }
    curl_close($ch);
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return '';
    }
    $name = trim($result['response'] ?? '');


    //余計な記号を軽く削る
    $name = str_replace(["「", "」", '"', "'", "。", "、", "\n", "\r"], '', $name);
    $name = trim($name);

    //名前なし判定
    if ($name === '' || strtoupper($name) === 'NONE') {
        return '';
    }

    //長すぎるものは名前ではない可能性が高いので捨てる
    if (mb_strlen($name) > 20) {
        return '';
    }

    //敬称を外す
    $name = preg_replace('/(さん|くん|君|ちゃん)$/u', '', $name);
    return $name;
}
/*ーーーーーーーーーーーーーーーーーーーーーー名前保存ーーーーーーーーーーーーーーーーーーーーーー*/


if(!function_exists('curl_init')) {
    echo json_encode([
        'message' => 'cURLが有効になってないみたいだよ？サーバーの設定を確認してみてね。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


/*ーーーーーーーーーーーーーーーーーーーーーーーーーー名前リセット処理ーーーーーーーーーーーーーーーーーーーーーーーーーー*/
if (preg_match('/名前を忘れて|名前忘れて|呼び方をリセット|名前をリセット/u', $prompt)) {
    unset($_SESSION['user_name']);
    saveLog('名前リセット', 'ユーザー名を削除しました');
}

//名前に関係しそうな発言だけ、AIで抽出
else if (preg_match('/名前|呼んで|呼び方|私は|ワタシは|わたしは|僕は|ボクは|俺は|おれは|オレは|自分は/u', $prompt)) {
    $detectedName = extractUserNameByAI($prompt, $ollamaUrl, $modelName);
    if ($detectedName !== '') {
        $_SESSION['user_name'] = $detectedName;
        saveLog('名前登録', $detectedName);
    }
}
/*ーーーーーーーーーーーーーーーーーーーーーーーーーー名前リセット処理ーーーーーーーーーーーーーーーーーーーーーーーーーー*/


/*ーーーーーーーーーーーーーーーーーーーー会話履歴の準備ーーーーーーーーーーーーーーーーーーーーーー*/
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}
$historyText = '';
foreach ($_SESSION['chat_history'] as $chat) {
    $historyText .= "ユーザー: " . $chat['user'] . "\n";
    $historyText .= "エルフィ: " . $chat['ai'] . "\n";
}
$userInfo = '';
if (!empty($_SESSION['user_name'])) {
    $userInfo = "ユーザーの名前は「" . $_SESSION['user_name'] . "」です。返答では自然に「" . $_SESSION['user_name'] . "くん」と呼んでください。\n";
}else {
    $userInfo = "ユーザーの名前はまだ分かっていません。名前が分かるまでは「" . $defaultUserCallName . "」と呼んでください。\n";
}
$fullPrompt = $userInfo . $historyText . "ユーザー: " . $prompt . "\nエルフィ:";
/*ーーーーーーーーーーーーーーーーーーーー会話履歴の準備ーーーーーーーーーーーーーーーーーーーーーー*/


//Ollamaへ送信するデータ
$data = [
    'model' => $modelName,
    'system' => $systemPrompt,
    'prompt' => $fullPrompt,
    'stream' => false,
//返答の文字数制限（今は空返答になるためコメントアウトしてる）
/*    'options' => [
       'num_predict' => 160
    ]*/
];


//Ollamaへ接続
$ch = curl_init($ollamaUrl);
if ($ch === false) {
    echo json_encode([
        'message' => 'cURL初期化がうまくできなかったみたい…。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 300); //タイムアウトを設定
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
if ($response === false) {
    echo json_encode([
        'message' => 'エラーが発生しちゃったよ…。これなんだけど…。ー＞ ' . curl_error($ch)
    ], JSON_UNESCAPED_UNICODE);
    curl_close($ch);
    exit;
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);


//Ollamaの返答チェック
if($httpCode !== 200) {
    echo json_encode([
        'message' => 'これが発生しちゃったみたい…。なんでだろう…。ー＞ ' . $httpCode
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'message' => 'ごめんね…。JSONがどこにあるかわかるかな…？: ' . json_last_error_msg()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($result['error'])) {
    echo json_encode([
        'message' => 'こんなのが発生しちゃったみたい…。力になれなくてごめんね…。ー＞ ' . $result['error']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($result['response'])) {
    echo json_encode([
        'message' => 'ごめんね…。回答データを取得できなかったよ…。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


//フロントへの返却部分　➡　変更したらフロントも変更する必要ありなので変えないように
$answer = trim($result['response'] ?? '');
if ($answer === '') {
    $answer = 'ごめんね…。うまく返事を作れなかったみたい。もう一度送ってくれる？';
}


/*ーーーーーーーーーーーーーーーーーーーー会話履歴の保存ーーーーーーーーーーーーーーーーーーーーーー*/
$_SESSION['chat_history'][] = [
    'user' => $prompt,
    'ai' => $answer
];
if (count($_SESSION['chat_history']) > $maxHistory) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -$maxHistory);
}
/*ーーーーーーーーーーーーーーーーーーーー会話履歴の保存ーーーーーーーーーーーーーーーーーーーーーー*/


saveLog('エルフィ', $answer);
echo json_encode([
    'message' => $answer
], JSON_UNESCAPED_UNICODE);
